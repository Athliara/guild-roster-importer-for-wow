<?php

if (! defined('ABSPATH')) {
    exit;
}

class WoW_Guild_Roster_API
{
    private const ROSTER_CACHE_TTL = DAY_IN_SECONDS;
    private const AVATAR_CACHE_TTL = 7 * DAY_IN_SECONDS;
    private const MYTHIC_SCORE_CACHE_TTL = 36 * HOUR_IN_SECONDS;

    public function get_cache_key(array $options): string
    {
        $guild_name = trim((string) ($options['guild_name'] ?? ''));
        $server = trim((string) ($options['server'] ?? ''));
        $region = strtolower(trim((string) ($options['region'] ?? 'us')));
        $locale = $this->get_locale_for_region($region);
        $ranks_raw = trim((string) ($options['ranks_to_display'] ?? ''));
        $sort_by = sanitize_key((string) ($options['sort_by'] ?? 'rank'));
        $sort_order = sanitize_key((string) ($options['sort_order'] ?? 'asc'));
        $skip_profile_enrichment = ! empty($options['skip_profile_enrichment']);
        $skip_mythic_score_fetch = ! empty($options['skip_mythic_score_fetch']);

        return 'guilroim_roster_' . md5(wp_json_encode(array($guild_name, $server, $region, $locale, $ranks_raw, $sort_by, $sort_order, $skip_profile_enrichment, $skip_mythic_score_fetch, 'schema_16')));
    }

    public function refresh_roster(array $options)
    {
        delete_transient($this->get_cache_key($options));
        return $this->get_roster($options);
    }

    public function sync_roster_mythic_scores(array $options, array $roster_data = array())
    {
        if (empty($roster_data)) {
            $roster_options = $options;
            $roster_options['skip_profile_enrichment'] = true;
            $roster_options['skip_mythic_score_fetch'] = true;
            $roster_data = $this->get_roster($roster_options);
            if (is_wp_error($roster_data)) {
                return $roster_data;
            }
        }

        $members = is_array($roster_data['members'] ?? null) ? $roster_data['members'] : array();
        if (empty($members)) {
            return array(
                'max_level' => 0,
                'eligible_members' => 0,
                'updated_members' => 0,
                'non_max_members' => 0,
                'non_zero_scores' => 0,
            );
        }

        $region = WoW_Guild_Roster_Settings::normalize_region((string) ($roster_data['region'] ?? ($options['region'] ?? '')), '');
        $locale = $this->get_locale_for_region($region);
        $client_id = trim((string) ($options['blizzard_client_id'] ?? ''));
        $client_secret = trim((string) ($options['blizzard_client_secret'] ?? ''));
        if ($region === '' || $client_id === '' || $client_secret === '') {
            return new WP_Error('guilroim_mythic_sync_missing_credentials', __('Battle.net API credentials are missing for Mythic+ sync.', 'guild-roster-importer-for-wow'));
        }

        $max_level = 0;
        foreach ($members as $member) {
            $level = absint($member['level'] ?? 0);
            if ($level > $max_level) {
                $max_level = $level;
            }
        }

        if ($max_level <= 0) {
            return array(
                'max_level' => 0,
                'eligible_members' => 0,
                'updated_members' => 0,
                'non_max_members' => count($members),
                'non_zero_scores' => 0,
            );
        }

        $token = $this->get_access_token($client_id, $client_secret);
        if (is_wp_error($token)) {
            return $token;
        }

        $eligible_members = 0;
        $updated_members = 0;
        $non_max_members = 0;
        $non_zero_scores = 0;

        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }

            $character = sanitize_text_field((string) ($member['name'] ?? ''));
            $realm = sanitize_text_field((string) ($member['realm_slug'] ?? $member['realm'] ?? ''));
            if ($character === '' || $realm === '') {
                continue;
            }

            $level = absint($member['level'] ?? 0);
            if ($level !== $max_level) {
                $this->set_character_mythic_score_cache($character, $realm, $region, $locale, 0);
                $non_max_members++;
                continue;
            }

            $eligible_members++;
            $profile_url = $this->build_character_profile_url($character, $realm, $region);
            if ($profile_url === '') {
                $this->set_character_mythic_score_cache($character, $realm, $region, $locale, 0);
                $updated_members++;
                continue;
            }

            $score = $this->fetch_member_roster_mythic_score($profile_url, $locale, $region, $token);
            $this->set_character_mythic_score_cache($character, $realm, $region, $locale, $score);
            $updated_members++;
            if ($score > 0) {
                $non_zero_scores++;
            }
        }

        return array(
            'max_level' => $max_level,
            'eligible_members' => $eligible_members,
            'updated_members' => $updated_members,
            'non_max_members' => $non_max_members,
            'non_zero_scores' => $non_zero_scores,
        );
    }

    public function get_character_avatar_url(array $options, string $character, string $realm, string $region): string
    {
        $client_id = trim((string) ($options['blizzard_client_id'] ?? ''));
        $client_secret = trim((string) ($options['blizzard_client_secret'] ?? ''));
        $region = WoW_Guild_Roster_Settings::normalize_region($region, '');
        $locale = $this->get_locale_for_region($region);

        if ($client_id === '' || $client_secret === '' || $character === '' || $realm === '' || $region === '') {
            return '';
        }

        $realm_slug = sanitize_title($realm);
        $character_slug = $this->normalize_character_slug($character);
        $cache_key = 'guilroim_avatar_lookup_' . md5($region . '|' . $realm_slug . '|' . $character_slug . '|' . $locale);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached === 'none') {
            return '';
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->get_access_token($client_id, $client_secret);
        if (is_wp_error($token)) {
            return '';
        }

        $url = sprintf(
            'https://%s.api.blizzard.com/profile/wow/character/%s/%s/character-media',
            rawurlencode($region),
            rawurlencode($realm_slug),
            rawurlencode($character_slug)
        );
        $response = wp_remote_get(
            add_query_arg(array('namespace' => 'profile-' . $region, 'locale' => $locale), $url),
            array(
                'timeout' => 8,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($cache_key, 'none', 30 * MINUTE_IN_SECONDS);
            return '';
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data) || empty($data['assets']) || ! is_array($data['assets'])) {
            set_transient($cache_key, 'none', 30 * MINUTE_IN_SECONDS);
            return '';
        }

        $avatar_url = '';
        foreach ($data['assets'] as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $key = (string) ($asset['key'] ?? '');
            $value = (string) ($asset['value'] ?? '');
            if ($value === '') {
                continue;
            }
            if ($key === 'avatar') {
                $avatar_url = $this->localize_remote_media_url($value, 'avatars');
                break;
            }
            if ($avatar_url === '' && $key === 'inset') {
                $avatar_url = $this->localize_remote_media_url($value, 'avatars');
            }
        }

        if ($avatar_url === '') {
            set_transient($cache_key, 'none', 30 * MINUTE_IN_SECONDS);
            return '';
        }

        set_transient($cache_key, $avatar_url, self::AVATAR_CACHE_TTL);
        return $avatar_url;
    }

    public function localize_remote_media_url(string $url, string $context = 'media'): string
    {
        $url = esc_url_raw(set_url_scheme(trim($url), 'https'));
        if ($url === '') {
            return '';
        }

        $parsed = wp_parse_url($url);
        if (! is_array($parsed) || strtolower((string) ($parsed['scheme'] ?? '')) !== 'https' || empty($parsed['host'])) {
            return '';
        }

        $context = sanitize_key($context);
        if ($context === '') {
            $context = 'media';
        }

        $cache_key = 'guilroim_media_local_' . md5($url . '|' . $context);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached === 'none') {
            return '';
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $path = (string) ($parsed['path'] ?? '');
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'webp', 'gif');
        if (! in_array($extension, $allowed_extensions, true)) {
            $extension = '';
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'redirection' => 3,
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($extension === '') {
            if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) {
                $extension = 'jpg';
            } elseif (str_contains($content_type, 'png')) {
                $extension = 'png';
            } elseif (str_contains($content_type, 'webp')) {
                $extension = 'webp';
            } elseif (str_contains($content_type, 'gif')) {
                $extension = 'gif';
            }
        }

        if ($extension === '' || ! in_array($extension, $allowed_extensions, true)) {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '' || strlen($body) > 5 * MB_IN_BYTES) {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $filename = sanitize_file_name('guilroim-' . $context . '-' . md5($url) . '.' . $extension);
        $upload = wp_upload_bits($filename, null, $body);
        if (! empty($upload['error']) || empty($upload['url'])) {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $local_url = esc_url_raw((string) $upload['url']);
        set_transient($cache_key, $local_url, 30 * DAY_IN_SECONDS);

        return $local_url;
    }

    public function get_roster(array $options)
    {
        $guild_name = trim((string) ($options['guild_name'] ?? ''));
        $server = trim((string) ($options['server'] ?? ''));
        $region = WoW_Guild_Roster_Settings::normalize_region((string) ($options['region'] ?? 'us'));
        $locale = $this->get_locale_for_region($region);
        $client_id = trim((string) ($options['blizzard_client_id'] ?? ''));
        $client_secret = trim((string) ($options['blizzard_client_secret'] ?? ''));
        $ranks_raw = trim((string) ($options['ranks_to_display'] ?? ''));
        $sort_by = sanitize_key((string) ($options['sort_by'] ?? 'rank'));
        $sort_order = sanitize_key((string) ($options['sort_order'] ?? 'asc'));
        $skip_profile_enrichment = ! empty($options['skip_profile_enrichment']);
        $skip_mythic_score_fetch = ! empty($options['skip_mythic_score_fetch']);

        $allowed_sort_by = array('name', 'rank', 'level', 'class', 'race', 'role', 'mythic_score');
        if (! in_array($sort_by, $allowed_sort_by, true)) {
            $sort_by = 'rank';
        }

        $allowed_sort_order = array('asc', 'desc');
        if (! in_array($sort_order, $allowed_sort_order, true)) {
            $sort_order = 'asc';
        }

        if ($guild_name === '' || $server === '' || $region === '') {
            return new WP_Error('guilroim_missing_guild_data', __('Guild name, server, and region are required.', 'guild-roster-importer-for-wow'));
        }

        if ($client_id === '' || $client_secret === '') {
            return new WP_Error('guilroim_missing_credentials', __('Battle.net Client Key and Secret are required.', 'guild-roster-importer-for-wow'));
        }

        $cache_key = $this->get_cache_key($options);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $token = $this->get_access_token($client_id, $client_secret);
        if (is_wp_error($token)) {
            return $token;
        }

        $realm_slug = sanitize_title($server);
        $guild_slug = sanitize_title($guild_name);
        $url = sprintf(
            'https://%s.api.blizzard.com/data/wow/guild/%s/%s/roster',
            rawurlencode($region),
            rawurlencode($realm_slug),
            rawurlencode($guild_slug)
        );
        $response = $this->fetch_guild_roster_response($url, $region, $locale, $token);

        if (! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 404) {
            $resolved_realm_slug = $this->resolve_realm_slug($server, $region, $locale, $token);
            if ($resolved_realm_slug !== '' && $resolved_realm_slug !== $realm_slug) {
                $realm_slug = $resolved_realm_slug;
                $url = sprintf(
                    'https://%s.api.blizzard.com/data/wow/guild/%s/%s/roster',
                    rawurlencode($region),
                    rawurlencode($realm_slug),
                    rawurlencode($guild_slug)
                );
                $response = $this->fetch_guild_roster_response($url, $region, $locale, $token);
            }
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status !== 200 || ! is_array($data)) {
            $api_detail = '';
            if (is_array($data)) {
                $api_detail = (string) ($data['detail'] ?? $data['reason'] ?? $data['message'] ?? '');
            }

            if ($status === 404) {
                return new WP_Error('guilroim_roster_request_failed', __('Roster request failed (404). Guild or realm was not found for the selected region.', 'guild-roster-importer-for-wow'));
            }

            if ($status === 403) {
                return new WP_Error('guilroim_roster_request_failed', __('Roster request failed (403). Battle.net credentials are valid but not authorized for this request.', 'guild-roster-importer-for-wow'));
            }

            /* translators: 1: HTTP status code, 2: API detail message. */
            $message_template = __('Roster request failed (HTTP %1$d). %2$s', 'guild-roster-importer-for-wow');
            $message = sprintf(
                $message_template,
                (int) $status,
                $api_detail !== '' ? $api_detail : __('Check guild details, region, and API credentials.', 'guild-roster-importer-for-wow')
            );

            return new WP_Error('guilroim_roster_request_failed', $message);
        }

        $members = $data['members'] ?? array();
        if (! is_array($members)) {
            return new WP_Error('guilroim_invalid_roster_data', __('Unexpected roster data format received from API.', 'guild-roster-importer-for-wow'));
        }

        $allowed_ranks = $this->parse_ranks($ranks_raw);
        $normalized_members = array();

        foreach ($members as $member) {
            $rank = isset($member['rank']) ? absint($member['rank']) : null;
            if (! empty($allowed_ranks) && ($rank === null || ! in_array($rank, $allowed_ranks, true))) {
                continue;
            }

            $character = is_array($member['character'] ?? null) ? $member['character'] : array();
            $class = $this->extract_class_from_character($character);
            $race = $this->extract_race_from_character($character);
            $role = $this->extract_role_from_character($character, $class['id']);
            $gender = $this->extract_gender_from_character($character);
            $avatar_url = $this->extract_avatar_from_character($character);

            if ($gender === '' || (! $skip_profile_enrichment && ($class['id'] === 0 || $class['name'] === '' || $race['id'] === 0 || $race['name'] === '' || $role === ''))) {
                $profile_details = $this->fetch_member_profile_details($member, $locale, $region, $token);
                if (! empty($profile_details['class_id']) && $class['id'] === 0) {
                    $class['id'] = absint($profile_details['class_id']);
                }
                if (! empty($profile_details['class_name']) && $class['name'] === '') {
                    $class['name'] = (string) $profile_details['class_name'];
                }
                if (! empty($profile_details['race_id']) && $race['id'] === 0) {
                    $race['id'] = absint($profile_details['race_id']);
                }
                if (! empty($profile_details['race_name']) && $race['name'] === '') {
                    $race['name'] = (string) $profile_details['race_name'];
                }
                if (! empty($profile_details['role']) && $role === '') {
                    $role = (string) $profile_details['role'];
                }
                if (! empty($profile_details['gender']) && $gender === '') {
                    $gender = (string) $profile_details['gender'];
                }
            }

            if ($class['name'] === '') {
                $class['name'] = $this->get_class_name_from_id($class['id']);
            }
            if ($race['name'] === '') {
                $race['name'] = $this->get_race_name_from_id($race['id']);
            }
            if ($role === '') {
                $role = $this->get_default_role_by_class($class['id']);
            }

            $mythic_score = $skip_mythic_score_fetch ? 0 : $this->fetch_member_mythic_score($member, $locale, $region, $token);

            $normalized_members[] = array(
                'name' => (string) ($character['name'] ?? ''),
                'realm' => $this->extract_character_realm_name($member, $character),
                'realm_slug' => $this->extract_character_realm_slug($member, $character),
                'level' => isset($character['level']) ? absint($character['level']) : 0,
                'class' => (string) $class['name'],
                'class_id' => (int) $class['id'],
                'race' => (string) $race['name'],
                'race_id' => (int) $race['id'],
                'gender' => (string) $gender,
                'avatar_url' => (string) $avatar_url,
                'role' => (string) $role,
                'mythic_score' => $mythic_score,
                'rank' => $rank ?? 0,
            );
        }

        usort($normalized_members, $this->build_sort_callback($sort_by, $sort_order));

        $guild_banner_url = '';
        try {
            $guild_banner_url = $this->fetch_guild_banner_url($region, $locale, $realm_slug, $guild_slug, $token);
        } catch (Throwable $exception) {
            $guild_banner_url = '';
        }

        $result = array(
            'guild_name' => $guild_name,
            'server' => $server,
            'region' => strtoupper($region),
            'members' => $normalized_members,
            'fetched_at' => current_time('timestamp'),
            'sort_by' => $sort_by,
            'sort_order' => $sort_order,
            'guild_banner_url' => $guild_banner_url,
        );

        set_transient($cache_key, $result, self::ROSTER_CACHE_TTL);

        return $result;
    }

    private function fetch_guild_roster_response(string $url, string $region, string $locale, string $token)
    {
        return wp_remote_get(
            add_query_arg(
                array(
                    'namespace' => 'profile-' . $region,
                    'locale' => $locale,
                ),
                $url
            ),
            array(
                'timeout' => 20,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );
    }

    private function resolve_realm_slug(string $realm_input, string $region, string $locale, string $token): string
    {
        $realm_input = trim($realm_input);
        if ($realm_input === '') {
            return '';
        }

        $cache_key = 'guilroim_realm_slug_' . md5($region . '|' . $locale . '|' . $realm_input);
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached === 'none' ? '' : $cached;
        }

        $index_url = sprintf('https://%s.api.blizzard.com/data/wow/realm/index', rawurlencode($region));
        $index = $this->api_get_json($index_url, 'dynamic-' . $region, $locale, $token);
        if (is_wp_error($index) || ! is_array($index)) {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $target_slug = sanitize_title($realm_input);
        $target_name = $this->normalize_realm_match_value($realm_input);
        $realms = $index['realms'] ?? array();

        if (! is_array($realms)) {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        foreach ($realms as $realm) {
            if (! is_array($realm)) {
                continue;
            }

            $realm_slug = sanitize_title((string) ($realm['slug'] ?? ''));
            $realm_name = $this->normalize_realm_match_value((string) ($realm['name'] ?? ''));

            if ($realm_slug !== '' && $realm_slug === $target_slug) {
                set_transient($cache_key, $realm_slug, 7 * DAY_IN_SECONDS);
                return $realm_slug;
            }

            if ($realm_name !== '' && $realm_name === $target_name && $realm_slug !== '') {
                set_transient($cache_key, $realm_slug, 7 * DAY_IN_SECONDS);
                return $realm_slug;
            }
        }

        set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
        return '';
    }

    private function normalize_realm_match_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value;
    }

    private function extract_character_realm_slug(array $member, array $character): string
    {
        $candidates = array(
            $character['realm']['slug'] ?? '',
            $character['realm']['id'] ?? '',
        );

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return sanitize_title($candidate);
            }
        }

        $href = (string) ($member['character']['key']['href'] ?? $member['character']['href'] ?? '');
        if ($href !== '' && preg_match('#/character/([^/]+)/[^/?]+#i', $href, $matches)) {
            return sanitize_title(rawurldecode((string) $matches[1]));
        }

        $realm_name = (string) ($character['realm']['name'] ?? '');

        return $realm_name !== '' ? sanitize_title($realm_name) : '';
    }

    private function extract_character_realm_name(array $member, array $character): string
    {
        $realm_name = trim((string) ($character['realm']['name'] ?? ''));
        if ($realm_name !== '') {
            return $realm_name;
        }

        $realm_slug = $this->extract_character_realm_slug($member, $character);
        if ($realm_slug === '') {
            return '';
        }

        $parts = array_filter(explode('-', $realm_slug), static function ($part): bool {
            return $part !== '';
        });
        $parts = array_map(static function ($part): string {
            return ucfirst(strtolower($part));
        }, $parts);

        return implode(' ', $parts);
    }

    private function normalize_character_slug(string $character): string
    {
        $character = trim($character);
        $character = preg_replace('/\s+/', '', $character);
        if (! is_string($character) || $character === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($character, 'UTF-8');
        }

        return strtolower($character);
    }

    private function build_character_profile_url(string $character, string $realm, string $region): string
    {
        $realm_slug = sanitize_title($realm);
        $character_slug = $this->normalize_character_slug($character);
        if ($realm_slug === '' || $character_slug === '' || $region === '') {
            return '';
        }

        return sprintf(
            'https://%s.api.blizzard.com/profile/wow/character/%s/%s',
            rawurlencode($region),
            rawurlencode($realm_slug),
            rawurlencode($character_slug)
        );
    }

    private function build_member_mythic_score_cache_key(string $character, string $realm, string $region, string $locale): string
    {
        return $this->build_member_mythic_score_cache_key_for_schema($character, $realm, $region, $locale, 'schema_10');
    }

    private function build_member_mythic_score_cache_key_for_schema(string $character, string $realm, string $region, string $locale, string $schema): string
    {
        $profile_url = $this->build_character_profile_url($character, $realm, $region);
        if ($profile_url === '') {
            return '';
        }

        return 'guilroim_member_mplus_' . md5($profile_url . '|' . $locale . '|' . $region . '|' . $schema);
    }

    private function get_locale_for_region(string $region): string
    {
        return WoW_Guild_Roster_Settings::get_locale_for_region($region);
    }

    public function get_access_token(string $client_id, string $client_secret)
    {
        $token_cache_key = 'guilroim_access_token_' . md5($client_id . '|' . $client_secret);
        $cached_token = get_transient($token_cache_key);
        if (is_string($cached_token) && $cached_token !== '') {
            return $cached_token;
        }

        $token_response = wp_remote_post(
            'https://oauth.battle.net/token',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body' => array('grant_type' => 'client_credentials'),
            )
        );

        if (is_wp_error($token_response)) {
            return $token_response;
        }

        $status = wp_remote_retrieve_response_code($token_response);
        $body = wp_remote_retrieve_body($token_response);
        $data = json_decode($body, true);

        if ($status !== 200 || ! is_array($data) || empty($data['access_token'])) {
            $api_detail = '';
            if (is_array($data)) {
                $api_detail = (string) ($data['error_description'] ?? $data['detail'] ?? $data['error'] ?? '');
            }

            /* translators: 1: HTTP status code, 2: API detail message. */
            $message_template = __('Could not get Blizzard access token (HTTP %1$d). %2$s', 'guild-roster-importer-for-wow');
            $message = sprintf(
                $message_template,
                (int) $status,
                $api_detail !== '' ? $api_detail : __('Check Battle.net Client Key/Secret.', 'guild-roster-importer-for-wow')
            );

            return new WP_Error('guilroim_token_failed', $message);
        }

        $token = (string) $data['access_token'];
        $expires_in = isset($data['expires_in']) ? max(60, (int) $data['expires_in']) : 3600;
        set_transient($token_cache_key, $token, max(60, $expires_in - 60));
        return $token;
    }

    public function api_get_json(string $url, string $namespace, string $locale, string $token)
    {
        $response = wp_remote_get(
            add_query_arg(array('namespace' => $namespace, 'locale' => $locale), $url),
            array(
                'timeout' => 20,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status !== 200 || ! is_array($json)) {
            $api_detail = '';
            if (is_array($json)) {
                $api_detail = (string) ($json['detail'] ?? $json['reason'] ?? $json['message'] ?? '');
            }

            /* translators: 1: HTTP status code, 2: API detail message. */
            $message_template = __('Blizzard API request failed (HTTP %1$d). %2$s', 'guild-roster-importer-for-wow');
            $message = sprintf(
                $message_template,
                (int) $status,
                $api_detail !== '' ? $api_detail : __('The requested Blizzard API payload could not be loaded.', 'guild-roster-importer-for-wow')
            );

            return new WP_Error('guilroim_api_request_failed', $message);
        }

        return $json;
    }

    public function get_character_mythic_score(array $options, string $character, string $realm, string $region): int
    {
        $client_id = trim((string) ($options['blizzard_client_id'] ?? ''));
        $client_secret = trim((string) ($options['blizzard_client_secret'] ?? ''));
        $region = WoW_Guild_Roster_Settings::normalize_region($region, '');
        $locale = $this->get_locale_for_region($region);

        if ($client_id === '' || $client_secret === '' || $character === '' || $realm === '' || $region === '') {
            return 0;
        }

        $token = $this->get_access_token($client_id, $client_secret);
        if (is_wp_error($token)) {
            return 0;
        }

        $realm_slug = sanitize_title($realm);
        $character_slug = $this->normalize_character_slug($character);
        if ($realm_slug === '' || $character_slug === '') {
            return 0;
        }

        $profile_url = sprintf(
            'https://%s.api.blizzard.com/profile/wow/character/%s/%s',
            rawurlencode($region),
            rawurlencode($realm_slug),
            rawurlencode($character_slug)
        );

        return $this->fetch_member_mythic_score_from_profile_url($profile_url, $locale, $region, $token);
    }

    public function get_cached_character_mythic_score(string $character, string $realm, string $region): ?int
    {
        $region = WoW_Guild_Roster_Settings::normalize_region($region, '');
        if ($character === '' || $realm === '' || $region === '') {
            return null;
        }

        $locale = $this->get_locale_for_region($region);
        $cache_key = $this->build_member_mythic_score_cache_key($character, $realm, $region, $locale);
        if ($cache_key === '') {
            return null;
        }

        $cached = get_transient($cache_key);
        if (is_numeric($cached)) {
            return (int) $cached;
        }

        foreach (array('schema_9', 'schema_8') as $legacy_schema) {
            $legacy_cache_key = $this->build_member_mythic_score_cache_key_for_schema($character, $realm, $region, $locale, $legacy_schema);
            if ($legacy_cache_key === '') {
                continue;
            }

            $legacy_cached = get_transient($legacy_cache_key);
            if (is_numeric($legacy_cached) && (int) $legacy_cached > 0) {
                return (int) $legacy_cached;
            }
        }

        return null;
    }

    private function set_character_mythic_score_cache(string $character, string $realm, string $region, string $locale, int $score): void
    {
        $cache_key = $this->build_member_mythic_score_cache_key($character, $realm, $region, $locale);
        if ($cache_key === '') {
            return;
        }

        set_transient($cache_key, max(0, $score), self::MYTHIC_SCORE_CACHE_TTL);
    }

    public function get_character_mythic_scores_bulk(array $options, array $characters, string $region): array
    {
        $client_id = trim((string) ($options['blizzard_client_id'] ?? ''));
        $client_secret = trim((string) ($options['blizzard_client_secret'] ?? ''));
        $region = WoW_Guild_Roster_Settings::normalize_region($region, '');
        $locale = $this->get_locale_for_region($region);
        $scores = array();

        if ($client_id === '' || $client_secret === '' || $region === '') {
            return $scores;
        }

        $token = null;

        foreach ($characters as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $character = sanitize_text_field((string) ($entry['character'] ?? ''));
            $realm = sanitize_text_field((string) ($entry['realm'] ?? ''));
            if ($character === '' || $realm === '') {
                continue;
            }

            $cache_key = strtolower($region . '|' . sanitize_title($realm) . '|' . $this->normalize_character_slug($character));
            $cached_score = $this->get_cached_character_mythic_score($character, $realm, $region);
            if ($cached_score !== null) {
                $scores[$cache_key] = $cached_score;
                continue;
            }

            if ($token === null) {
                $token = $this->get_access_token($client_id, $client_secret);
                if (is_wp_error($token)) {
                    return $scores;
                }
            }

            $profile_url = $this->build_character_profile_url($character, $realm, $region);
            if ($profile_url === '') {
                $scores[$cache_key] = 0;
                continue;
            }

            $scores[$cache_key] = $this->fetch_member_mythic_score_from_profile_url($profile_url, $locale, $region, $token);
        }

        return $scores;
    }

    private function extract_class_from_character(array $character): array
    {
        $id = 0;
        $name = '';

        if (isset($character['playable_class']) && is_array($character['playable_class'])) {
            $id = isset($character['playable_class']['id']) ? absint($character['playable_class']['id']) : 0;
            $name = $this->resolve_name_field($character['playable_class']['name'] ?? '', '');
        } elseif (isset($character['class']) && is_array($character['class'])) {
            $id = isset($character['class']['id']) ? absint($character['class']['id']) : 0;
            $name = $this->resolve_name_field($character['class']['name'] ?? '', '');
        }

        return array('id' => $id, 'name' => $name);
    }

    private function extract_race_from_character(array $character): array
    {
        $id = 0;
        $name = '';

        if (isset($character['playable_race']) && is_array($character['playable_race'])) {
            $id = isset($character['playable_race']['id']) ? absint($character['playable_race']['id']) : 0;
            $name = $this->resolve_name_field($character['playable_race']['name'] ?? '', '');
        } elseif (isset($character['race']) && is_array($character['race'])) {
            $id = isset($character['race']['id']) ? absint($character['race']['id']) : 0;
            $name = $this->resolve_name_field($character['race']['name'] ?? '', '');
        }

        return array('id' => $id, 'name' => $name);
    }

    private function extract_role_from_character(array $character, int $class_id): string
    {
        $spec_id = absint($character['active_spec']['id'] ?? 0);
        $spec_name = $this->resolve_name_field($character['active_spec']['name'] ?? '', '');
        $spec_role = $this->get_role_by_spec($spec_id, $spec_name);
        if ($spec_role !== '') {
            return $spec_role;
        }

        if (isset($character['active_spec']['role']['type'])) {
            return $this->normalize_role((string) $character['active_spec']['role']['type']);
        }

        if (isset($character['active_spec']['role']['name'])) {
            return $this->normalize_role((string) $character['active_spec']['role']['name']);
        }

        return '';
    }

    private function extract_gender_from_character(array $character): string
    {
        return $this->extract_gender_value($character['gender'] ?? '');
    }

    private function extract_avatar_from_character(array $character): string
    {
        $candidates = array(
            $character['avatar_url'] ?? '',
            $character['avatar'] ?? '',
            $character['render_url'] ?? '',
            $character['character_media']['avatar_url'] ?? '',
            $character['character_media']['avatar'] ?? '',
        );

        if (! empty($character['media']['assets']) && is_array($character['media']['assets'])) {
            foreach ($character['media']['assets'] as $asset) {
                if (! is_array($asset)) {
                    continue;
                }
                $key = (string) ($asset['key'] ?? '');
                if ($key === 'avatar' || $key === 'inset') {
                    $candidates[] = (string) ($asset['value'] ?? '');
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $localized = $this->localize_remote_media_url($candidate, 'avatars');
            if ($localized !== '') {
                return $localized;
            }
        }

        return '';
    }

    private function fetch_member_profile_details(array $member, string $locale, string $region, string $token): array
    {
        $href = (string) ($member['character']['key']['href'] ?? $member['character']['href'] ?? '');
        if ($href === '') {
            return array();
        }

        $transient_key = 'guilroim_member_profile_' . md5($href . '|' . $locale . '|' . $region . '|schema_5');
        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            return $cached;
        }

        $query_args = array('locale' => $locale);
        if (strpos($href, 'namespace=') === false) {
            $query_args['namespace'] = 'profile-' . $region;
        }

        $response = wp_remote_get(
            add_query_arg($query_args, $href),
            array(
                'timeout' => 8,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($transient_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data)) {
            set_transient($transient_key, array(), 30 * MINUTE_IN_SECONDS);
            return array();
        }

        $class_id = absint($data['character_class']['id'] ?? $data['playable_class']['id'] ?? $data['class']['id'] ?? 0);
        $race_id = absint($data['race']['id'] ?? $data['playable_race']['id'] ?? 0);
        $spec_id = absint($data['active_spec']['id'] ?? 0);
        $spec_name = $this->resolve_name_field($data['active_spec']['name'] ?? '', '');

        $role = $this->get_role_by_spec($spec_id, $spec_name);
        if ($role === '') {
            $role = $this->fetch_specialization_role($spec_id, $region, $locale, $token);
        }
        if ($role === '' && ! empty($data['active_spec']['role']['type'])) {
            $role = $this->normalize_role((string) $data['active_spec']['role']['type']);
        }
        if ($role === '' && ! empty($data['active_spec']['role']['name'])) {
            $role = $this->normalize_role((string) $data['active_spec']['role']['name']);
        }

        $result = array(
            'class_id' => $class_id,
            'class_name' => $this->resolve_name_field($data['character_class']['name'] ?? $data['playable_class']['name'] ?? '', $this->get_class_name_from_id($class_id)),
            'race_id' => $race_id,
            'race_name' => $this->normalize_race_name($this->resolve_name_field($data['race']['name'] ?? $data['playable_race']['name'] ?? '', $this->get_race_name_from_id($race_id))),
            'gender' => $this->extract_gender_value($data['gender'] ?? ''),
            'avatar_url' => '',
            'role' => $role,
        );

        set_transient($transient_key, $result, 12 * HOUR_IN_SECONDS);
        return $result;
    }

    private function fetch_specialization_role(int $spec_id, string $region, string $locale, string $token): string
    {
        $cache_key = 'guilroim_spec_role_' . $region . '_' . $spec_id;
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached === 'none' ? '' : $cached;
        }

        $url = sprintf('https://%s.api.blizzard.com/data/wow/playable-specialization/%d', rawurlencode($region), $spec_id);
        $response = wp_remote_get(
            add_query_arg(array('namespace' => 'static-' . $region, 'locale' => $locale), $url),
            array(
                'timeout' => 8,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($cache_key, 'none', 12 * HOUR_IN_SECONDS);
            return '';
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data)) {
            set_transient($cache_key, 'none', 12 * HOUR_IN_SECONDS);
            return '';
        }

        $role = $this->normalize_role((string) ($data['role']['type'] ?? $data['role']['name'] ?? ''));
        if ($role !== '') {
            set_transient($cache_key, $role, 7 * DAY_IN_SECONDS);
            return $role;
        }

        set_transient($cache_key, 'none', 12 * HOUR_IN_SECONDS);

        return '';
    }

    private function fetch_member_mythic_score(array $member, string $locale, string $region, string $token): int
    {
        $href = (string) ($member['character']['key']['href'] ?? $member['character']['href'] ?? '');
        if ($href === '') {
            return 0;
        }

        $profile_url = preg_replace('/\?.*$/', '', $href);
        if (! is_string($profile_url) || $profile_url === '') {
            return 0;
        }

        return $this->fetch_member_mythic_score_from_profile_url($profile_url, $locale, $region, $token);
    }

    private function fetch_member_mythic_score_from_profile_url(string $profile_url, string $locale, string $region, string $token): int
    {
        $cache_key = 'guilroim_member_mplus_' . md5($profile_url . '|' . $locale . '|' . $region . '|schema_10');
        $cached = get_transient($cache_key);
        if (is_numeric($cached)) {
            return (int) $cached;
        }

        foreach (array('schema_9', 'schema_8') as $legacy_schema) {
            $legacy_cache_key = 'guilroim_member_mplus_' . md5($profile_url . '|' . $locale . '|' . $region . '|' . $legacy_schema);
            $legacy_cached = get_transient($legacy_cache_key);
            if (is_numeric($legacy_cached) && (int) $legacy_cached > 0) {
                return (int) $legacy_cached;
            }
        }

        $normalized_score = $this->fetch_member_roster_mythic_score($profile_url, $locale, $region, $token);
        set_transient($cache_key, $normalized_score, self::MYTHIC_SCORE_CACHE_TTL);

        return $normalized_score;
    }

    private function fetch_member_roster_mythic_score(string $profile_url, string $locale, string $region, string $token): int
    {
        $current_season_id = $this->fetch_current_mythic_keystone_season_id($locale, $token, $region);
        if ($current_season_id > 0) {
            $season_score = $this->fetch_member_mythic_season_score($profile_url, $locale, $region, $token, $current_season_id);
            if ($season_score > 0) {
                return $season_score;
            }
        }

        foreach ($this->fetch_recent_mythic_keystone_season_ids($locale, $token, $region) as $season_id) {
            $season_id = absint($season_id);
            if ($season_id <= 0 || $season_id === $current_season_id) {
                continue;
            }

            $season_score = $this->fetch_member_mythic_season_score($profile_url, $locale, $region, $token, $season_id);
            if ($season_score > 0) {
                return $season_score;
            }
        }

        $summary_response = $this->fetch_member_summary_mythic_profile($profile_url, $locale, $region, $token);
        $summary_score = (int) ($summary_response['score'] ?? 0);
        if ($summary_score <= 0) {
            return 0;
        }

        return $summary_score;
    }

    private function fetch_member_mythic_season_score(string $profile_url, string $locale, string $region, string $token, int $season_id): int
    {
        if ($season_id <= 0) {
            return 0;
        }

        $season_url = $profile_url . '/mythic-keystone-profile/season/' . (int) $season_id;
        $response = wp_remote_get(
            add_query_arg(
                array(
                    'namespace' => 'profile-' . $region,
                    'locale' => $locale,
                ),
                $season_url
            ),
            array(
                'timeout' => 8,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return 0;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data)) {
            return 0;
        }

        return (int) round((float) ($data['current_mythic_rating']['rating'] ?? $data['current_mythic_rating']['score'] ?? 0));
    }

    private function fetch_member_summary_mythic_profile(string $profile_url, string $locale, string $region, string $token): array
    {
        $response = wp_remote_get(
            add_query_arg(
                array(
                    'namespace' => 'profile-' . $region,
                    'locale' => $locale,
                ),
                $profile_url . '/mythic-keystone-profile'
            ),
            array(
                'timeout' => 8,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return array(
                'score' => 0,
                'has_current_season_activity' => false,
            );
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data)) {
            return array(
                'score' => 0,
                'has_current_season_activity' => false,
            );
        }

        return array(
            'score' => (int) round((float) ($data['current_mythic_rating']['rating'] ?? $data['current_mythic_rating']['score'] ?? 0)),
            'has_current_season_activity' => $this->summary_has_current_season_mythic_activity($data),
        );
    }

    private function summary_has_current_season_mythic_activity(array $data): bool
    {
        $candidates = array(
            $data['season_best_runs'] ?? array(),
            $data['current_season_best_runs'] ?? array(),
            $data['best_runs_this_season'] ?? array(),
            $data['current_period']['best_runs'] ?? array(),
        );

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ! empty($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function get_expected_current_mythic_keystone_season_title(string $region): string
    {
        $region = strtolower(trim($region));
        $today = gmdate('Y-m-d');
        $current_title = '';
        $current_start = '';
        $manifests = array(
            array(
                'title' => 'The War Within Season 3',
                'starts' => array(
                    'default' => '2025-09-16',
                    'eu' => '2025-09-17',
                    'kr' => '2025-09-18',
                    'tw' => '2025-09-18',
                ),
            ),
            array(
                'title' => 'Midnight Season 1',
                'starts' => array(
                    'default' => '2026-03-24',
                    'eu' => '2026-03-25',
                    'kr' => '2026-03-26',
                    'tw' => '2026-03-26',
                ),
            ),
        );

        foreach ($manifests as $manifest) {
            $starts = is_array($manifest['starts'] ?? null) ? $manifest['starts'] : array();
            $start_date = (string) ($starts[$region] ?? $starts['default'] ?? '');
            if ($start_date === '' || $today < $start_date) {
                continue;
            }

            if ($current_start === '' || $start_date > $current_start) {
                $current_start = $start_date;
                $current_title = strtolower((string) ($manifest['title'] ?? ''));
            }
        }

        return $current_title;
    }

    private function fetch_current_mythic_keystone_season_id(string $locale, string $token, string $region): int
    {
        $expected_title = $this->get_expected_current_mythic_keystone_season_title($region);
        $cache_key = 'guilroim_current_mplus_season_id_' . md5($region . '|' . $locale . '|' . $expected_title . '|schema_1');
        $cached = get_transient($cache_key);
        if (is_numeric($cached) && (int) $cached > 0) {
            return (int) $cached;
        }

        $season_ids = $this->fetch_recent_mythic_keystone_season_ids($locale, $token, $region);
        if (empty($season_ids)) {
            return 0;
        }

        if ($expected_title !== '') {
            foreach ($season_ids as $season_id) {
                $season_url = sprintf(
                    'https://%s.api.blizzard.com/data/wow/mythic-keystone/season/%d',
                    rawurlencode($region),
                    (int) $season_id
                );
                $season = $this->api_get_json($season_url, 'dynamic-' . $region, $locale, $token);
                if (is_wp_error($season) || ! is_array($season)) {
                    continue;
                }

                $season_name = strtolower(trim((string) ($season['name'] ?? '')));
                if ($season_name !== '' && $season_name === $expected_title) {
                    set_transient($cache_key, (int) $season_id, 12 * HOUR_IN_SECONDS);
                    return (int) $season_id;
                }
            }
        }

        $fallback_id = (int) $season_ids[0];
        set_transient($cache_key, $fallback_id, 12 * HOUR_IN_SECONDS);
        return $fallback_id;
    }

    private function fetch_recent_mythic_keystone_season_ids(string $locale, string $token, string $region): array
    {
        $cache_key = 'guilroim_mplus_season_ids_' . md5($region . '|' . $locale . '|schema_2');
        $cached = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $index_url = sprintf('https://%s.api.blizzard.com/data/wow/mythic-keystone/season/index', rawurlencode($region));
        $index = $this->api_get_json($index_url, 'dynamic-' . $region, $locale, $token);
        if (is_wp_error($index) || ! is_array($index)) {
            return array();
        }

        $ids = array();
        $seasons = $index['seasons'] ?? array();
        if (! is_array($seasons)) {
            return array();
        }

        foreach ($seasons as $season) {
            if (! is_array($season)) {
                continue;
            }

            $season_id = absint($season['id'] ?? 0);
            if ($season_id > 0) {
                $ids[] = $season_id;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        rsort($ids, SORT_NUMERIC);
        $ids = array_slice($ids, 0, 2);

        if (! empty($ids)) {
            set_transient($cache_key, $ids, 12 * HOUR_IN_SECONDS);
        }

        return $ids;
    }

    private function normalize_role(string $role): string
    {
        $value = strtolower(trim($role));
        if ($value === 'tank') {
            return 'Tank';
        }
        if ($value === 'healer' || $value === 'heal') {
            return 'Healer';
        }
        if ($value === 'damage' || $value === 'dps') {
            return 'Damage';
        }
        return '';
    }

    private function get_role_by_spec(int $spec_id, string $spec_name = ''): string
    {
        $spec_role_map = array(
            62 => 'Damage',
            63 => 'Damage',
            64 => 'Damage',
            65 => 'Healer',
            66 => 'Tank',
            70 => 'Damage',
            71 => 'Damage',
            72 => 'Damage',
            73 => 'Tank',
            102 => 'Damage',
            103 => 'Damage',
            104 => 'Tank',
            105 => 'Healer',
            250 => 'Tank',
            251 => 'Damage',
            252 => 'Damage',
            253 => 'Damage',
            254 => 'Damage',
            255 => 'Damage',
            256 => 'Healer',
            257 => 'Healer',
            258 => 'Damage',
            259 => 'Damage',
            260 => 'Damage',
            261 => 'Damage',
            262 => 'Damage',
            263 => 'Damage',
            264 => 'Healer',
            265 => 'Damage',
            266 => 'Damage',
            267 => 'Damage',
            268 => 'Tank',
            269 => 'Damage',
            270 => 'Healer',
            577 => 'Damage',
            581 => 'Tank',
            1467 => 'Damage',
            1468 => 'Healer',
            1473 => 'Damage',
        );

        if ($spec_id > 0 && isset($spec_role_map[$spec_id])) {
            return $spec_role_map[$spec_id];
        }

        $normalized_name = strtolower(trim($spec_name));
        $spec_name_map = array(
            'arcane' => 'Damage',
            'fire' => 'Damage',
            'frost' => 'Damage',
            'holy' => 'Healer',
            'protection' => '',
            'retribution' => 'Damage',
            'arms' => 'Damage',
            'fury' => 'Damage',
            'balance' => 'Damage',
            'feral' => 'Damage',
            'guardian' => 'Tank',
            'restoration' => '',
            'blood' => 'Tank',
            'unholy' => 'Damage',
            'beast mastery' => 'Damage',
            'marksmanship' => 'Damage',
            'survival' => 'Damage',
            'discipline' => 'Healer',
            'shadow' => 'Damage',
            'assassination' => 'Damage',
            'outlaw' => 'Damage',
            'subtlety' => 'Damage',
            'elemental' => 'Damage',
            'enhancement' => 'Damage',
            'affliction' => 'Damage',
            'demonology' => 'Damage',
            'destruction' => 'Damage',
            'brewmaster' => 'Tank',
            'windwalker' => 'Damage',
            'mistweaver' => 'Healer',
            'havoc' => 'Damage',
            'vengeance' => 'Tank',
            'devastation' => 'Damage',
            'preservation' => 'Healer',
            'augmentation' => 'Damage',
        );

        if ($normalized_name === 'protection' && in_array($spec_id, array(66, 73), true)) {
            return $spec_role_map[$spec_id];
        }

        if ($normalized_name === 'restoration' && in_array($spec_id, array(105, 264), true)) {
            return $spec_role_map[$spec_id];
        }

        if ($normalized_name !== '' && isset($spec_name_map[$normalized_name]) && $spec_name_map[$normalized_name] !== '') {
            return $spec_name_map[$normalized_name];
        }

        return '';
    }

    private function normalize_gender(string $gender): string
    {
        $value = strtolower(trim($gender));
        if ($value === 'male' || $value === 'm') {
            return 'male';
        }
        if ($value === 'female' || $value === 'f') {
            return 'female';
        }
        if ($value === '0') {
            return 'male';
        }
        if ($value === '1') {
            return 'female';
        }
        if ($value === 'masculine') {
            return 'male';
        }
        if ($value === 'feminine') {
            return 'female';
        }
        return '';
    }

    private function extract_gender_value($gender_data): string
    {
        if (is_array($gender_data)) {
            foreach (array('type', 'name', 'id') as $key) {
                if (! isset($gender_data[$key])) {
                    continue;
                }

                $normalized = $this->normalize_gender((string) $gender_data[$key]);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            foreach ($gender_data as $value) {
                $normalized = $this->extract_gender_value($value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            return '';
        }

        if (is_scalar($gender_data)) {
            return $this->normalize_gender((string) $gender_data);
        }

        return '';
    }

    private function normalize_race_name(string $race_name): string
    {
        $value = trim($race_name);
        if ($value === '') {
            return '';
        }

        $normalized = strtolower($value);
        $aliases = array(
            'harronir' => 'Haranir',
            'haranir' => 'Haranir',
            'dracthyr visage' => 'Dracthyr',
            'dracthyr (visage)' => 'Dracthyr',
        );

        return (string) ($aliases[$normalized] ?? $value);
    }

    private function get_default_role_by_class(int $class_id): string
    {
        $map = array(
            5 => 'Healer',
            2 => 'Tank',
            1 => 'Tank',
            6 => 'Tank',
            10 => 'Tank',
            11 => 'Tank',
            12 => 'Tank',
            3 => 'Damage',
            4 => 'Damage',
            8 => 'Damage',
            9 => 'Damage',
            13 => 'Damage',
            7 => 'Damage',
        );
        return (string) ($map[$class_id] ?? 'Damage');
    }

    private function fetch_guild_banner_url(string $region, string $locale, string $realm_slug, string $guild_slug, string $token): string
    {
        $cache_key = 'guilroim_guild_banner_' . md5($region . '|' . $locale . '|' . $realm_slug . '|' . $guild_slug . '|schema_6');
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached === 'none') {
            return '';
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $guild_url = sprintf('https://%s.api.blizzard.com/data/wow/guild/%s/%s', rawurlencode($region), rawurlencode($realm_slug), rawurlencode($guild_slug));
        $guild_response = wp_remote_get(
            add_query_arg(array('namespace' => 'profile-' . $region, 'locale' => $locale), $guild_url),
            array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $token))
        );

        if (is_wp_error($guild_response) || wp_remote_retrieve_response_code($guild_response) !== 200) {
            set_transient($cache_key, 'none', 30 * MINUTE_IN_SECONDS);
            return '';
        }

        $guild_data = json_decode((string) wp_remote_retrieve_body($guild_response), true);
        if (! is_array($guild_data)) {
            set_transient($cache_key, 'none', 30 * MINUTE_IN_SECONDS);
            return '';
        }

        $direct_asset_url = $this->extract_asset_url_from_media_data($guild_data, array('icon', 'emblem', 'crest', 'guild-crest', 'guild_crest', 'banner', 'tabard', 'main', 'image', 'small', 'large'));
        if ($direct_asset_url !== '') {
            $localized = $this->localize_remote_media_url($direct_asset_url, 'guild-banners');
            if ($localized !== '') {
                set_transient($cache_key, $localized, 7 * DAY_IN_SECONDS);
                return $localized;
            }
        }

        $recursive_asset_url = $this->extract_asset_url_recursive($guild_data, array('icon', 'emblem', 'crest', 'guild-crest', 'guild_crest', 'banner', 'tabard', 'main', 'image', 'small', 'large'));
        if ($recursive_asset_url !== '') {
            $localized = $this->localize_remote_media_url($recursive_asset_url, 'guild-banners');
            if ($localized !== '') {
                set_transient($cache_key, $localized, 7 * DAY_IN_SECONDS);
                return $localized;
            }
        }

        $media_hrefs = $this->collect_guild_media_hrefs($guild_data);
        foreach ($media_hrefs as $media_href) {
            $url = $this->fetch_media_asset_url($media_href, $locale, $region, $token);
            if ($url === '') {
                continue;
            }
            $localized = $this->localize_remote_media_url($url, 'guild-banners');
            if ($localized !== '') {
                set_transient($cache_key, $localized, 7 * DAY_IN_SECONDS);
                return $localized;
            }
        }

        $crest_ids = $this->collect_guild_crest_ids($guild_data);
        foreach ($crest_ids as $crest_id) {
            $url = $this->fetch_guild_crest_emblem_by_id((int) $crest_id, $locale, $region, $token);
            if ($url === '') {
                continue;
            }
            $localized = $this->localize_remote_media_url($url, 'guild-banners');
            if ($localized !== '') {
                set_transient($cache_key, $localized, 7 * DAY_IN_SECONDS);
                return $localized;
            }
        }

        set_transient($cache_key, 'none', 30 * MINUTE_IN_SECONDS);
        return '';
    }

    private function fetch_guild_crest_emblem_by_id(int $crest_id, string $locale, string $region, string $token): string
    {
        if ($crest_id <= 0) {
            return '';
        }

        $url = sprintf('https://%s.api.blizzard.com/data/wow/media/guild-crest-emblem/%d', rawurlencode($region), $crest_id);
        $response = wp_remote_get(
            add_query_arg(array('namespace' => 'static-' . $region, 'locale' => $locale), $url),
            array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $token))
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data)) {
            return '';
        }

        $asset_url = $this->extract_asset_url_from_media_data($data, array('icon', 'emblem', 'crest', 'guild-crest', 'guild_crest', 'banner', 'tabard', 'main', 'image', 'small', 'large'));
        if ($asset_url !== '') {
            return $asset_url;
        }

        return $this->extract_asset_url_recursive($data, array('icon', 'emblem', 'crest', 'guild-crest', 'guild_crest', 'banner', 'tabard', 'main', 'image', 'small', 'large'));
    }

    private function build_guild_crest_render_url(array $guild_data, string $region): string
    {
        return '';
    }

    private function fetch_media_asset_url(string $media_href, string $locale, string $region, string $token): string
    {
        if ($this->is_image_url($media_href)) {
            return esc_url_raw(set_url_scheme($media_href, 'https'));
        }

        $namespaces = array('');
        if (strpos($media_href, 'namespace=') === false) {
            $namespaces = array('static-' . $region, 'profile-' . $region, 'dynamic-' . $region);
        }

        foreach ($namespaces as $namespace) {
            $query_args = array('locale' => $locale);
            if ($namespace !== '') {
                $query_args['namespace'] = $namespace;
            }

            $media_response = wp_remote_get(
                add_query_arg($query_args, $media_href),
                array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $token))
            );

            if (is_wp_error($media_response) || wp_remote_retrieve_response_code($media_response) !== 200) {
                continue;
            }

            $media_data = json_decode((string) wp_remote_retrieve_body($media_response), true);
            if (! is_array($media_data)) {
                continue;
            }

            $asset_url = $this->extract_asset_url_from_media_data($media_data, array('icon', 'emblem', 'crest', 'guild-crest', 'guild_crest', 'banner', 'tabard', 'main', 'image', 'small', 'large'));
            if ($asset_url !== '') {
                return $asset_url;
            }

            $asset_url = $this->extract_asset_url_recursive($media_data, array('icon', 'emblem', 'crest', 'guild-crest', 'guild_crest', 'banner', 'tabard', 'main', 'image', 'small', 'large'));
            if ($asset_url !== '') {
                return $asset_url;
            }
        }

        return '';
    }

    private function collect_guild_media_hrefs(array $guild_data): array
    {
        $candidates = array();

        foreach (array('crest', 'emblem', 'media') as $key) {
            if (! empty($guild_data[$key]) && is_array($guild_data[$key])) {
                $candidates = array_merge($candidates, $this->collect_media_hrefs_recursive($guild_data[$key]));
            }
        }

        $candidates = array_merge($candidates, $this->collect_media_hrefs_recursive($guild_data));

        return array_values(array_unique(array_filter($candidates)));
    }

    private function collect_media_hrefs_recursive(array $data): array
    {
        $hrefs = array();

        foreach ($data as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $normalized_key = strtolower((string) $key);
                if ($normalized_key === 'href' || $normalized_key === 'url' || str_ends_with($normalized_key, '_url') || str_ends_with($normalized_key, '_href')) {
                    $hrefs[] = trim($value);
                }
            }

            if ($key === 'href' && is_string($value) && trim($value) !== '') {
                $hrefs[] = trim($value);
                continue;
            }

            if (is_array($value)) {
                $hrefs = array_merge($hrefs, $this->collect_media_hrefs_recursive($value));
            }
        }

        return $hrefs;
    }

    private function collect_guild_crest_ids(array $guild_data): array
    {
        $ids = array(
            absint($guild_data['crest']['emblem']['id'] ?? 0),
            absint($guild_data['crest']['id'] ?? 0),
            absint($guild_data['emblem']['id'] ?? 0),
        );

        foreach (array('crest', 'emblem') as $key) {
            if (! empty($guild_data[$key]) && is_array($guild_data[$key])) {
                $ids = array_merge($ids, $this->collect_ids_recursive($guild_data[$key]));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function collect_ids_recursive(array $data): array
    {
        $ids = array();

        foreach ($data as $key => $value) {
            if ($key === 'id' && is_numeric($value)) {
                $ids[] = absint($value);
                continue;
            }

            if (is_array($value)) {
                $ids = array_merge($ids, $this->collect_ids_recursive($value));
            }
        }

        return $ids;
    }

    private function collect_recursive_arrays(array $data): array
    {
        $results = array($data);

        foreach ($data as $value) {
            if (is_array($value)) {
                $results = array_merge($results, $this->collect_recursive_arrays($value));
            }
        }

        return $results;
    }

    private function extract_color_recursive(array $data, array $preferred_keys): string
    {
        foreach ($preferred_keys as $preferred_key) {
            foreach ($data as $key => $value) {
                if (strtolower((string) $key) !== $preferred_key) {
                    continue;
                }

                $normalized = $this->normalize_color_value($value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $normalized = $this->extract_color_recursive($value, $preferred_keys);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    private function normalize_color_value($value): string
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            $normalized = ltrim($normalized, '#');
            if (preg_match('/^[0-9a-f]{6}$/', $normalized)) {
                return $normalized;
            }
        }

        if (is_array($value)) {
            foreach (array('hex', 'rgba', 'rgb', 'color') as $key) {
                if (isset($value[$key])) {
                    $normalized = $this->normalize_color_value($value[$key]);
                    if ($normalized !== '') {
                        return $normalized;
                    }
                }
            }
        }

        return '';
    }

    private function resolve_name_field($value, string $fallback): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value)) {
            if (! empty($value['en_US']) && is_string($value['en_US'])) {
                return (string) $value['en_US'];
            }
            foreach ($value as $entry) {
                if (is_string($entry) && $entry !== '') {
                    return $entry;
                }
            }
        }
        return $fallback;
    }

    private function get_class_name_from_id(int $class_id): string
    {
        $map = array(
            1 => 'Warrior', 2 => 'Paladin', 3 => 'Hunter', 4 => 'Rogue', 5 => 'Priest', 6 => 'Death Knight',
            7 => 'Shaman', 8 => 'Mage', 9 => 'Warlock', 10 => 'Monk', 11 => 'Druid', 12 => 'Demon Hunter', 13 => 'Evoker',
        );
        return (string) ($map[$class_id] ?? '');
    }

    private function get_race_name_from_id(int $race_id): string
    {
        $map = array(
            1 => 'Human', 2 => 'Orc', 3 => 'Dwarf', 4 => 'Night Elf', 5 => 'Undead', 6 => 'Tauren', 7 => 'Gnome', 8 => 'Troll',
            9 => 'Goblin', 10 => 'Blood Elf', 11 => 'Draenei', 22 => 'Worgen', 24 => 'Pandaren', 25 => 'Pandaren', 26 => 'Pandaren',
            27 => 'Nightborne', 28 => 'Highmountain Tauren', 29 => 'Void Elf', 30 => 'Lightforged Draenei', 31 => 'Zandalari Troll',
            32 => 'Kul Tiran', 34 => 'Dark Iron Dwarf', 35 => 'Vulpera', 36 => 'Maghar Orc', 37 => 'Mechagnome', 52 => 'Dracthyr', 70 => 'Dracthyr',
        );
        return (string) ($map[$race_id] ?? '');
    }

    private function is_image_url(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('#^https?://#i', $value)) {
            return false;
        }

        $parsed = wp_parse_url($value);
        if (! is_array($parsed) || empty($parsed['host']) || empty($parsed['path'])) {
            return false;
        }

        $extension = strtolower((string) pathinfo((string) $parsed['path'], PATHINFO_EXTENSION));
        return in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true);
    }

    private function extract_first_string(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function extract_asset_url_from_media_data(array $media_data, array $priority_keys = array()): string
    {
        $assets = array();
        if (! empty($media_data['assets']) && is_array($media_data['assets'])) {
            $assets = $media_data['assets'];
        } elseif (! empty($media_data['media']['assets']) && is_array($media_data['media']['assets'])) {
            $assets = $media_data['media']['assets'];
        }

        if (! empty($assets)) {
            $priority_keys[] = '';
            foreach ($priority_keys as $wanted) {
                foreach ($assets as $asset) {
                    if (! is_array($asset)) {
                        continue;
                    }

                    $key = strtolower((string) ($asset['key'] ?? ''));
                    if ($wanted !== '' && $key !== $wanted) {
                        continue;
                    }

                    $value = $this->extract_first_string(
                        array(
                            $asset['value'] ?? '',
                            $asset['file_data_id'] ?? '',
                            $asset['url'] ?? '',
                        )
                    );

                    if ($this->is_image_url($value)) {
                        return esc_url_raw(set_url_scheme($value, 'https'));
                    }
                }
            }
        }

        return $this->extract_first_string(
            array_filter(
                array(
                    $this->is_image_url((string) ($media_data['href'] ?? '')) ? (string) $media_data['href'] : '',
                    $this->is_image_url((string) ($media_data['url'] ?? '')) ? (string) $media_data['url'] : '',
                    $this->is_image_url((string) ($media_data['value'] ?? '')) ? (string) $media_data['value'] : '',
                )
            )
        );
    }

    private function extract_asset_url_recursive(array $data, array $priority_keys = array()): string
    {
        $direct_match = $this->extract_asset_url_from_media_data($data, $priority_keys);
        if ($direct_match !== '') {
            return $direct_match;
        }

        foreach ($data as $key => $value) {
            if (is_string($value) && $this->is_image_url($value)) {
                $normalized_key = strtolower((string) $key);
                if ($normalized_key === 'href' || $normalized_key === 'url' || str_contains($normalized_key, 'image') || str_contains($normalized_key, 'icon') || str_contains($normalized_key, 'crest') || str_contains($normalized_key, 'emblem') || str_contains($normalized_key, 'banner') || str_contains($normalized_key, 'tabard')) {
                    return esc_url_raw(set_url_scheme($value, 'https'));
                }
            }

            if (is_array($value)) {
                $nested_match = $this->extract_asset_url_recursive($value, $priority_keys);
                if ($nested_match !== '') {
                    return $nested_match;
                }
            }
        }

        return '';
    }

    private function parse_ranks(string $ranks): array
    {
        if ($ranks === '') {
            return array();
        }

        $parts = preg_split('/\s*,\s*/', $ranks);
        if (! is_array($parts)) {
            return array();
        }

        $values = array();
        foreach ($parts as $part) {
            if ($part === '' || ! is_numeric($part)) {
                continue;
            }
            $values[] = absint($part);
        }

        return array_values(array_unique($values));
    }

    private function build_sort_callback(string $sort_by, string $sort_order): callable
    {
        return static function (array $a, array $b) use ($sort_by, $sort_order): int {
            switch ($sort_by) {
                case 'name':
                    $result = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                    break;
                case 'level':
                    $result = ((int) ($a['level'] ?? 0)) <=> ((int) ($b['level'] ?? 0));
                    break;
                case 'class':
                    $result = strcasecmp((string) ($a['class'] ?? ''), (string) ($b['class'] ?? ''));
                    break;
                case 'race':
                    $result = strcasecmp((string) ($a['race'] ?? ''), (string) ($b['race'] ?? ''));
                    break;
                case 'role':
                    $result = strcasecmp((string) ($a['role'] ?? ''), (string) ($b['role'] ?? ''));
                    break;
                case 'mythic_score':
                    $result = ((int) ($a['mythic_score'] ?? 0)) <=> ((int) ($b['mythic_score'] ?? 0));
                    break;
                case 'rank':
                default:
                    $result = ((int) ($a['rank'] ?? 0)) <=> ((int) ($b['rank'] ?? 0));
                    break;
            }

            if ($result === 0) {
                $result = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }

            if ($sort_order === 'desc') {
                $result = $result * -1;
            }

            return $result;
        };
    }
}
