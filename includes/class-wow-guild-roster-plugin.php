<?php

if (! defined('ABSPATH')) {
    exit;
}

class WoW_Guild_Roster_Plugin
{
    public const CRON_HOOK = 'guilroim_daily_sync_event';
    private const DAILY_SYNC_TIMEZONE = 'Europe/Berlin';

    private WoW_Guild_Roster_Settings $settings;
    private WoW_Guild_Roster_API $api;
    private WoW_Guild_Roster_Shortcode $shortcode;
    private WoW_Guild_Roster_Character_Page $character_page;

    public function init(): void
    {
        $this->settings = new WoW_Guild_Roster_Settings();
        $this->api = new WoW_Guild_Roster_API();
        $this->shortcode = new WoW_Guild_Roster_Shortcode($this->api, $this->settings);
        $this->character_page = new WoW_Guild_Roster_Character_Page($this->settings, $this->api);

        add_action(self::CRON_HOOK, array($this, 'refresh_roster_via_cron'));
        add_action('init', array($this, 'maybe_schedule_daily_sync'));
        add_action('admin_init', array($this->settings, 'register_settings'));
        add_action('admin_menu', array($this->settings, 'add_settings_page'));
        add_action('admin_enqueue_scripts', array($this->settings, 'enqueue_admin_assets'));
        add_action('init', array($this->shortcode, 'register_shortcode'));
        add_action('wp_enqueue_scripts', array($this->shortcode, 'register_assets'));
        add_filter('plugin_action_links_' . plugin_basename(GUILROIM_PLUGIN_FILE), array($this, 'add_plugin_action_links'));
        $this->character_page->register_hooks();
        add_action('admin_post_guilroim_sync_roster', array($this, 'handle_sync_roster'));
        add_action('admin_post_guilroim_sync_mythic', array($this, 'handle_sync_mythic'));
        add_action('admin_post_guilroim_test_connection', array($this, 'handle_sync_roster'));
        add_action('wp_ajax_guilroim_get_avatar', array($this, 'handle_get_avatar'));
        add_action('wp_ajax_nopriv_guilroim_get_avatar', array($this, 'handle_get_avatar'));
        add_action('wp_ajax_guilroim_get_mythic_score', array($this, 'handle_get_mythic_score'));
        add_action('wp_ajax_nopriv_guilroim_get_mythic_score', array($this, 'handle_get_mythic_score'));
        add_action('wp_ajax_guilroim_get_mythic_scores', array($this, 'handle_get_mythic_scores'));
        add_action('wp_ajax_nopriv_guilroim_get_mythic_scores', array($this, 'handle_get_mythic_scores'));
    }

    public static function activate(): void
    {
        wp_clear_scheduled_hook('guilroim_refresh_roster_event');
        self::schedule_next_daily_sync();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook('guilroim_refresh_roster_event');
    }

    public function maybe_schedule_daily_sync(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            self::schedule_next_daily_sync();
        }
    }

    public function refresh_roster_via_cron(): void
    {
        self::schedule_next_daily_sync();
        $options = $this->settings->get_options();
        $options['skip_profile_enrichment'] = true;
        $options['skip_mythic_score_fetch'] = true;
        $roster = $this->api->refresh_roster($options);
        if (! is_wp_error($roster) && is_array($roster)) {
            $this->api->sync_roster_mythic_scores($options, $roster);
        }
    }

    public function handle_sync_roster(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'guild-roster-importer-for-wow'));
        }

        check_admin_referer('guilroim_sync_roster');

        $options = $this->settings->get_options();
        $options['skip_profile_enrichment'] = true;
        $options['skip_mythic_score_fetch'] = true;
        $result = $this->api->refresh_roster($options);

        $status = 'success';
        $message = __('Guild roster synced successfully from Battle.net.', 'guild-roster-importer-for-wow');

        if (is_wp_error($result)) {
            $status = 'error';
            $message = $result->get_error_message();
        }

        $redirect_url = add_query_arg(
            array(
                'page' => WoW_Guild_Roster_Settings::PAGE_SLUG,
                'guilroim_sync_status' => $status,
                'guilroim_sync_message' => $message,
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_sync_mythic(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'guild-roster-importer-for-wow'));
        }

        check_admin_referer('guilroim_sync_mythic');

        $options = $this->settings->get_options();
        $result = $this->api->sync_roster_mythic_scores($options);

        $status = 'success';
        $message = __('Mythic+ data synced successfully.', 'guild-roster-importer-for-wow');

        if (is_wp_error($result)) {
            $status = 'error';
            $message = $result->get_error_message();
        } elseif (is_array($result)) {
            /* translators: 1: number of synced max-level members, 2: detected expansion max level, 3: members with non-zero Mythic+ scores. */
            $message_template = __('Mythic+ data synced for %1$d max-level members (max level %2$d, %3$d non-zero scores).', 'guild-roster-importer-for-wow');
            $message = sprintf(
                $message_template,
                (int) ($result['updated_members'] ?? 0),
                (int) ($result['max_level'] ?? 0),
                (int) ($result['non_zero_scores'] ?? 0)
            );
        }

        $redirect_url = add_query_arg(
            array(
                'page' => WoW_Guild_Roster_Settings::PAGE_SLUG,
                'guilroim_mythic_sync_status' => $status,
                'guilroim_mythic_sync_message' => $message,
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    private static function schedule_next_daily_sync(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_schedule_single_event(self::get_next_daily_sync_timestamp(), self::CRON_HOOK);
    }

    private static function get_next_daily_sync_timestamp(): int
    {
        $timezone = new DateTimeZone(self::DAILY_SYNC_TIMEZONE);
        $now = new DateTimeImmutable('now', $timezone);
        $next = $now->setTime(5, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }

    public function handle_get_avatar(): void
    {
        if (! check_ajax_referer('guilroim_avatar_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'guild-roster-importer-for-wow')), 403);
        }

        $character = $this->get_request_text('character');
        $realm = $this->get_request_text('realm');
        $region = WoW_Guild_Roster_Settings::normalize_region($this->get_request_text('region'), '');
        if ($character === '' || $realm === '' || $region === '') {
            wp_send_json_error(array('message' => __('Missing avatar parameters.', 'guild-roster-importer-for-wow')), 400);
        }

        $options = $this->settings->get_options();
        $avatar_url = $this->api->get_character_avatar_url($options, $character, $realm, $region);
        if ($avatar_url === '') {
            wp_send_json_error(array('message' => __('Avatar not found.', 'guild-roster-importer-for-wow')), 404);
        }

        wp_send_json_success(array('avatar_url' => $avatar_url));
    }

    public function handle_get_mythic_score(): void
    {
        if (! check_ajax_referer('guilroim_avatar_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'guild-roster-importer-for-wow')), 403);
        }

        $character = $this->get_request_text('character');
        $realm = $this->get_request_text('realm');
        $region = WoW_Guild_Roster_Settings::normalize_region($this->get_request_text('region'), '');
        if ($character === '' || $realm === '' || $region === '') {
            wp_send_json_error(array('message' => __('Missing Mythic+ parameters.', 'guild-roster-importer-for-wow')), 400);
        }

        $options = $this->settings->get_options();
        $score = $this->api->get_character_mythic_score($options, $character, $realm, $region);

        wp_send_json_success(array('score' => $score));
    }

    public function handle_get_mythic_scores(): void
    {
        if (! check_ajax_referer('guilroim_avatar_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid request.', 'guild-roster-importer-for-wow')), 403);
        }

        $region = WoW_Guild_Roster_Settings::normalize_region($this->get_request_text('region'), '');
        $characters_json = $this->get_request_raw('characters');
        $characters = $characters_json !== '' ? json_decode($characters_json, true) : array();
        if ($region === '' || ! is_array($characters) || empty($characters)) {
            wp_send_json_error(array('message' => __('Missing Mythic+ batch parameters.', 'guild-roster-importer-for-wow')), 400);
        }

        $sanitized = array();
        foreach ($characters as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $character = sanitize_text_field((string) ($entry['character'] ?? ''));
            $realm = sanitize_text_field((string) ($entry['realm'] ?? ''));
            if ($character === '' || $realm === '') {
                continue;
            }

            $sanitized[] = array(
                'character' => $character,
                'realm' => $realm,
            );
        }

        if (empty($sanitized)) {
            wp_send_json_error(array('message' => __('No valid Mythic+ batch members were provided.', 'guild-roster-importer-for-wow')), 400);
        }

        $options = $this->settings->get_options();
        $scores = $this->api->get_character_mythic_scores_bulk($options, $sanitized, $region);

        wp_send_json_success(array('scores' => $scores));
    }

    private function get_request_text(string $key): string
    {
        return sanitize_text_field($this->get_request_raw($key));
    }

    private function get_request_raw(string $key): string
    {
        $value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        if ($value === null) {
            $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        }

        return is_string($value) ? wp_unslash($value) : '';
    }

    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . WoW_Guild_Roster_Settings::PAGE_SLUG)),
            esc_html__('Settings', 'guild-roster-importer-for-wow')
        );

        array_unshift($links, $settings_link);

        return $links;
    }
}
