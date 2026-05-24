<?php

if (! defined('ABSPATH')) {
    exit;
}

class WoW_Guild_Roster_Character_Page
{
    private WoW_Guild_Roster_Settings $settings;
    private WoW_Guild_Roster_API $api;
    private bool $is_character_request = false;
    private string $character_markup = '';
    public function __construct(WoW_Guild_Roster_Settings $settings, WoW_Guild_Roster_API $api)
    {
        $this->settings = $settings;
        $this->api = $api;
    }

    public function register_hooks(): void
    {
        add_action('wp', array($this, 'maybe_prepare_character_page'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_character_assets'));
        add_filter('the_content', array($this, 'filter_the_content'), 999);
    }

    public function maybe_prepare_character_page(): void
    {
        $character = sanitize_text_field($this->get_query_string('guilroim_character'));
        $realm = sanitize_text_field($this->get_query_string('guilroim_realm'));
        $realm_slug = sanitize_title($this->get_query_string('guilroim_realm_slug'));
        $region = WoW_Guild_Roster_Settings::normalize_region(sanitize_text_field($this->get_query_string('guilroim_region')), '');

        if ($character === '' || ($realm === '' && $realm_slug === '') || $region === '') {
            return;
        }

        $this->is_character_request = true;
        $options = $this->settings->get_options();
        $locale = WoW_Guild_Roster_Settings::get_locale_for_region($region);

        $details = $this->fetch_character_details($options, $character, $realm, $realm_slug, $region, $locale);
        if (is_wp_error($details)) {
            $this->character_markup = '<div class="wgrp-error">' . esc_html($details->get_error_message()) . '</div>';
            return;
        }

        $this->character_markup = $this->render_character_template($details, $region, $realm, $character);
    }

    public function enqueue_character_assets(): void
    {
        if (! $this->is_character_request) {
            return;
        }

        wp_enqueue_style('guilroim-roster-themes');
        wp_enqueue_style(
            'guilroim-character-profile-style',
            GUILROIM_PLUGIN_URL . 'assets/css/character-profile.css',
            array('guilroim-roster-themes'),
            $this->get_character_page_style_version()
        );
        wp_enqueue_script(
            'guilroim-character-profile',
            GUILROIM_PLUGIN_URL . 'assets/js/character-profile.js',
            array(),
            $this->get_character_page_script_version(),
            true
        );
    }

    public function filter_the_content(string $content): string
    {
        if (! $this->is_character_request || ! is_main_query() || ! in_the_loop()) {
            return $content;
        }

        return $this->character_markup;
    }

    private function fetch_character_details(array $options, string $character, string $realm, string $realm_slug, string $region, string $locale)
    {
        $client_id = trim((string) ($options['blizzard_client_id'] ?? ''));
        $client_secret = trim((string) ($options['blizzard_client_secret'] ?? ''));
        if ($client_id === '' || $client_secret === '') {
            return new WP_Error('guilroim_profile_missing_credentials', __('Battle.net API credentials are missing.', 'guild-roster-importer-for-wow'));
        }

        $realm_slug = $realm_slug !== '' ? sanitize_title($realm_slug) : sanitize_title($realm);
        $character_slug = $this->normalize_character_slug($character);
        $cache_key = 'guilroim_character_profile_' . md5(wp_json_encode(array($region, $locale, $realm_slug, $character_slug, 'schema_4')));
        $cached = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached['summary']) && ! empty($cached['equipment'])) {
            return $cached;
        }

        $token = $this->api->get_access_token($client_id, $client_secret);
        if (is_wp_error($token)) {
            return $token;
        }

        $profile_ns = 'profile-' . $region;

        $summary_url = sprintf('https://%s.api.blizzard.com/profile/wow/character/%s/%s', rawurlencode($region), rawurlencode($realm_slug), rawurlencode($character_slug));
        $equipment_url = $summary_url . '/equipment';
        $media_url = $summary_url . '/character-media';
        $statistics_url = $summary_url . '/statistics';
        $specializations_url = $summary_url . '/specializations';
        $professions_url = $summary_url . '/professions';
        $mythic_keystone_profile_url = $summary_url . '/mythic-keystone-profile';
        $raid_encounters_url = $summary_url . '/encounters/raids';

        $summary = $this->api->api_get_json($summary_url, $profile_ns, $locale, $token);
        if (is_wp_error($summary)) {
            return $summary;
        }

        $equipment = $this->api->api_get_json($equipment_url, $profile_ns, $locale, $token);
        if (is_wp_error($equipment)) {
            return $equipment;
        }

        $media = $this->api->api_get_json($media_url, $profile_ns, $locale, $token);
        if (is_wp_error($media)) {
            $media = array();
        }

        $statistics = $this->api->api_get_json($statistics_url, $profile_ns, $locale, $token);
        if (is_wp_error($statistics)) {
            $statistics = array();
        }

        $specializations = $this->api->api_get_json($specializations_url, $profile_ns, $locale, $token);
        if (is_wp_error($specializations)) {
            $specializations = array();
        }

        $professions = $this->api->api_get_json($professions_url, $profile_ns, $locale, $token);
        if (is_wp_error($professions)) {
            $professions = array();
        }

        $mythic_keystone_profile = $this->api->api_get_json($mythic_keystone_profile_url, $profile_ns, $locale, $token);
        if (is_wp_error($mythic_keystone_profile)) {
            $mythic_keystone_profile = array();
        }

        $mythic_keystone_seasons = $this->fetch_character_mythic_keystone_seasons($summary_url, $profile_ns, $locale, $token);
        $raid_encounters = $this->api->api_get_json($raid_encounters_url, $profile_ns, $locale, $token);
        if (is_wp_error($raid_encounters)) {
            $raid_encounters = array();
        }

        if (! empty($equipment['equipped_items']) && is_array($equipment['equipped_items'])) {
            foreach ($equipment['equipped_items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $equipment['equipped_items'][$index]['region'] = strtolower($region);
                $icon_url = $this->extract_item_icon_url_from_data($item);
                if ($icon_url === '') {
                    $icon_url = $this->fetch_item_icon_url($options, absint($item['item']['id'] ?? 0), $region, $locale);
                }
                $equipment['equipped_items'][$index]['icon_url'] = $icon_url;
                if (! empty($item['sockets']) && is_array($item['sockets'])) {
                    foreach ($item['sockets'] as $socket_index => $socket) {
                        if (! is_array($socket)) {
                            continue;
                        }

                        $gem_item_id = absint($socket['item']['id'] ?? $socket['gem']['item']['id'] ?? $socket['gem']['id'] ?? 0);
                        if ($gem_item_id <= 0) {
                            continue;
                        }

                        $equipment['equipped_items'][$index]['sockets'][$socket_index]['guilroim_icon_url'] = $this->fetch_item_icon_url($options, $gem_item_id, $region, $locale);
                    }
                }
            }
        }

        $result = array(
            'summary' => $summary,
            'equipment' => $equipment,
            'media' => is_array($media) ? $media : array(),
            'statistics' => is_array($statistics) ? $statistics : array(),
            'specializations' => is_array($specializations) ? $specializations : array(),
            'professions' => is_array($professions) ? $professions : array(),
            'mythic_keystone_profile' => is_array($mythic_keystone_profile) ? $mythic_keystone_profile : array(),
            'mythic_keystone_seasons' => is_array($mythic_keystone_seasons) ? $mythic_keystone_seasons : array(),
            'raid_encounters' => is_array($raid_encounters) ? $raid_encounters : array(),
        );

        set_transient($cache_key, $result, 4 * HOUR_IN_SECONDS);

        return $result;
    }

      private function render_character_template(array $details, string $region, string $realm, string $character): string
      {
        $summary = $details['summary'];
        $equipment = $details['equipment'];
        $media = $details['media'];
        $statistics = is_array($details['statistics'] ?? null) ? $details['statistics'] : array();
        $specializations = is_array($details['specializations'] ?? null) ? $details['specializations'] : array();
        $professions = is_array($details['professions'] ?? null) ? $details['professions'] : array();
        $mythic_keystone_profile = is_array($details['mythic_keystone_profile'] ?? null) ? $details['mythic_keystone_profile'] : array();
        $mythic_keystone_seasons = is_array($details['mythic_keystone_seasons'] ?? null) ? $details['mythic_keystone_seasons'] : array();
        $raid_encounters = is_array($details['raid_encounters'] ?? null) ? $details['raid_encounters'] : array();

        $name = (string) ($summary['name'] ?? $character);
        $realm_name = trim((string) ($summary['realm']['name'] ?? $realm));
        $realm_slug = trim((string) ($summary['realm']['slug'] ?? sanitize_title($realm_name)));
        $display_name = $this->format_character_name_display($name);
        $level = (int) ($summary['level'] ?? 0);
        $class_name = (string) ($summary['character_class']['name'] ?? '');
        $race_name = (string) ($summary['race']['name'] ?? '');
        $spec_name = (string) ($summary['active_spec']['name'] ?? '');
        $equipped_ilvl = (int) ($summary['equipped_item_level'] ?? 0);
        $class_slug = sanitize_html_class(strtolower(str_replace(' ', '-', $class_name)));
        $theme = $this->get_character_theme($class_name);
        $faction = $this->normalize_faction((string) ($summary['faction']['type'] ?? $summary['faction']['name'] ?? ''));
        $faction_label = $faction !== '' ? ucfirst($faction) : '';
        $character_title = $this->extract_character_title($summary, $name);
        $local_background_url = $this->get_profile_background_url($class_name);
        $armory_url = $this->build_character_armory_url($region, $realm_slug, $name);
        $achievement_points = (int) ($summary['achievement_points'] ?? 0);

        $render_url = '';
        $bg_url = '';
        if (! empty($media['assets']) && is_array($media['assets'])) {
            foreach ($media['assets'] as $asset) {
                if (! is_array($asset)) {
                    continue;
                }
                $key = (string) ($asset['key'] ?? '');
                $value = (string) ($asset['value'] ?? '');
                if ($key === 'background') {
                    $bg_url = $this->api->localize_remote_media_url($value, 'profile-backgrounds');
                }
                if ($render_url === '' && ($key === 'main-raw' || $key === 'main')) {
                    $render_url = $this->api->localize_remote_media_url($value, 'character-renders');
                }
            }
        }

        if ($local_background_url !== '') {
            $bg_url = $local_background_url;
        }

        $items = is_array($equipment['equipped_items'] ?? null) ? $equipment['equipped_items'] : array();
        $left_slots = array('HEAD', 'NECK', 'SHOULDER', 'BACK', 'CHEST', 'SHIRT', 'TABARD', 'WRIST');
        $right_slots = array('HANDS', 'WAIST', 'LEGS', 'FEET', 'FINGER_1', 'FINGER_2', 'TRINKET_1', 'TRINKET_2');
        $bottom_slots = array('MAIN_HAND', 'OFF_HAND');

        $by_slot = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $slot_key = (string) ($item['slot']['type'] ?? '');
            if ($slot_key !== '') {
                $by_slot[$slot_key] = $item;
            }
        }

          $back_url = $this->get_back_to_roster_url();
          $stats_sections = $this->build_character_stats_sections($summary, $statistics);
          $mythic_keystone_panel = $this->build_mythic_keystone_panel_data($mythic_keystone_profile, $mythic_keystone_seasons, $region);
          $raid_progression_panel = $this->build_raid_progression_panel_data($raid_encounters, $region);
          $professions_panel = $this->build_professions_panel_data($professions);
          $specialization_sections = $this->build_specialization_sections($summary, $specializations);
          $identity_line = trim(preg_replace('/\s+/', ' ', $level . ' ' . $race_name . ' ' . $class_name . ' ' . $spec_name));
          $header_nav = array();
          if (! empty($specialization_sections)) {
              $header_nav[] = array(
                  'href' => '#wgrp-specializations',
                  'label' => __('Specializations', 'guild-roster-importer-for-wow'),
              );
          }
          if (! empty($mythic_keystone_panel)) {
              $header_nav[] = array(
                  'href' => '#wgrp-mythic-plus',
                  'label' => __('Mythic Plus', 'guild-roster-importer-for-wow'),
              );
          }
          if (! empty($raid_progression_panel)) {
              $header_nav[] = array(
                  'href' => '#wgrp-raid-progress',
                  'label' => __('Raid Progress', 'guild-roster-importer-for-wow'),
              );
          }
          if (! empty($professions_panel)) {
              $header_nav[] = array(
                  'href' => '#wgrp-professions',
                  'label' => __('Professions', 'guild-roster-importer-for-wow'),
              );
          }
          $header_stats = array(
            array(
                'icon' => 'achievement',
                'value' => $achievement_points > 0 ? (string) $achievement_points : '0',
            ),
            array(
                'icon' => 'ilvl',
                'value' => (string) $equipped_ilvl,
                'suffix' => 'ILVL',
            ),
            array(
                'icon' => 'mplus',
                'prefix' => 'M+',
                'value' => ! empty($mythic_keystone_panel['score']) ? (string) $mythic_keystone_panel['score'] : '0',
            ),
          );
          $wrap_style = sprintf(
            '--wgrp-accent:%1$s;--wgrp-accent-soft:%2$s;--wgrp-panel-border:%3$s;%4$s',
            esc_attr((string) $theme['accent']),
            esc_attr((string) $theme['soft']),
            esc_attr((string) $theme['border']),
            $bg_url !== '' ? "--wgrp-hero-bg:url('" . esc_url($bg_url) . "');" : ''
        );

          ob_start();
          ?>
  <div class="wgrp-wrap wgrp-class-<?php echo esc_attr($class_slug); ?>" style="<?php echo esc_attr($wrap_style); ?>">
  <div class="wgrp-hero">
  <div class="wgrp-backdrop"></div>
  <div class="wgrp-top">
<div class="wgrp-name-row"><?php if ($faction_label !== '') { ?><img class="wgrp-faction-icon" src="<?php echo esc_url($this->get_faction_icon_url($faction)); ?>" alt="<?php echo esc_attr($faction_label); ?>" title="<?php echo esc_attr($faction_label); ?>" loading="lazy" width="28" height="28" /><?php } ?><div class="wgrp-name-block"><?php if (($character_title['position'] ?? '') === 'prefix' && ($character_title['title'] ?? '') !== '') { ?><div class="wgrp-character-title"><?php echo esc_html((string) $character_title['title']); ?></div><?php } ?><h1 class="wgrp-name"><?php if ($armory_url !== '') { ?><a class="wgrp-name-link" href="<?php echo esc_url($armory_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($display_name); ?></a><?php } else { echo esc_html($display_name); } ?></h1><?php if (($character_title['position'] ?? '') === 'suffix' && ($character_title['title'] ?? '') !== '') { ?><div class="wgrp-character-title"><?php echo esc_html((string) $character_title['title']); ?></div><?php } ?></div><div class="wgrp-header-meta"><div class="wgrp-header-stats"><?php foreach ($header_stats as $stat) { ?><div class="wgrp-header-stat"><span class="wgrp-header-stat-icon" aria-hidden="true"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted internal SVG markup. ?><?php echo $this->get_header_meta_icon_svg((string) ($stat['icon'] ?? '')); ?></span><span class="wgrp-header-stat-value"><?php if (! empty($stat['prefix'])) { ?><span class="wgrp-header-stat-prefix"><?php echo esc_html((string) $stat['prefix']); ?></span><?php } ?><?php echo esc_html((string) ($stat['value'] ?? '0')); ?><?php if (! empty($stat['suffix'])) { ?> <span class="wgrp-header-stat-suffix"><?php echo esc_html((string) $stat['suffix']); ?></span><?php } ?></span></div><?php } ?></div><div class="wgrp-meta"><?php echo esc_html($identity_line); ?></div></div></div>
<nav class="wgrp-header-nav" aria-label="<?php esc_attr_e('Character profile sections', 'guild-roster-importer-for-wow'); ?>">
<a class="wgrp-header-nav-link wgrp-back" href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to roster', 'guild-roster-importer-for-wow'); ?></a>
<?php foreach ($header_nav as $nav_item) { ?>
<a class="wgrp-header-nav-link" href="<?php echo esc_url((string) ($nav_item['href'] ?? '#')); ?>"><?php echo esc_html((string) ($nav_item['label'] ?? '')); ?></a>
<?php } ?>
</nav>
</div>
<div class="wgrp-layout">
<div class="wgrp-col wgrp-col-left"><?php foreach ($left_slots as $slot) { ?><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_item_row($by_slot[$slot] ?? null, $slot); ?><?php } ?></div>
<div class="wgrp-center">
<div class="wgrp-render-stage"></div>
<?php if ($render_url !== '') { ?><img class="wgrp-render" src="<?php echo esc_url($render_url); ?>" alt="<?php echo esc_attr($name); ?>" /><?php } ?>
<div class="wgrp-bottom-gear"><?php foreach ($bottom_slots as $slot) { ?><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_item_row($by_slot[$slot] ?? null, $slot, $slot === 'MAIN_HAND' ? 'wgrp-item-compact wgrp-item-mirrored' : 'wgrp-item-compact', $slot === 'MAIN_HAND'); ?><?php } ?></div>
</div>
<div class="wgrp-col wgrp-col-right"><?php foreach ($right_slots as $slot) { ?><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_item_row($by_slot[$slot] ?? null, $slot, 'wgrp-item-mirrored', true); ?><?php } ?></div>
</div>
 </div>
<div class="wgrp-content">
<?php if (! empty($stats_sections)) { ?>
<div class="wgrp-stats-panel">
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_profile_section_divider(); ?>
<?php foreach ($stats_sections as $stats_section) { ?>
<section class="wgrp-card wgrp-stats-card">
<div class="wgrp-stats-grid">
<?php foreach (($stats_section['stats'] ?? array()) as $stat) { ?><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_stat_card($stat); ?><?php } ?>
</div>
</section>
<?php } ?>
</div>
<?php } ?>
<?php if (! empty($specialization_sections)) { ?>
<div class="wgrp-profile-sections">
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_profile_sections_stack(array($this->render_specialization_panel($specialization_sections), $this->render_mythic_keystone_panel($mythic_keystone_panel), $this->render_raid_progression_panel($raid_progression_panel), $this->render_professions_panel($professions_panel))); ?>
</div>
<?php } elseif (! empty($mythic_keystone_panel)) { ?>
<div class="wgrp-profile-sections">
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_profile_sections_stack(array($this->render_mythic_keystone_panel($mythic_keystone_panel), $this->render_raid_progression_panel($raid_progression_panel), $this->render_professions_panel($professions_panel))); ?>
</div>
<?php } elseif (! empty($raid_progression_panel) || ! empty($professions_panel)) { ?>
<div class="wgrp-profile-sections">
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_profile_sections_stack(array($this->render_raid_progression_panel($raid_progression_panel), $this->render_professions_panel($professions_panel))); ?>
</div>
<?php } ?>
 </div>
</div>
        <?php

          return (string) ob_get_clean();
      }

      private function render_profile_section_divider(): string
      {
          return '<div class="wgrp-section-divider" aria-hidden="true"></div>';
      }

      private function render_profile_sections_stack(array $sections): string
      {
          $sections = array_values(array_filter($sections, static function ($section): bool {
              return is_string($section) && trim($section) !== '';
          }));

          if (empty($sections)) {
              return '';
          }

          return $this->render_profile_section_divider() . implode($this->render_profile_section_divider(), $sections);
      }

      private function build_character_armory_url(string $region, string $realm_slug, string $character_name): string
      {
          $region = strtolower(trim($region));
          $realm_slug = sanitize_title($realm_slug);
          $character_slug = $this->normalize_character_slug($character_name);

          if ($region === '' || $realm_slug === '' || $character_slug === '') {
              return '';
          }

          $site_locale = strtolower(str_replace('_', '-', WoW_Guild_Roster_Settings::get_locale_for_region($region)));

          return sprintf(
              'https://worldofwarcraft.blizzard.com/%s/character/%s/%s/%s',
              rawurlencode($site_locale),
              rawurlencode($region),
              rawurlencode($realm_slug),
              rawurlencode($character_slug)
          );
      }

      private function render_item_row($item, string $slot, string $extra_class = '', bool $is_mirrored = false): string
    {
        $slot_label = ucwords(strtolower(str_replace('_', ' ', $slot)));
        $classes = trim('wgrp-item ' . $extra_class);
        if (! is_array($item)) {
            $empty_slot_icon = $this->get_empty_slot_icon_url($slot);
            if ($empty_slot_icon !== '') {
                $placeholder = '<img class="wgrp-item-media wgrp-item-media-empty-icon" src="' . esc_url($empty_slot_icon) . '" alt="' . esc_attr($slot_label) . '" loading="lazy" width="48" height="48" />';
            } else {
                $slot_short = $this->get_slot_short_label($slot);
                $placeholder = '<div class="wgrp-item-media wgrp-item-media-empty" aria-hidden="true"><span class="wgrp-empty-slot-glyph">' . esc_html($slot_short) . '</span></div>';
            }
            return $this->build_item_row_markup($classes, $placeholder, $slot_label, '-', '-', $is_mirrored, true);
        }

        $item_name = (string) ($item['name'] ?? __('Unknown Item', 'guild-roster-importer-for-wow'));
        $item_id = (int) ($item['item']['id'] ?? 0);
        $item_level = (int) ($item['level']['value'] ?? 0);
        $icon_url = trim((string) ($item['icon_url'] ?? ''));
        $item_region = strtolower(trim((string) ($item['region'] ?? '')));
        $item_meta = $this->build_item_meta($item);
        $item_meta['region'] = $item_region;
        $item_quality_class = $this->get_item_quality_class($item);
        $href = $this->build_wowhead_item_url($item, $item_meta);
        $wowhead_data = $this->build_wowhead_item_tooltip_data($item, $item_meta);
        $icon_markup = '<div class="wgrp-item-media wgrp-item-media-empty" aria-hidden="true"></div>';
        if ($icon_url !== '') {
            $icon_markup = '<img class="wgrp-item-media" src="' . esc_url($icon_url) . '" alt="' . esc_attr($item_name) . '" loading="lazy" width="42" height="42" />';
        }

        return $this->build_item_row_markup(
            $classes,
            $icon_markup,
            $slot_label,
            '<span class="wgrp-item-link ' . esc_attr($item_quality_class) . '">' . esc_html($item_name) . '</span>',
            (string) $item_level,
            $is_mirrored,
            false,
            $item_meta,
            $href,
            $wowhead_data
        );
    }

    private function get_back_to_roster_url(): string
    {
        $request_uri = $this->get_server_request_uri();
        $url = home_url($request_uri !== '' ? $request_uri : '/');

        return (string) remove_query_arg(array('guilroim_character', 'guilroim_realm', 'guilroim_region'), $url);
    }

    private function get_character_page_style_version(): string
    {
        $style_path = GUILROIM_PLUGIN_DIR . 'assets/css/character-profile.css';

        return file_exists($style_path) ? (string) filemtime($style_path) : GUILROIM_PLUGIN_VERSION;
    }

    private function get_character_page_script_version(): string
    {
        $script_path = GUILROIM_PLUGIN_DIR . 'assets/js/character-profile.js';

        return file_exists($script_path) ? (string) filemtime($script_path) : GUILROIM_PLUGIN_VERSION;
    }

    private function get_query_string(string $key): string
    {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);

        return is_string($value) ? wp_unslash($value) : '';
    }

    private function get_request_value(string $key): string
    {
        $value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        if ($value === null) {
            $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        }

        return is_string($value) ? wp_unslash($value) : '';
    }

    private function get_server_request_uri(): string
    {
        $request_uri = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);

        return is_string($request_uri) ? wp_unslash($request_uri) : '/';
    }

    private function build_character_stats_sections(array $summary, array $statistics): array
    {
        if (empty($summary) && empty($statistics)) {
            return array();
        }

        $class_name = (string) ($summary['character_class']['name'] ?? '');
        $spec_name = (string) ($summary['active_spec']['name'] ?? '');
        $primary_stat_label = $this->get_primary_stat_label($class_name, $spec_name);
        $resource_label = $this->get_resource_label($class_name, $spec_name);

        $primary_stats = array_filter(
            array(
                $this->build_stat_card_data('Health', $this->extract_health_value($summary, $statistics), '', 'health'),
                $this->build_stat_card_data($resource_label, $this->extract_resource_value($resource_label, $summary, $statistics), '', $resource_label),
                $this->build_stat_card_data($primary_stat_label, $this->extract_primary_stat_value($primary_stat_label, $statistics), '', $primary_stat_label),
                $this->build_stat_card_data('Stamina', $this->extract_named_stat_value($statistics, array('stamina')), '', 'stamina'),
            ),
            static function ($stat): bool {
                return is_array($stat) && ($stat['value'] ?? '') !== '';
            }
        );

        $secondary_stats = array_filter(
            array(
                $this->build_percentage_stat_card('Critical Strike', $this->extract_critical_strike_data($statistics), 'critical-strike'),
                $this->build_percentage_stat_card('Haste', $this->extract_haste_data($statistics), 'haste'),
                $this->build_percentage_stat_card('Mastery', $this->extract_mastery_data($statistics), 'mastery'),
                $this->build_percentage_stat_card('Versatility', $this->extract_versatility_data($statistics), 'versatility'),
            ),
            static function ($stat): bool {
                return is_array($stat) && ($stat['value'] ?? '') !== '';
            }
        );

        $sections = array();
        if (! empty($primary_stats)) {
            $sections[] = array('title' => '', 'stats' => array_values($primary_stats));
        }
        if (! empty($secondary_stats)) {
            $sections[] = array('title' => '', 'stats' => array_values($secondary_stats));
        }

        return $sections;
    }

    private function build_stat_card_data(string $label, string $value, string $subvalue, string $icon, array $tooltip = array()): array
    {
        return array(
            'label' => $label,
            'value' => $value,
            'subvalue' => $subvalue,
            'icon' => $icon,
            'tooltip' => $tooltip,
        );
    }

    private function build_percentage_stat_card(string $label, array $stat_data, string $icon): array
    {
        if (($stat_data['percent'] ?? '') === '' && ($stat_data['rating'] ?? '') === '') {
            return array();
        }

        $tooltip = array();
        if (($stat_data['percent'] ?? '') !== '') {
            $tooltip[] = array('label' => $label, 'value' => (string) $stat_data['percent']);
        }
        if (($stat_data['rating'] ?? '') !== '') {
            $tooltip[] = array('label' => __('Total Rating', 'guild-roster-importer-for-wow'), 'value' => (string) $stat_data['rating']);
        }
        if (($stat_data['extra'] ?? '') !== '') {
            $tooltip[] = array('label' => __('Bonus', 'guild-roster-importer-for-wow'), 'value' => (string) $stat_data['extra']);
        }

        return $this->build_stat_card_data($label, (string) ($stat_data['percent'] ?? ''), (string) ($stat_data['rating'] ?? ''), $icon, $tooltip);
    }

    private function render_stat_card(array $stat): string
    {
        $label = (string) ($stat['label'] ?? '');
        $value = (string) ($stat['value'] ?? '');
        if ($label === '' || $value === '') {
            return '';
        }

        $subvalue = trim((string) ($stat['subvalue'] ?? ''));
        $tooltip = is_array($stat['tooltip'] ?? null) ? $stat['tooltip'] : array();

        ob_start();
        ?>
<div class="wgrp-stat-card<?php echo ! empty($tooltip) ? ' has-tooltip' : ''; ?>">
<span class="wgrp-stat-icon" aria-hidden="true"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted internal SVG markup. ?><?php echo $this->get_stat_icon_svg((string) ($stat['icon'] ?? '')); ?></span>
<span class="wgrp-stat-body">
<span class="wgrp-stat-label"><?php echo esc_html($label); ?></span>
<span class="wgrp-stat-value"><?php echo esc_html($value); ?></span>
<?php if ($subvalue !== '') { ?><span class="wgrp-stat-subvalue"><?php echo esc_html($subvalue); ?></span><?php } ?>
</span>
<?php if (! empty($tooltip)) { ?>
<span class="wgrp-stat-tooltip" role="tooltip">
<?php foreach ($tooltip as $index => $tooltip_line) { ?>
<?php $tooltip_label = trim((string) ($tooltip_line['label'] ?? '')); ?>
<?php $tooltip_value = trim((string) ($tooltip_line['value'] ?? '')); ?>
<?php if ($tooltip_value === '') { continue; } ?>
<?php if ($tooltip_label !== '') { ?><span class="wgrp-tooltip-label"><?php echo esc_html($tooltip_label); ?></span><?php } ?>
<span class="<?php echo esc_attr($index === 0 ? 'wgrp-tooltip-value' : 'wgrp-tooltip-subvalue'); ?>"><?php echo esc_html($tooltip_value); ?></span>
<?php } ?>
</span>
<?php } ?>
</div>
        <?php

        return (string) ob_get_clean();
    }

    private function build_mythic_keystone_panel_data(array $mythic_keystone_profile, array $mythic_keystone_seasons, string $region): array
    {
        $season_payload = $this->select_current_mythic_keystone_season_payload($mythic_keystone_seasons, $mythic_keystone_profile, $region);
        $runs = $this->extract_mythic_keystone_runs(! empty($season_payload) ? $season_payload : $mythic_keystone_profile);
        if (empty($runs)) {
            $runs = $this->extract_mythic_keystone_runs($mythic_keystone_profile);
        }
        $manifest = $this->resolve_mythic_keystone_season_manifest($region, $runs, $season_payload);
        $runs_by_name = array();

        foreach ($runs as $run) {
            $normalized_name = (string) ($run['normalized_name'] ?? '');
            if ($normalized_name === '') {
                continue;
            }
            $runs_by_name[$normalized_name] = $run;
        }

        $cards = array();
        $season_title = (string) ($manifest['title'] ?? __('Current Season', 'guild-roster-importer-for-wow'));
        if (! empty($manifest['dungeons']) && is_array($manifest['dungeons'])) {
            foreach ($manifest['dungeons'] as $dungeon) {
                if (! is_array($dungeon)) {
                    continue;
                }

                $name = trim((string) ($dungeon['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $normalized_name = $this->normalize_mythic_dungeon_key($name);
                $run = $runs_by_name[$normalized_name] ?? array();
                $level = (int) ($run['level'] ?? 0);
                $rating = (int) ($run['rating'] ?? 0);
                $duration = trim((string) ($run['duration'] ?? ''));
                $background_url = $this->build_mythic_dungeon_background_url($region, (string) ($dungeon['slug'] ?? ''));

                $cards[] = array(
                    'name' => $name,
                    'background_url' => $background_url,
                    'level' => $level > 0 ? (string) $level : '-',
                    'duration' => $duration !== '' ? $duration : __('No run yet', 'guild-roster-importer-for-wow'),
                    /* translators: %s: Mythic+ dungeon rating value. */
                    'rating' => $rating > 0 ? sprintf(__('Rating: %s', 'guild-roster-importer-for-wow'), number_format_i18n($rating)) : __('Rating: -', 'guild-roster-importer-for-wow'),
                    'active' => $level > 0 || $rating > 0,
                );
            }
        } elseif (! empty($runs)) {
            foreach ($runs as $run) {
                $cards[] = array(
                    'name' => (string) ($run['name'] ?? ''),
                    'background_url' => '',
                    'level' => (int) ($run['level'] ?? 0) > 0 ? (string) ((int) ($run['level'] ?? 0)) : '-',
                    'duration' => (string) (($run['duration'] ?? '') !== '' ? $run['duration'] : __('No run yet', 'guild-roster-importer-for-wow')),
                    /* translators: %s: Mythic+ dungeon rating value. */
                    'rating' => (int) ($run['rating'] ?? 0) > 0 ? sprintf(__('Rating: %s', 'guild-roster-importer-for-wow'), number_format_i18n((int) $run['rating'])) : __('Rating: -', 'guild-roster-importer-for-wow'),
                    'active' => ! empty($run),
                );
            }
        }

        if (empty($cards)) {
            return array();
        }

        $rating_source = ! empty($season_payload) ? $season_payload : $mythic_keystone_profile;
        $current_rating = (int) round((float) ($rating_source['current_mythic_rating']['rating'] ?? $rating_source['current_mythic_rating']['score'] ?? $mythic_keystone_profile['current_mythic_rating']['rating'] ?? $mythic_keystone_profile['current_mythic_rating']['score'] ?? 0));

        return array(
            'title' => __('Mythic Keystone Dungeons', 'guild-roster-importer-for-wow'),
            'subtitle' => $season_title,
            'score' => $current_rating,
            'cards' => $cards,
        );
    }

    private function render_mythic_keystone_panel(array $panel): string
    {
        if (empty($panel['cards']) || ! is_array($panel['cards'])) {
            return '';
        }

        ob_start();
        ?>
<section id="wgrp-mythic-plus" class="wgrp-card wgrp-mplus-card">
<div class="wgrp-mplus-head">
<div>
<h2 class="wgrp-section-title"><?php echo esc_html((string) ($panel['title'] ?? __('Mythic Keystone Dungeons', 'guild-roster-importer-for-wow'))); ?></h2>
<?php if (! empty($panel['subtitle'])) { ?><div class="wgrp-section-subtitle"><?php echo esc_html((string) $panel['subtitle']); ?></div><?php } ?>
</div>
<div class="wgrp-mplus-meta">
<?php if (! empty($panel['score'])) { ?>
<span class="wgrp-mplus-pill">
<?php /* translators: %s: Mythic+ score value. */ ?>
<?php echo esc_html(sprintf(__('Score %s', 'guild-roster-importer-for-wow'), number_format_i18n((int) $panel['score']))); ?>
</span>
<?php } ?>
</div>
</div>
<div class="wgrp-mplus-grid">
<?php foreach ($panel['cards'] as $card) { ?>
<?php $has_background = trim((string) ($card['background_url'] ?? '')) !== ''; ?>
<article class="wgrp-mplus-run<?php echo ! empty($card['active']) ? '' : ' is-inactive'; ?>">
<div class="wgrp-mplus-run-header"<?php echo $has_background ? ' style="background-image:url(\'' . esc_url((string) $card['background_url']) . '\')"' : ''; ?>>
<span class="wgrp-mplus-level"><?php echo esc_html((string) ($card['level'] ?? '0')); ?></span>
</div>
<div class="wgrp-mplus-run-body">
<div class="wgrp-mplus-run-name"><?php echo esc_html((string) ($card['name'] ?? '')); ?></div>
<div class="wgrp-mplus-run-duration"><?php echo esc_html((string) ($card['duration'] ?? '')); ?></div>
<div class="wgrp-mplus-run-rating"><?php echo esc_html((string) ($card['rating'] ?? '')); ?></div>
</div>
</article>
<?php } ?>
</div>
</section>
        <?php

        return (string) ob_get_clean();
    }

    private function resolve_mythic_keystone_season_manifest(string $region, array $runs, array $season_payload = array()): array
    {
        $manifests = $this->get_mythic_keystone_season_manifests();
        $expected_manifest = $this->get_expected_current_mythic_keystone_manifest($region);
        if (! empty($expected_manifest['dungeons']) && $this->manifest_has_local_zone_images($expected_manifest)) {
            return $expected_manifest;
        }

        $season_name = trim((string) ($season_payload['season']['name'] ?? $season_payload['name'] ?? ''));
        if ($season_name !== '') {
            $normalized_season_name = strtolower($season_name);
            foreach ($manifests as $manifest) {
                $manifest_title = strtolower((string) ($manifest['title'] ?? ''));
                if ($manifest_title !== '' && $manifest_title === $normalized_season_name) {
                    return $manifest;
                }
            }
        }

        $best_match_key = '';
        $best_match_score = 0;

        foreach ($manifests as $key => $manifest) {
            if (empty($manifest['dungeons']) || ! is_array($manifest['dungeons'])) {
                continue;
            }

            $score = 0;
            $known_names = array();
            foreach ($manifest['dungeons'] as $dungeon) {
                if (! is_array($dungeon) || empty($dungeon['name'])) {
                    continue;
                }
                $known_names[$this->normalize_mythic_dungeon_key((string) $dungeon['name'])] = true;
            }

            foreach ($runs as $run) {
                $normalized_name = (string) ($run['normalized_name'] ?? '');
                if ($normalized_name !== '' && isset($known_names[$normalized_name])) {
                    $score++;
                }
            }

            if ($score > $best_match_score) {
                $best_match_score = $score;
                $best_match_key = $key;
            }
        }

        if ($best_match_key !== '' && isset($manifests[$best_match_key])) {
            return $manifests[$best_match_key];
        }

        return $expected_manifest;
    }

    private function manifest_has_local_zone_images(array $manifest): bool
    {
        $dungeons = is_array($manifest['dungeons'] ?? null) ? $manifest['dungeons'] : array();
        if (empty($dungeons)) {
            return false;
        }

        foreach ($dungeons as $dungeon) {
            if (! is_array($dungeon)) {
                return false;
            }

            if ($this->build_mythic_dungeon_background_url('', (string) ($dungeon['slug'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function get_expected_current_mythic_keystone_manifest(string $region): array
    {
        $manifests = $this->get_mythic_keystone_season_manifests();
        $region = strtolower(trim($region));
        $today = gmdate('Y-m-d');
        $fallback_manifest = array();
        $current_manifest = array();
        $current_start = '';

        foreach ($manifests as $manifest) {
            if (! is_array($manifest)) {
                continue;
            }

            if (empty($fallback_manifest)) {
                $fallback_manifest = $manifest;
            }

            $starts = is_array($manifest['starts'] ?? null) ? $manifest['starts'] : array();
            $start_date = (string) ($starts[$region] ?? $starts['default'] ?? '');
            if ($start_date === '') {
                continue;
            }

            if ($today >= $start_date) {
                if ($current_start === '' || $start_date > $current_start) {
                    $current_start = $start_date;
                    $current_manifest = $manifest;
                }
            }
        }

        if (! empty($current_manifest)) {
            return $current_manifest;
        }

        return $fallback_manifest;
    }

    private function select_current_mythic_keystone_season_payload(array $seasons, array $mythic_keystone_profile, string $region): array
    {
        $expected_manifest = $this->get_expected_current_mythic_keystone_manifest($region);
        $expected_title = strtolower((string) ($expected_manifest['title'] ?? ''));
        $current_rating = (int) round((float) ($mythic_keystone_profile['current_mythic_rating']['rating'] ?? $mythic_keystone_profile['current_mythic_rating']['score'] ?? 0));
        $best_payload = array();
        $best_id = -1;
        $closest_rating_delta = PHP_INT_MAX;

        foreach ($seasons as $season) {
            if (! is_array($season)) {
                continue;
            }

            $rating = (int) round((float) ($season['current_mythic_rating']['rating'] ?? $season['current_mythic_rating']['score'] ?? 0));
            $season_id = absint($season['_guilroim_season_id'] ?? $season['season']['id'] ?? $season['id'] ?? 0);
            $season_name = strtolower(trim((string) ($season['season']['name'] ?? $season['name'] ?? '')));

            if ($expected_title !== '' && $season_name !== '' && $season_name === $expected_title) {
                return $season;
            }

            if ($current_rating > 0) {
                $rating_delta = abs($rating - $current_rating);
                if ($rating_delta < $closest_rating_delta || ($rating_delta === $closest_rating_delta && $season_id > $best_id)) {
                    $best_payload = $season;
                    $best_id = $season_id;
                    $closest_rating_delta = $rating_delta;
                }
                continue;
            }

            if ($season_id > $best_id) {
                $best_payload = $season;
                $best_id = $season_id;
            }
        }

        return $best_payload;
    }

    private function fetch_character_mythic_keystone_seasons(string $summary_url, string $profile_ns, string $locale, string $token): array
    {
        $season_ids = $this->fetch_mythic_keystone_season_ids($locale, $token);
        if (empty($season_ids)) {
            return array();
        }

        $seasons = array();
        foreach ($season_ids as $season_id) {
            $season_url = $summary_url . '/mythic-keystone-profile/season/' . (int) $season_id;
            $season = $this->api->api_get_json($season_url, $profile_ns, $locale, $token);
            if (! is_wp_error($season) && is_array($season)) {
                $season['_guilroim_season_id'] = $season_id;
                $seasons[] = $season;
            }
        }

        return $seasons;
    }

    private function fetch_mythic_keystone_season_ids(string $locale, string $token): array
    {
        $cache_key = 'guilroim_mplus_season_ids_' . md5($locale . '|v1');
        $cached = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        $ids = array();
        foreach (array('us', 'eu', 'kr', 'tw') as $region) {
            $index_url = sprintf('https://%s.api.blizzard.com/data/wow/mythic-keystone/season/index', rawurlencode($region));
            $index = $this->api->api_get_json($index_url, 'dynamic-' . $region, $locale, $token);
            if (is_wp_error($index) || ! is_array($index)) {
                continue;
            }

            $seasons = $index['seasons'] ?? array();
            if (! is_array($seasons)) {
                continue;
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

            if (! empty($ids)) {
                break;
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

    private function get_mythic_keystone_season_manifests(): array
    {
        return array(
            'the-war-within-season-3' => array(
                'title' => 'The War Within Season 3',
                'starts' => array(
                    'default' => '2025-09-16',
                    'eu' => '2025-09-17',
                    'kr' => '2025-09-18',
                    'tw' => '2025-09-18',
                ),
                'dungeons' => array(
                    array('name' => 'Halls of Atonement', 'slug' => 'halls-of-atonement'),
                    array('name' => "Tazavesh: Streets of Wonder", 'slug' => 'tazavesh-the-veiled-market'),
                    array('name' => "Tazavesh: So'leah's Gambit", 'slug' => 'tazavesh-the-veiled-market'),
                    array('name' => 'Priory of the Sacred Flame', 'slug' => 'priory-of-the-sacred-flame'),
                    array('name' => 'Ara-Kara, City of Echoes', 'slug' => 'ara-kara-city-of-echoes'),
                    array('name' => 'The Dawnbreaker', 'slug' => 'the-dawnbreaker'),
                    array('name' => 'Operation: Floodgate', 'slug' => 'operation-floodgate'),
                    array('name' => "Eco-Dome Al'dani", 'slug' => 'eco-dome-aldani'),
                ),
            ),
            'midnight-season-1' => array(
                'title' => 'Midnight Season 1',
                'starts' => array(
                    'default' => '2026-03-24',
                    'eu' => '2026-03-25',
                    'kr' => '2026-03-26',
                    'tw' => '2026-03-26',
                ),
                'dungeons' => array(
                    array('name' => "Magisters' Terrace", 'slug' => 'magisters-terrace'),
                    array('name' => 'Maisara Caverns', 'slug' => 'maisara-caverns'),
                    array('name' => 'Nexus-Point Xenas', 'slug' => 'nexus-point-xenas'),
                    array('name' => 'Windrunner Spire', 'slug' => 'windrunner-spire'),
                    array('name' => "Algeth'ar Academy", 'slug' => 'algethar-academy'),
                    array('name' => 'Pit of Saron', 'slug' => 'pit-of-saron'),
                    array('name' => 'Seat of the Triumvirate', 'slug' => 'seat-of-the-triumvirate'),
                    array('name' => 'Skyreach', 'slug' => 'skyreach'),
                ),
            ),
        );
    }

    private function extract_mythic_keystone_runs(array $mythic_keystone_profile): array
    {
        $run_sources = array(
            $mythic_keystone_profile['best_runs'] ?? array(),
            $mythic_keystone_profile['season_best_runs'] ?? array(),
            $mythic_keystone_profile['current_season_best_runs'] ?? array(),
            $mythic_keystone_profile['best_runs_this_season'] ?? array(),
            $mythic_keystone_profile['current_period']['best_runs'] ?? array(),
        );

        $runs_by_name = array();
        foreach ($run_sources as $run_source) {
            if (! is_array($run_source)) {
                continue;
            }

            foreach ($run_source as $run) {
                if (! is_array($run)) {
                    continue;
                }

                $parsed_run = $this->parse_mythic_keystone_run($run);
                $normalized_name = (string) ($parsed_run['normalized_name'] ?? '');
                if ($normalized_name === '') {
                    continue;
                }

                $existing = $runs_by_name[$normalized_name] ?? array();
                if (empty($existing) || $this->compare_mythic_keystone_runs($parsed_run, $existing) > 0) {
                    $runs_by_name[$normalized_name] = $parsed_run;
                }
            }
        }

        return array_values($runs_by_name);
    }

    private function parse_mythic_keystone_run(array $run): array
    {
        $name = trim((string) ($run['dungeon']['name'] ?? $run['map']['name'] ?? $run['instance']['name'] ?? $run['journal_instance']['name'] ?? $run['name'] ?? ''));
        $normalized_name = $this->normalize_mythic_dungeon_key($name);
        if ($normalized_name === '') {
            return array();
        }

        $level = absint($run['keystone_level'] ?? $run['mythic_level'] ?? $run['level'] ?? 0);
        $rating = (float) ($run['mythic_rating']['rating'] ?? $run['map_rating']['rating'] ?? $run['dungeon_score'] ?? $run['score'] ?? 0);

        return array(
            'name' => $name,
            'normalized_name' => $normalized_name,
            'level' => $level,
            'rating' => (int) round($rating),
            'duration' => $this->format_mythic_keystone_duration($run['clear_time_ms'] ?? $run['duration'] ?? $run['run_duration'] ?? ''),
        );
    }

    private function compare_mythic_keystone_runs(array $candidate, array $existing): int
    {
        $candidate_rating = (int) ($candidate['rating'] ?? 0);
        $existing_rating = (int) ($existing['rating'] ?? 0);
        if ($candidate_rating !== $existing_rating) {
            return $candidate_rating <=> $existing_rating;
        }

        $candidate_level = (int) ($candidate['level'] ?? 0);
        $existing_level = (int) ($existing['level'] ?? 0);
        if ($candidate_level !== $existing_level) {
            return $candidate_level <=> $existing_level;
        }

        return strcmp((string) ($existing['duration'] ?? ''), (string) ($candidate['duration'] ?? ''));
    }

    private function format_mythic_keystone_duration($value): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed)) {
                return $trimmed;
            }
            if (is_numeric($trimmed)) {
                $value = (float) $trimmed;
            } else {
                return $trimmed;
            }
        }

        if (! is_numeric($value)) {
            return '';
        }

        $seconds = (float) $value;
        if ($seconds > 100000) {
            $seconds = $seconds / 1000;
        }

        $seconds = (int) round($seconds);
        if ($seconds <= 0) {
            return '';
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $remaining_seconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining_seconds);
    }

    private function normalize_mythic_dungeon_name(string $name): string
    {
        $normalized = trim(strtolower($name));
        $normalized = str_replace(array('â€™', '`'), "'", $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return is_string($normalized) ? $normalized : '';
    }

    private function build_mythic_dungeon_background_url(string $region, string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $fallback_slug_map = array(
            'voidstorm' => 'nexus-point-xenas',
            'magisters-terrace-tbc' => 'magisters-terrace',
            'magisters-terrace' => 'magisters-terrace',
            'magister-s-terrace' => 'magisters-terrace',
            'maisara-cavern' => 'maisara-caverns',
            'maisaras-caverns' => 'maisara-caverns',
            'maisaara-caverns' => 'maisara-caverns',
            'nexus-point-xenas' => 'nexus-point-xenas',
            'nexus-point-xena' => 'nexus-point-xenas',
            'windrunner-spire' => 'windrunner-spire',
            'algethar-academy' => 'algethar-academy',
            'pit-of-saron' => 'pit-of-saron',
            'seat-of-triumvirate' => 'seat-of-the-triumvirate',
            'seat-of-the-triumvirate' => 'seat-of-the-triumvirate',
            'skyreach' => 'skyreach',
        );
        if (isset($fallback_slug_map[$slug])) {
            $slug = (string) $fallback_slug_map[$slug];
        }

        return $this->get_local_zone_image_url($slug);
    }

    private function normalize_mythic_dungeon_key(string $name): string
    {
        $normalized = trim(strtolower($name));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);

        return is_string($normalized) ? trim($normalized) : '';
    }

    private function build_raid_progression_panel_data(array $raid_encounters, string $region): array
    {
        $expansions = $raid_encounters['expansions'] ?? array();
        if (! is_array($expansions) || empty($expansions)) {
            return array();
        }

        $current_expansion = $this->select_current_raid_expansion($expansions);
        if (empty($current_expansion)) {
            return array();
        }

        $instances = $current_expansion['instances'] ?? $current_expansion['raids'] ?? array();
        if (! is_array($instances) || empty($instances)) {
            return array();
        }

        $cards = array();
        foreach ($instances as $instance) {
            if (! is_array($instance)) {
                continue;
            }

            $card = $this->build_raid_progression_card($instance, $region);
            if (! empty($card)) {
                $cards[] = $card;
            }
        }

        if (empty($cards)) {
            return array();
        }

        return array(
            'title' => __('Raid Progression', 'guild-roster-importer-for-wow'),
            'subtitle' => (string) ($current_expansion['name'] ?? __('Current Expansion', 'guild-roster-importer-for-wow')),
            'cards' => $cards,
        );
    }

    private function render_raid_progression_panel(array $panel): string
    {
        if (empty($panel['cards']) || ! is_array($panel['cards'])) {
            return '';
        }

        ob_start();
        ?>
<section id="wgrp-raid-progress" class="wgrp-card wgrp-raid-card">
<div class="wgrp-section-head">
<div>
<h2 class="wgrp-section-title"><?php echo esc_html((string) ($panel['title'] ?? __('Raid Progression', 'guild-roster-importer-for-wow'))); ?></h2>
<?php if (! empty($panel['subtitle'])) { ?><div class="wgrp-section-subtitle"><?php echo esc_html((string) $panel['subtitle']); ?></div><?php } ?>
</div>
</div>
<div class="wgrp-raid-grid">
<?php foreach ($panel['cards'] as $card) { ?>
<?php $has_background = trim((string) ($card['background_url'] ?? '')) !== ''; ?>
<article class="wgrp-raid-run">
<div class="wgrp-raid-run-header"<?php echo $has_background ? ' style="background-image:url(\'' . esc_url((string) $card['background_url']) . '\')"' : ''; ?>></div>
<div class="wgrp-raid-run-body">
<div>
<div class="wgrp-raid-run-name"><?php echo esc_html((string) ($card['name'] ?? '')); ?></div>
<?php if (! empty($card['level_label'])) { ?><div class="wgrp-raid-run-level"><?php echo esc_html((string) $card['level_label']); ?></div><?php } ?>
</div>
<div class="wgrp-raid-difficulty-list">
<?php foreach (($card['difficulties'] ?? array()) as $difficulty) { ?>
<?php
    $progress = (int) ($difficulty['progress'] ?? 0);
    $total = max(1, (int) ($difficulty['total'] ?? 0));
    $percentage = max(0, min(100, (int) round(($progress / $total) * 100)));
    $fill_class = '';
    if ($progress > 0) {
        $ratio = $total > 0 ? ($progress / $total) : 0;
        if ($progress >= $total) {
            $fill_class = ' is-complete';
        } elseif ($ratio < 0.25) {
            $fill_class = ' is-low-progress';
        } else {
            $fill_class = ' is-progress';
        }
    }
?>
<div class="wgrp-raid-difficulty-row">
<div class="wgrp-raid-difficulty-label"><?php echo esc_html((string) ($difficulty['label'] ?? '')); ?></div>
<div class="wgrp-raid-progress">
<span class="wgrp-raid-progress-fill<?php echo esc_attr($fill_class); ?>" style="width:<?php echo esc_attr((string) $percentage); ?>%;"></span>
<span class="wgrp-raid-progress-text"><?php echo esc_html((string) ($difficulty['text'] ?? '0/0')); ?></span>
</div>
</div>
<?php } ?>
</div>
</div>
</article>
<?php } ?>
</div>
</section>
        <?php

        return (string) ob_get_clean();
    }

    private function build_professions_panel_data(array $professions): array
    {
        $primary_professions = $professions['primaries'] ?? $professions['primary_professions'] ?? $professions['professions'] ?? array();
        if (! is_array($primary_professions) || empty($primary_professions)) {
            return array();
        }

        $cards = array();
        foreach ($primary_professions as $profession) {
            if (! is_array($profession)) {
                continue;
            }

            $card = $this->build_profession_card($profession);
            if (! empty($card)) {
                $cards[] = $card;
            }
        }

        if (empty($cards)) {
            return array();
        }

        return array(
            'title' => __('Professions', 'guild-roster-importer-for-wow'),
            'subtitle' => __('Primary profession skill by expansion', 'guild-roster-importer-for-wow'),
            'cards' => $cards,
        );
    }

    private function render_professions_panel(array $panel): string
    {
        if (empty($panel['cards']) || ! is_array($panel['cards'])) {
            return '';
        }

        ob_start();
        ?>
<section id="wgrp-professions" class="wgrp-card wgrp-professions-card">
<div class="wgrp-section-head">
<div>
<h2 class="wgrp-section-title"><?php echo esc_html((string) ($panel['title'] ?? __('Professions', 'guild-roster-importer-for-wow'))); ?></h2>
<?php if (! empty($panel['subtitle'])) { ?><div class="wgrp-section-subtitle"><?php echo esc_html((string) $panel['subtitle']); ?></div><?php } ?>
</div>
</div>
<div class="wgrp-professions-grid">
<?php foreach ($panel['cards'] as $card) { ?>
<article class="wgrp-profession-card">
<div class="wgrp-profession-head">
<div class="wgrp-profession-badge"><?php if (! empty($card['icon_url'])) { ?><img src="<?php echo esc_url((string) $card['icon_url']); ?>" alt="<?php echo esc_attr((string) ($card['name'] ?? '')); ?>" loading="lazy" width="52" height="52" /><?php } else { echo esc_html((string) ($card['badge'] ?? '')); } ?></div>
<div>
<div class="wgrp-profession-name"><?php echo esc_html((string) ($card['name'] ?? '')); ?></div>
<div class="wgrp-profession-kind"><?php esc_html_e('Primary Profession', 'guild-roster-importer-for-wow'); ?></div>
</div>
<div class="wgrp-profession-total">
<div class="wgrp-profession-total-value"><?php echo esc_html((string) ($card['current_skill'] ?? '0')); ?></div>
<div class="wgrp-profession-total-label"><?php esc_html_e('Current Skill', 'guild-roster-importer-for-wow'); ?></div>
</div>
</div>
<div class="wgrp-profession-tiers">
<?php foreach (($card['tiers'] ?? array()) as $index => $tier) { ?>
<?php
    $is_current = $index === 0;
    $progress = max(0, (int) ($tier['progress'] ?? 0));
    $total = max(1, (int) ($tier['total'] ?? 0));
    $percentage = max(0, min(100, (int) round(($progress / $total) * 100)));
    $fill_class = '';
    if ($progress > 0) {
        $ratio = $total > 0 ? ($progress / $total) : 0;
        if ($progress >= $total) {
            $fill_class = ' is-complete';
        } elseif ($ratio < 0.25) {
            $fill_class = ' is-low-progress';
        } else {
            $fill_class = ' is-progress';
        }
    }
    $row_class = 'wgrp-profession-tier' . ($is_current ? ' is-current' : '') . ($progress <= 0 ? ' is-zero' : '');
?>
<div class="<?php echo esc_attr($row_class); ?>">
<div class="wgrp-profession-tier-name"><?php echo esc_html((string) ($tier['name'] ?? '')); ?></div>
<div class="wgrp-profession-progress">
<span class="wgrp-profession-progress-fill<?php echo esc_attr($fill_class); ?>" style="width:<?php echo esc_attr((string) $percentage); ?>%;"></span>
<span class="wgrp-profession-progress-text"><?php echo esc_html((string) ($tier['text'] ?? '0 / 0')); ?></span>
</div>
</div>
<?php } ?>
</div>
</article>
<?php } ?>
</div>
</section>
        <?php

        return (string) ob_get_clean();
    }

    private function build_profession_card(array $profession): array
    {
        $name = trim((string) ($profession['profession']['name'] ?? $profession['profession_name'] ?? $profession['name'] ?? ''));
        if ($name === '') {
            return array();
        }

        $tiers = $this->extract_profession_tiers($profession);
        if (empty($tiers)) {
            return array();
        }

        $current_skill = (int) ($profession['skill_points'] ?? $profession['current_skill'] ?? $tiers[0]['progress'] ?? 0);
        if ($current_skill <= 0) {
            $current_skill = (int) ($tiers[0]['progress'] ?? 0);
        }

        return array(
            'name' => $name,
            'badge' => $this->get_profession_badge_text($name),
            'icon_url' => $this->get_profession_icon_url($name),
            'current_skill' => $current_skill,
            'tiers' => $tiers,
        );
    }

    private function extract_profession_tiers(array $profession): array
    {
        $tiers = $profession['tiers'] ?? $profession['skill_tiers'] ?? $profession['categories'] ?? array();
        if (! is_array($tiers) || empty($tiers)) {
            return array();
        }

        $rows = array();
        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $name = trim((string) ($tier['tier']['name'] ?? $tier['skill_tier']['name'] ?? $tier['name'] ?? ''));
            $display_name = $this->normalize_profession_tier_name($name, $profession);
            if ($display_name === '') {
                continue;
            }

            $progress = max(0, (int) ($tier['skill_points'] ?? $tier['value'] ?? $tier['points'] ?? 0));
            $total = max(1, $progress, (int) ($tier['max_skill_points'] ?? $tier['max_value'] ?? $tier['max'] ?? 0));

            $rows[] = array(
                'name' => $display_name,
                'progress' => $progress,
                'total' => $total,
                'text' => $progress . ' / ' . $total,
                'sort' => $this->get_profession_expansion_sort($display_name),
            );
        }

        usort($rows, static function (array $a, array $b): int {
            return ((int) ($b['sort'] ?? 0)) <=> ((int) ($a['sort'] ?? 0));
        });

        return array_values(array_map(static function (array $row): array {
            unset($row['sort']);
            return $row;
        }, $rows));
    }

    private function get_profession_badge_text(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', strtoupper(trim($name))) ?: array();
        $letters = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $letters .= substr($word, 0, 1);
            if (strlen($letters) >= 2) {
                break;
            }
        }

        if ($letters === '') {
            $letters = substr(strtoupper($name), 0, 2);
        }

        return substr($letters, 0, 2);
    }

    private function get_profession_icon_url(string $name): string
    {
        $normalized = strtolower(trim($name));
        $map = array(
            'alchemy' => 'ui_profession_alchemy.jpg',
            'blacksmithing' => 'ui_profession_blacksmithing.jpg',
            'enchanting' => 'ui_profession_enchanting.jpg',
            'engineering' => 'ui_profession_engineering.jpg',
            'herbalism' => 'ui_profession_herbalism.jpg',
            'inscription' => 'ui_profession_inscription.jpg',
            'jewelcrafting' => 'ui_profession_jewelcrafting.jpg',
            'leatherworking' => 'ui_profession_leatherworking.jpg',
            'mining' => 'ui_profession_mining.jpg',
            'skinning' => 'ui_profession_skinning.jpg',
            'tailoring' => 'ui_profession_tailoring.jpg',
        );

        if (! isset($map[$normalized])) {
            return '';
        }

        return GUILROIM_PLUGIN_URL . 'assets/images/professions/' . rawurlencode($map[$normalized]);
    }

    private function get_header_meta_icon_svg(string $type): string
    {
        $icons = array(
            'achievement' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M51.492 3.677c-5.941 1.654-14.886 3.906-19.494 3.906-4.611 0-13.553-2.252-19.495-3.906-2.937-.815-5.875 1.255-5.875 4.144v34.684c0 1.336.657 2.597 1.778 3.415l20.792 15.176a4.76 4.76 0 0 0 2.8.904c.989 0 1.981-.3 2.805-.904L55.594 45.92c1.122-.818 1.778-2.08 1.778-3.415V7.823c-.002-2.888-2.942-4.961-5.88-4.146"></path></svg>',
            'ilvl' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="m13.593 18.962-6.729 7.035c-.135.142-.061.383.128.417l3.932.683a.23.23 0 0 0 .205-.068l3.428-3.584 7.684 7.93 3.927-4.106.346-.362 3.725-3.896-7.684-7.928 3.646-3.814a.25.25 0 0 0 .066-.213l-.654-4.112a.232.232 0 0 0-.398-.133l-6.853 7.167L7.485 4.401V.428A.42.42 0 0 0 7.075 0H.791a.42.42 0 0 0-.409.428v6.571c0 .236.183.427.409.427h3.512zm27.505 15.599-3.8 3.972-.24.251-3.958 4.139 18.652 19.411L61.562 64l-1.671-9.882zM63.209.017h-6.283a.42.42 0 0 0-.409.428v3.672L45.483 13.83l-6.728-7.034c-.135-.143-.366-.065-.397.132l-.654 4.111a.25.25 0 0 0 .066.214l3.428 3.585L4.002 53.726 2.408 63.983l9.451-1.748 37.336-39.036 3.646 3.812a.23.23 0 0 0 .205.069l3.931-.684c.188-.031.263-.274.128-.415l-6.854-7.166L59.41 7.442h3.799a.42.42 0 0 0 .409-.428V.444a.42.42 0 0 0-.409-.427"></path></svg>',
            'mplus' => '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M12.5 91.2h75v9.8h-75zM12.5.5h75v9.8h-75zM87.3 20.3v-4.6H12.6v4.6c.1 12.6 7.5 24.2 19 30.4C20.1 57 12.7 68.6 12.7 81.2v4.6h74.6v-4.6c-.1-12.6-7.5-24.2-19-30.4 11.6-6.3 19-18 19-30.5M50 46.2c-13.3 0-24.7-9.1-27.1-21.3h54.3C74.7 37.2 63.3 46.2 50 46.2M22.8 76.6C25.3 64.4 36.7 55.3 50 55.3s24.7 9.1 27.1 21.3z"></path></svg>',
        );

        return $icons[$type] ?? '';
    }

    private function get_profession_expansion_sort(string $name): int
    {
        $normalized = strtolower(trim($name));
        $map = array(
            'midnight' => 120,
            'khaz algar' => 110,
            'the war within' => 110,
            'dragonflight' => 100,
            'shadowlands' => 90,
            'zandalari' => 80,
            'battle for azeroth' => 80,
            'legion' => 70,
            'draenor' => 60,
            'warlords of draenor' => 60,
            'pandaria' => 50,
            'mists of pandaria' => 50,
            'cataclysm' => 40,
            'northrend' => 30,
            'outland' => 20,
            'classic' => 10,
        );

        return (int) ($map[$normalized] ?? 0);
    }

    private function normalize_profession_tier_name(string $name, array $profession): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $profession_name = trim((string) ($profession['profession']['name'] ?? $profession['profession_name'] ?? $profession['name'] ?? ''));
        if ($profession_name !== '') {
            $pattern = '/\s+' . preg_quote($profession_name, '/') . '$/i';
            $name = preg_replace($pattern, '', $name) ?? $name;
        }

        $normalized = strtolower(trim($name));
        $map = array(
            'classic' => 'Classic',
            'outland' => 'Outland',
            'northrend' => 'Northrend',
            'cataclysm' => 'Cataclysm',
            'pandaria' => 'Mists of Pandaria',
            'mists of pandaria' => 'Mists of Pandaria',
            'draenor' => 'Warlords of Draenor',
            'warlords of draenor' => 'Warlords of Draenor',
            'legion' => 'Legion',
            'kul tiran' => 'Kul Tiran',
            'zandalari' => 'Zandalari',
            'battle for azeroth' => 'Battle for Azeroth',
            'shadowlands' => 'Shadowlands',
            'dragon isles' => 'Dragonflight',
            'dragonflight' => 'Dragonflight',
            'khaz algar' => 'Khaz Algar',
            'the war within' => 'Khaz Algar',
            'midnight' => 'Midnight',
        );

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return $name;
    }

    private function select_current_raid_expansion(array $expansions): array
    {
        usort($expansions, function (array $a, array $b): int {
            return ((int) ($b['expansion']['id'] ?? $b['id'] ?? 0)) <=> ((int) ($a['expansion']['id'] ?? $a['id'] ?? 0));
        });

        foreach ($expansions as $expansion) {
            if (! is_array($expansion)) {
                continue;
            }

            $instances = $expansion['instances'] ?? $expansion['raids'] ?? array();
            if (is_array($instances) && ! empty($instances)) {
                return array(
                    'name' => (string) ($expansion['expansion']['name'] ?? $expansion['name'] ?? ''),
                    'instances' => $instances,
                );
            }
        }

        return array();
    }

    private function build_raid_progression_card(array $instance, string $region): array
    {
        $name = trim((string) ($instance['instance']['name'] ?? $instance['raid']['name'] ?? $instance['name'] ?? ''));
        if ($name === '') {
            return array();
        }

        $encounters = $instance['modes'] ?? $instance['difficulties'] ?? $instance['encounters'] ?? array();
        $boss_total = max(1, $this->resolve_raid_boss_total($instance));

        $difficulty_map = array(
            'lfr' => __('LFR', 'guild-roster-importer-for-wow'),
            'normal' => __('Normal', 'guild-roster-importer-for-wow'),
            'heroic' => __('Heroic', 'guild-roster-importer-for-wow'),
            'mythic' => __('Mythic', 'guild-roster-importer-for-wow'),
        );

        $difficulty_progress = array();
        foreach ($difficulty_map as $difficulty_key => $difficulty_label) {
            $difficulty_progress[$difficulty_key] = array(
                'label' => $difficulty_label,
                'progress' => 0,
                'total' => $boss_total,
                'text' => '0/' . $boss_total,
            );
        }

        if (is_array($encounters)) {
            foreach ($encounters as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $difficulty_key = strtolower(trim((string) ($entry['difficulty']['type'] ?? $entry['difficulty']['name'] ?? $entry['type'] ?? $entry['name'] ?? '')));
                if (str_contains($difficulty_key, 'raid_finder') || str_contains($difficulty_key, 'raid finder')) {
                    $difficulty_key = 'lfr';
                }
                if (! isset($difficulty_progress[$difficulty_key])) {
                    continue;
                }

                $entry_total = $this->extract_raid_total_count($entry);
                if ($entry_total > 0) {
                    $boss_total = max($boss_total, $entry_total);
                }

                $kills = min($boss_total, $this->extract_raid_kill_count($entry));
                $difficulty_progress[$difficulty_key]['progress'] = $kills;
                $difficulty_progress[$difficulty_key]['total'] = $boss_total;
                $difficulty_progress[$difficulty_key]['text'] = $kills . '/' . $boss_total;
            }

            foreach ($difficulty_progress as $difficulty_key => $progress) {
                $kills = min($boss_total, (int) $progress['progress']);
                $difficulty_progress[$difficulty_key]['total'] = $boss_total;
                $difficulty_progress[$difficulty_key]['text'] = $kills . '/' . $boss_total;
            }
        }

        $instance_id = (int) ($instance['instance']['id'] ?? $instance['raid']['id'] ?? $instance['id'] ?? 0);
        $slug = $this->get_raid_background_slug($instance_id, $name);

        return array(
            'name' => $name,
            'level_label' => $this->build_raid_level_label($instance),
            'background_url' => $this->build_raid_background_url($region, $slug),
            'difficulties' => array_values($difficulty_progress),
        );
    }

    private function resolve_raid_boss_total(array $instance): int
    {
        if (! empty($instance['total_bosses']) && is_numeric($instance['total_bosses'])) {
            return (int) $instance['total_bosses'];
        }

        $modes = $instance['modes'] ?? $instance['difficulties'] ?? array();
        if (is_array($modes)) {
            $best_total = 0;
            foreach ($modes as $mode) {
                if (! is_array($mode)) {
                    continue;
                }

                $best_total = max($best_total, $this->extract_raid_total_count($mode));
            }

            if ($best_total > 0) {
                return $best_total;
            }
        }

        $encounter_ids = array();
        foreach (array('bosses', 'encounters', 'modes', 'difficulties') as $key) {
            $this->collect_raid_encounter_ids($instance[$key] ?? array(), $encounter_ids);
        }

        return count($encounter_ids);
    }

    private function extract_raid_kill_count(array $entry): int
    {
        if (isset($entry['progress']['completed_count']) && is_numeric($entry['progress']['completed_count'])) {
            return max(0, (int) $entry['progress']['completed_count']);
        }

        foreach (array('completed_count', 'encounter_count', 'count') as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key])) {
                return max(0, (int) $entry[$key]);
            }
        }

        $total = 0;
        foreach (array('completed_encounters', 'encounters', 'bosses') as $key) {
            $encounters = $entry[$key] ?? array();
            if (! is_array($encounters)) {
                continue;
            }

            foreach ($encounters as $encounter) {
                if (! is_array($encounter)) {
                    continue;
                }

                if (isset($encounter['completed_count']) && is_numeric($encounter['completed_count'])) {
                    if ((int) $encounter['completed_count'] > 0) {
                        $total++;
                    }
                    continue;
                }

                if (! empty($encounter['completed']) || ! empty($encounter['is_completed']) || ! empty($encounter['last_kill_timestamp'])) {
                    $total++;
                }
            }
        }

        if ($total > 0) {
            return $total;
        }

        return 0;
    }

    private function extract_raid_total_count(array $entry): int
    {
        if (isset($entry['progress']['total_count']) && is_numeric($entry['progress']['total_count'])) {
            return max(0, (int) $entry['progress']['total_count']);
        }

        foreach (array('total_count', 'encounter_total', 'boss_total') as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key])) {
                return max(0, (int) $entry[$key]);
            }
        }

        return 0;
    }

    private function collect_raid_encounter_ids($value, array &$encounter_ids): void
    {
        if (! is_array($value)) {
            return;
        }

        $encounter_id = (int) ($value['encounter']['id'] ?? $value['id'] ?? 0);
        if ($encounter_id > 0) {
            $encounter_ids[$encounter_id] = true;
        }

        foreach ($value as $entry) {
            if (is_array($entry)) {
                $this->collect_raid_encounter_ids($entry, $encounter_ids);
            }
        }
    }

    private function build_raid_level_label(array $instance): string
    {
        $level = (int) ($instance['instance']['expansion']['level'] ?? $instance['level'] ?? 0);
        if ($level <= 0) {
            $expansion_name = strtolower(trim((string) ($instance['instance']['expansion']['name'] ?? '')));
            if ($expansion_name === 'the war within') {
                $level = 80;
            } elseif ($expansion_name === 'midnight') {
                $level = 90;
            }
        }

        /* translators: %d: raid level requirement. */
        return $level > 0 ? sprintf(__('Level %d', 'guild-roster-importer-for-wow'), $level) : '';
    }

    private function get_raid_background_slug(int $instance_id, string $name): string
    {
        $slug_map = array(
            1273 => 'nerubar-palace',
            1296 => 'liberation-of-undermine',
            1308 => 'manaforge-omega',
            1311 => 'the-dreamrift',
            1312 => 'the-voidspire',
            1313 => 'march-on-queldanas',
        );

        if ($instance_id > 0 && isset($slug_map[$instance_id])) {
            return $slug_map[$instance_id];
        }

        return sanitize_title($name);
    }

    private function build_raid_background_url(string $region, string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return $this->get_local_zone_image_url($slug);
    }

    private function build_specialization_sections(array $summary, array $specializations): array
    {
        $spec_entries = $specializations['specializations'] ?? $specializations['specialization_groups'] ?? array();
        if (! is_array($spec_entries) || empty($spec_entries)) {
            return array();
        }

        $active_name = strtolower(trim((string) ($summary['active_spec']['name'] ?? '')));
        $sections = array();

        foreach ($spec_entries as $index => $spec_entry) {
            if (! is_array($spec_entry)) {
                continue;
            }

            $spec_name = trim((string) ($spec_entry['specialization']['name'] ?? $spec_entry['playable_specialization']['name'] ?? $spec_entry['name'] ?? ''));
            if ($spec_name === '') {
                continue;
            }

            $loadout = $this->find_active_specialization_loadout($spec_entry);
            $spec_id = (int) ($spec_entry['specialization']['id'] ?? $spec_entry['id'] ?? 0);
            $trees = $this->build_specialization_tree_groups($spec_id, $spec_entry, $loadout);
            $groups = empty($trees) ? $this->build_specialization_talent_groups($spec_entry, $loadout) : array();
            $role_label = trim((string) ($spec_entry['specialization']['role']['name'] ?? $spec_entry['specialization']['role']['type'] ?? $spec_entry['role']['name'] ?? $spec_entry['role']['type'] ?? ''));

            $is_active = strtolower($spec_name) === $active_name;
            $is_selected = $is_active;
            if (! $is_selected && empty($sections) && $index === 0 && $active_name === '') {
                $is_selected = true;
            }

            $sections[] = array(
                'id' => $spec_id,
                'name' => $spec_name,
                'role' => $this->normalize_specialization_role_label($role_label),
                'active' => $is_selected,
                'is_current_active' => $is_active,
                'loadout_name' => $this->extract_specialization_loadout_name($loadout),
                'loadout_code' => $this->extract_specialization_loadout_code($loadout, $spec_entry),
                'trees' => $trees,
                'groups' => $groups,
            );
        }

        if (count(array_filter($sections, static function (array $section): bool {
            return ! empty($section['active']);
        })) === 0 && ! empty($sections)) {
            $sections[0]['active'] = true;
        }

        return $sections;
    }

    private function render_specialization_panel(array $sections): string
    {
        if (empty($sections)) {
            return '';
        }

        ob_start();
        ?>
<section id="wgrp-specializations" class="wgrp-card wgrp-spec-card">
<div class="wgrp-section-head">
<div>
<h2 class="wgrp-section-title"><?php esc_html_e('Specializations', 'guild-roster-importer-for-wow'); ?></h2>
<div class="wgrp-section-subtitle"><?php esc_html_e('Active and inactive spec loadouts with a compact talent-tree view when Battle.net exposes the current nodes.', 'guild-roster-importer-for-wow'); ?></div>
</div>
</div>
<div class="wgrp-spec-tabs" role="tablist" aria-label="<?php esc_attr_e('Character specializations', 'guild-roster-importer-for-wow'); ?>">
<?php foreach ($sections as $index => $section) { ?>
<?php $pane_id = 'wgrp-spec-pane-' . $index; ?>
<button type="button" class="wgrp-spec-tab<?php echo ! empty($section['active']) ? ' is-active' : ''; ?>" data-wgrp-spec-tab="<?php echo esc_attr($pane_id); ?>" role="tab" aria-selected="<?php echo ! empty($section['active']) ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($pane_id); ?>">
<span class="wgrp-spec-tab-top">
<span class="wgrp-spec-badge" aria-hidden="true"><?php if ($this->get_specialization_icon_url((int) ($section['id'] ?? 0), (string) ($section['name'] ?? '')) !== '') { ?><img src="<?php echo esc_url($this->get_specialization_icon_url((int) ($section['id'] ?? 0), (string) ($section['name'] ?? ''))); ?>" alt="" loading="lazy" /><?php } else { echo esc_html($this->get_specialization_badge_label((string) ($section['name'] ?? ''))); } ?></span>
<span class="wgrp-spec-tab-text">
<span class="wgrp-spec-tab-name"><?php echo esc_html((string) ($section['name'] ?? '')); ?></span>
<span class="wgrp-spec-tab-meta"><?php echo esc_html((string) ($section['role'] ?? __('Specialization', 'guild-roster-importer-for-wow'))); ?></span>
</span>
</span>
</button>
<?php } ?>
</div>
<?php foreach ($sections as $index => $section) { ?>
<?php $pane_id = 'wgrp-spec-pane-' . $index; ?>
<div id="<?php echo esc_attr($pane_id); ?>" class="wgrp-spec-pane<?php echo ! empty($section['active']) ? ' is-active' : ''; ?>" role="tabpanel">
<div class="wgrp-spec-pane-head">
<div>
<h3 class="wgrp-spec-pane-title"><?php echo esc_html((string) ($section['name'] ?? '')); ?></h3>
<div class="wgrp-spec-pane-meta">
<?php if (! empty($section['role'])) { ?><span class="wgrp-spec-pill"><?php echo esc_html((string) $section['role']); ?></span><?php } ?>
<?php if (! empty($section['loadout_name'])) { ?><span class="wgrp-spec-pill"><?php echo esc_html((string) $section['loadout_name']); ?></span><?php } ?>
<?php if (! empty($section['is_current_active'])) { ?><span class="wgrp-spec-pill"><?php esc_html_e('Active', 'guild-roster-importer-for-wow'); ?></span><?php } ?>
<?php if (! empty($section['loadout_code'])) { ?><button type="button" class="wgrp-copy-loadout" data-wgrp-copy-loadout="<?php echo esc_attr((string) $section['loadout_code']); ?>" data-wgrp-copy-label="<?php echo esc_attr__('Copy Loadout', 'guild-roster-importer-for-wow'); ?>" data-wgrp-copied-label="<?php echo esc_attr__('Copied', 'guild-roster-importer-for-wow'); ?>"><?php esc_html_e('Copy Loadout', 'guild-roster-importer-for-wow'); ?></button><?php } ?>
</div>
</div>
</div>
<?php if (! empty($section['trees'])) { ?>
<div class="wgrp-talent-layout wgrp-talent-layout-graph">
<?php foreach (($section['trees'] ?? array()) as $tree) { ?><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal renderer returns trusted markup. ?><?php echo $this->render_specialization_tree_group($tree); ?><?php } ?>
</div>
<?php } elseif (! empty($section['groups'])) { ?>
<div class="wgrp-talent-layout">
<?php foreach ($this->order_specialization_groups_for_layout($section['groups']) as $group) { ?>
<section class="wgrp-talent-group<?php echo (($group['slug'] ?? '') === 'hero') ? ' wgrp-talent-group-hero' : ''; ?>">
<h4 class="wgrp-talent-group-title"><?php echo esc_html((string) ($group['title'] ?? __('Talents', 'guild-roster-importer-for-wow'))); ?></h4>
<div class="wgrp-talent-tree">
<?php foreach (($group['rows'] ?? array()) as $items) { ?>
<div class="wgrp-talent-row">
<div class="wgrp-talent-row-items">
<?php foreach ($items as $item) { ?>
<span class="wgrp-talent-chip"><span class="wgrp-talent-chip-col"><?php echo esc_html((string) ($item['column'] ?? '')); ?></span><?php echo esc_html((string) ($item['label'] ?? '')); ?></span>
<?php } ?>
</div>
</div>
<?php } ?>
</div>
</section>
<?php } ?>
</div>
<?php } else { ?>
<div class="wgrp-talent-empty"><?php esc_html_e('Battle.net did not expose a current talent-tree payload for this specialization. The spec tab is still rendered so players can switch between known specs without breaking the profile layout.', 'guild-roster-importer-for-wow'); ?></div>
<?php } ?>
</div>
<?php } ?>
</section>
        <?php

        return (string) ob_get_clean();
    }

    private function find_active_specialization_loadout(array $spec_entry): array
    {
        $loadouts = $spec_entry['loadouts'] ?? $spec_entry['talent_loadouts'] ?? array();
        if (is_array($loadouts)) {
            foreach ($loadouts as $loadout) {
                if (! is_array($loadout)) {
                    continue;
                }
                if (! empty($loadout['is_active']) || ! empty($loadout['selected']) || ! empty($loadout['active'])) {
                    return $loadout;
                }
            }

            foreach ($loadouts as $loadout) {
                if (is_array($loadout)) {
                    return $loadout;
                }
            }
        }

        return array();
    }

    private function build_specialization_tree_groups(int $spec_id, array $spec_entry, array $loadout): array
    {
        if ($spec_id <= 0) {
            return array();
        }

        $class_id = $this->get_class_id_for_spec($spec_id);
        if ($class_id <= 0) {
            return array();
        }

        $hero_tree_id = $this->resolve_hero_tree_id($spec_id, $spec_entry, $loadout, $class_id);
        $definitions = array(
            array(
                'slug' => 'class',
                'title' => __('Class Talents', 'guild-roster-importer-for-wow'),
                'tree_id' => $class_id,
                'selected_entries' => $this->get_selected_talent_entries($loadout, $spec_entry, 'selected_class_talents'),
            ),
            array(
                'slug' => 'hero',
                'title' => __('Hero Talents', 'guild-roster-importer-for-wow'),
                'tree_id' => $hero_tree_id,
                'selected_entries' => $this->get_selected_talent_entries($loadout, $spec_entry, 'selected_hero_talents'),
            ),
            array(
                'slug' => 'spec',
                'title' => __('Spec Talents', 'guild-roster-importer-for-wow'),
                'tree_id' => $spec_id,
                'selected_entries' => $this->get_selected_talent_entries($loadout, $spec_entry, 'selected_spec_talents'),
            ),
        );

        $groups = array();
        foreach ($definitions as $definition) {
            $tree_id = (int) ($definition['tree_id'] ?? 0);
            if ($tree_id <= 0) {
                continue;
            }

            $group = $this->build_specialization_tree_group_data(
                $tree_id,
                (string) ($definition['slug'] ?? ''),
                (string) ($definition['title'] ?? ''),
                $spec_id,
                is_array($definition['selected_entries'] ?? null) ? $definition['selected_entries'] : array()
            );

            if (! empty($group)) {
                $groups[] = $group;
            }
        }

        return $this->order_specialization_groups_for_layout($groups);
    }

    private function build_specialization_tree_group_data(int $tree_id, string $slug, string $title, int $spec_id, array $selected_entries): array
    {
        $tree = $this->get_wowhead_tree_data($tree_id);
        if (empty($tree)) {
            return array();
        }

        $selected_by_node = $this->index_selected_talents_by_node($selected_entries);
        $nodes = array();
        $row_count = 0;
        $min_column = PHP_INT_MAX;
        $max_column = 0;
        $spent_points = 0;

        foreach (($tree['talents'] ?? array()) as $entries) {
            if (! is_array($entries) || empty($entries)) {
                continue;
            }

            $node = $this->select_wowhead_tree_node($entries, $spec_id);
            if (empty($node)) {
                continue;
            }

            $cell = (int) ($node['cell'] ?? 0);
            if ($cell <= 0) {
                continue;
            }

            $coordinates = $this->get_talent_cell_coordinates($cell);
            $row_count = max($row_count, (int) $coordinates['row']);
            $min_column = min($min_column, (int) $coordinates['column']);
            $max_column = max($max_column, (int) $coordinates['column']);

            $node_id = (int) ($node['node'] ?? 0);
            $selected_state = $selected_by_node[$node_id] ?? array();
            $selected_choice = $this->resolve_selected_choice_index($node, $selected_state);
            $spell = $this->select_tree_spell_variant($node, $selected_choice);
            $max_rank = $this->get_tree_node_max_rank($node, $selected_choice);
            $total_rank = $this->get_selected_node_total_rank($selected_state);
            $spent_rank = $this->get_selected_node_spent_rank($selected_state);
            $is_selected = $total_rank > 0;
            $spent_points += $spent_rank;

            $nodes[$cell] = array(
                'cell' => $cell,
                'row' => (int) $coordinates['row'],
                'column' => (int) $coordinates['column'],
                'node_id' => $node_id,
                'type' => (int) ($node['type'] ?? 0),
                'name' => (string) ($spell['name'] ?? $this->extract_tree_node_name($node)),
                'icon' => (string) ($spell['icon'] ?? ''),
                'selected' => $is_selected,
                'rank' => $total_rank,
                'spent_rank' => $spent_rank,
                'max_rank' => $max_rank,
                'required_points' => (int) ($node['requiredPoints'] ?? 0),
                'spell_url' => $this->build_wowhead_spell_url((int) ($spell['spell'] ?? 0), (string) ($spell['name'] ?? '')),
                'requires' => array_values(array_filter(array_map('intval', (array) ($node['requires'] ?? array())))),
            );
        }

        if (empty($nodes)) {
            return array();
        }

        $column_offset = $min_column === PHP_INT_MAX ? 0 : max(0, $min_column - 1);
        $column_count = max(1, $max_column - $column_offset);

        foreach ($nodes as $cell => $node) {
            $nodes[$cell]['column'] = max(1, (int) $node['column'] - $column_offset);
        }

        foreach ($nodes as $cell => $node) {
            $nodes[$cell]['state'] = $this->determine_tree_node_state($node, $nodes, $spent_points);
        }

        $edges = array();
        foreach ($nodes as $cell => $node) {
            foreach (($node['requires'] ?? array()) as $from_cell) {
                if (! isset($nodes[$from_cell])) {
                    continue;
                }

                $edge_style = $this->get_talent_edge_style($nodes[$from_cell], $node, $row_count, $column_count);
                if ($edge_style === '') {
                    continue;
                }

                $edges[] = array(
                    'from' => $from_cell,
                    'to' => $cell,
                    'active' => $this->is_tree_edge_active($nodes[$from_cell], $node),
                    'style' => $edge_style,
                );
            }
        }

        return array(
            'slug' => $slug,
            'title' => $title,
            'name' => (string) ($tree['name'] ?? $title),
            'texture' => (string) ($tree['texture'] ?? ''),
            'checkpoints' => array_values(array_filter((array) ($tree['checkpoints'] ?? array()), 'is_array')),
            'row_count' => max($row_count, 1),
            'column_count' => $column_count,
            'nodes' => array_values($nodes),
            'edges' => $edges,
        );
    }

    private function render_specialization_tree_group(array $tree): string
    {
        $slug = sanitize_html_class((string) ($tree['slug'] ?? 'tree'));
        $row_count = max(1, (int) ($tree['row_count'] ?? 1));
        $column_count = max(1, (int) ($tree['column_count'] ?? 19));
        $board_style = sprintf('--wgrp-tree-rows:%1$d;--wgrp-tree-columns:%2$d;', $row_count, $column_count);

        ob_start();
        ?>
<section class="wgrp-talent-group wgrp-talent-group-graph wgrp-talent-group-<?php echo esc_attr($slug); ?>">
<h4 class="wgrp-talent-group-title"><?php echo esc_html((string) ($tree['title'] ?? __('Talents', 'guild-roster-importer-for-wow'))); ?></h4>
<div class="wgrp-talent-graph-scroll">
<div class="wgrp-talent-graph-scale" data-wgrp-talent-scale>
<div class="wgrp-talent-graph-board" data-wgrp-talent-board style="<?php echo esc_attr($board_style); ?>">
<?php foreach (($tree['edges'] ?? array()) as $edge) { ?>
<span class="wgrp-talent-edge<?php echo ! empty($edge['active']) ? ' is-active' : ''; ?>" data-wgrp-edge-from="<?php echo esc_attr((string) ($edge['from'] ?? '')); ?>" data-wgrp-edge-to="<?php echo esc_attr((string) ($edge['to'] ?? '')); ?>" style="<?php echo esc_attr((string) ($edge['style'] ?? '')); ?>"><span class="wgrp-talent-edge-line"></span><span class="wgrp-talent-edge-arrow"></span></span>
<?php } ?>
<?php foreach (($tree['nodes'] ?? array()) as $node) { ?>
<?php
    $node_classes = array(
        'wgrp-talent-node',
        'wgrp-talent-node-type-' . (int) ($node['type'] ?? 0),
        'is-' . sanitize_html_class((string) ($node['state'] ?? 'locked')),
    );
    $node_style = sprintf('grid-column:%1$d;grid-row:%2$d;', (int) ($node['column'] ?? 1), (int) ($node['row'] ?? 1));
    $node_name = (string) ($node['name'] ?? '');
    $spell_url = (string) ($node['spell_url'] ?? '');
?>
<?php if ($spell_url !== '') { ?><a class="<?php echo esc_attr(implode(' ', $node_classes)); ?>" data-wgrp-node-cell="<?php echo esc_attr((string) ($node['cell'] ?? '')); ?>" href="<?php echo esc_url($spell_url); ?>" target="_blank" rel="noopener noreferrer" style="<?php echo esc_attr($node_style); ?>" title="<?php echo esc_attr($node_name); ?>"><?php } else { ?><span class="<?php echo esc_attr(implode(' ', $node_classes)); ?>" data-wgrp-node-cell="<?php echo esc_attr((string) ($node['cell'] ?? '')); ?>" style="<?php echo esc_attr($node_style); ?>" title="<?php echo esc_attr($node_name); ?>"><?php } ?>
<span class="wgrp-talent-node-frame">
<?php if ((string) ($node['icon'] ?? '') !== '') { ?><img class="wgrp-talent-node-icon" src="<?php echo esc_url($this->get_wowhead_icon_url((string) $node['icon'])); ?>" alt="<?php echo esc_attr($node_name); ?>" loading="lazy" /><?php } ?>
</span>
<?php if ((int) ($node['max_rank'] ?? 0) > 0) { ?><span class="wgrp-talent-node-rank"><?php echo esc_html((string) ((int) ($node['rank'] ?? 0) . '/' . (int) ($node['max_rank'] ?? 0))); ?></span><?php } ?>
<span class="screen-reader-text"><?php echo esc_html($node_name); ?></span>
<?php if ($spell_url !== '') { ?></a><?php } else { ?></span><?php } ?>
<?php } ?>
</div>
</div>
</div>
</section>
        <?php

        return (string) ob_get_clean();
    }

    private function get_selected_talent_entries(array $loadout, array $spec_entry, string $key): array
    {
        foreach (array($loadout[$key] ?? null, $spec_entry[$key] ?? null) as $candidate) {
            if (is_array($candidate) && ! empty($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return array();
    }

    private function get_class_id_for_spec(int $spec_id): int
    {
        $spec_to_class = array(
            62 => 8, 63 => 8, 64 => 8,
            65 => 2, 66 => 2, 70 => 2,
            71 => 1, 72 => 1, 73 => 1,
            102 => 11, 103 => 11, 104 => 11, 105 => 11,
            250 => 6, 251 => 6, 252 => 6,
            253 => 3, 254 => 3, 255 => 3,
            256 => 5, 257 => 5, 258 => 5,
            259 => 4, 260 => 4, 261 => 4,
            262 => 7, 263 => 7, 264 => 7,
            265 => 9, 266 => 9, 267 => 9,
            268 => 10, 269 => 10, 270 => 10,
            577 => 12, 581 => 12, 1480 => 12,
            1467 => 13, 1468 => 13, 1473 => 13,
        );

        return (int) ($spec_to_class[$spec_id] ?? 0);
    }

    private function resolve_hero_tree_id(int $spec_id, array $spec_entry, array $loadout, int $class_id): int
    {
        $selected_entries = $this->get_selected_talent_entries($loadout, $spec_entry, 'selected_hero_talents');
        $selected_nodes = array_map(
            'intval',
            array_filter(
                array_map(
                    static function ($entry): int {
                        return (int) ($entry['id'] ?? 0);
                    },
                    $selected_entries
                )
            )
        );

        $best_id = 0;
        $best_score = -1;
        foreach ($this->get_wowhead_tree_index() as $tree_id => $tree) {
            if ((int) ($tree['type'] ?? 0) !== 3 || (int) ($tree['playerClass'] ?? 0) !== $class_id) {
                continue;
            }
            if (! $this->wowhead_tree_supports_spec($tree, $spec_id)) {
                continue;
            }

            $score = $this->score_hero_tree_match($tree, $selected_nodes, $spec_id);
            if ($score > $best_score) {
                $best_id = (int) $tree_id;
                $best_score = $score;
            }
        }

        return $best_id;
    }

    private function score_hero_tree_match(array $tree, array $selected_nodes, int $spec_id): int
    {
        if (empty($selected_nodes)) {
            return $this->wowhead_tree_supports_spec($tree, $spec_id) ? 0 : -1;
        }

        $score = 0;
        foreach (($tree['talents'] ?? array()) as $entries) {
            $node = $this->select_wowhead_tree_node(is_array($entries) ? $entries : array(), $spec_id);
            if (empty($node)) {
                continue;
            }

            if (in_array((int) ($node['node'] ?? 0), $selected_nodes, true)) {
                $score++;
            }
        }

        return $score;
    }

    private function wowhead_tree_supports_spec(array $tree, int $spec_id): bool
    {
        foreach (($tree['talents'] ?? array()) as $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $shown_for_specs = array_map('intval', (array) ($entry['shownForSpecs'] ?? array()));
                if (empty($shown_for_specs) || in_array($spec_id, $shown_for_specs, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function index_selected_talents_by_node(array $selected_entries): array
    {
        $indexed = array();
        foreach ($selected_entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $node_id = (int) ($entry['id'] ?? 0);
            if ($node_id > 0) {
                $indexed[$node_id] = $entry;
            }
        }

        return $indexed;
    }

    private function select_wowhead_tree_node(array $entries, int $spec_id): array
    {
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $shown_for_specs = array_map('intval', (array) ($entry['shownForSpecs'] ?? array()));
            if (empty($shown_for_specs) || in_array($spec_id, $shown_for_specs, true)) {
                return $entry;
            }
        }

        return array();
    }

    private function resolve_selected_choice_index(array $node, array $selected_state): int
    {
        $definition = (int) ($selected_state['tooltip']['talent']['id'] ?? 0);
        if ($definition <= 0) {
            return 0;
        }

        foreach ((array) ($node['spells'] ?? array()) as $index => $spell) {
            if ((int) ($spell['definition'] ?? 0) === $definition) {
                return (int) $index;
            }
        }

        return 0;
    }

    private function select_tree_spell_variant(array $node, int $selected_choice): array
    {
        $spells = array_values(array_filter((array) ($node['spells'] ?? array()), 'is_array'));
        if (empty($spells)) {
            return array();
        }

        if (isset($spells[$selected_choice]) && is_array($spells[$selected_choice])) {
            return $spells[$selected_choice];
        }

        return is_array($spells[0]) ? $spells[0] : array();
    }

    private function get_tree_node_max_rank(array $node, int $selected_choice): int
    {
        $type = (int) ($node['type'] ?? 0);
        $spells = array_values(array_filter((array) ($node['spells'] ?? array()), 'is_array'));
        if (empty($spells)) {
            return 1;
        }

        if ($type === 5) {
            $total = 0;
            foreach ($spells as $spell) {
                $total += max(0, (int) ($spell['points'] ?? 0));
            }

            return max(1, $total);
        }

        $spell = $spells[$selected_choice] ?? $spells[0];

        return max(1, (int) ($spell['points'] ?? 1));
    }

    private function get_selected_node_total_rank(array $selected_state): int
    {
        if (empty($selected_state)) {
            return 0;
        }

        $rank = (int) ($selected_state['rank'] ?? 0);
        $default_points = (int) ($selected_state['default_points'] ?? 0);

        return max(0, max($rank, $default_points));
    }

    private function get_selected_node_spent_rank(array $selected_state): int
    {
        if (empty($selected_state)) {
            return 0;
        }

        $rank = (int) ($selected_state['rank'] ?? 0);
        $default_points = (int) ($selected_state['default_points'] ?? 0);

        return max(0, $rank - $default_points);
    }

    private function determine_tree_node_state(array $node, array $nodes, int $spent_points): string
    {
        $rank = (int) ($node['rank'] ?? 0);
        $max_rank = max(1, (int) ($node['max_rank'] ?? 1));
        if ($rank > 0) {
            return $rank >= $max_rank ? 'selected' : 'partial';
        }

        return 'locked';
    }

    private function is_tree_edge_active(array $from_node, array $to_node): bool
    {
        if ((int) ($from_node['rank'] ?? 0) > 0 && (int) ($to_node['rank'] ?? 0) > 0) {
            return true;
        }

        return ((string) ($from_node['state'] ?? '') === 'available' || (string) ($from_node['state'] ?? '') === 'selected' || (string) ($from_node['state'] ?? '') === 'partial')
            && ((string) ($to_node['state'] ?? '') === 'available' || (string) ($to_node['state'] ?? '') === 'selected' || (string) ($to_node['state'] ?? '') === 'partial');
    }

    private function extract_tree_node_name(array $node): string
    {
        foreach ((array) ($node['spells'] ?? array()) as $spell) {
            if (! is_array($spell)) {
                continue;
            }

            $name = trim((string) ($spell['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    private function get_talent_cell_coordinates(int $cell): array
    {
        $column_count = 19;

        return array(
            'column' => ($cell % $column_count) + 1,
            'row' => (int) floor($cell / $column_count) + 1,
        );
    }

    private function get_talent_edge_style(array $from_node, array $to_node, int $row_count, int $column_count = 19): string
    {
        $row_count = max(1, $row_count);
        $column_count = max(1, $column_count);
        $from_column = (int) ($from_node['column'] ?? 1);
        $from_row = (int) ($from_node['row'] ?? 1);
        $to_column = (int) ($to_node['column'] ?? 1);
        $to_row = (int) ($to_node['row'] ?? 1);
        $vertical_center_offset = 0.22;

        $cell_width = 100 / $column_count;
        $cell_height = 100 / $row_count;
        $vertical_scale = $cell_width / $cell_height;

        $from_x = ($from_column - 0.5) * $cell_width;
        $from_y = ($from_row - 0.5 + $vertical_center_offset) * $cell_height;
        $to_x = ($to_column - 0.5) * $cell_width;
        $to_y = ($to_row - 0.5 + $vertical_center_offset) * $cell_height;

        $length = sqrt(pow($from_x - $to_x, 2) + pow(($from_y - $to_y) * $vertical_scale, 2));
        if ($length <= 0) {
            return '';
        }

        $angle = atan2(($to_y - $from_y) * $vertical_scale, ($to_x - $from_x));

        return sprintf(
            'left:%1$.4f%%;top:%2$.4f%%;width:calc(%3$.4f%% - 8px);transform:rotate(%4$.6frad);',
            $from_x,
            $from_y,
            max($length, 0),
            $angle
        );
    }

    private function get_wowhead_tree_data(int $tree_id): array
    {
        $trees = $this->get_wowhead_tree_index();

        return is_array($trees[$tree_id] ?? null) ? $trees[$tree_id] : array();
    }

    private function get_wowhead_tree_index(): array
    {
        static $tree_index = null;
        if (is_array($tree_index)) {
            return $tree_index;
        }

        $tree_index = array();
        $json_file = GUILROIM_PLUGIN_DIR . 'assets/data/talents-dragonflight-trees.json';
        if (! file_exists($json_file)) {
            return $tree_index;
        }

        $contents = file_get_contents($json_file);
        if (! is_string($contents) || $contents === '') {
            return $tree_index;
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return $tree_index;
        }

        foreach ($decoded as $tree) {
            if (! is_array($tree)) {
                continue;
            }

            $tree_id = (int) ($tree['id'] ?? 0);
            if ($tree_id > 0) {
                $tree_index[$tree_id] = $tree;
            }
        }

        return $tree_index;
    }

    private function build_wowhead_spell_url(int $spell_id, string $name): string
    {
        return '';
    }

    private function get_wowhead_icon_url(string $icon): string
    {
        $icon = strtolower(trim($icon));
        if ($icon === '') {
            return '';
        }

        $talent_path = GUILROIM_PLUGIN_DIR . 'assets/images/talents/' . $icon . '.webp';
        if (file_exists($talent_path)) {
            return GUILROIM_PLUGIN_URL . 'assets/images/talents/' . rawurlencode($icon) . '.webp';
        }

        $local_path = GUILROIM_PLUGIN_DIR . 'assets/images/specializations/' . $icon . '.jpg';
        if (file_exists($local_path)) {
            return GUILROIM_PLUGIN_URL . 'assets/images/specializations/' . rawurlencode($icon) . '.jpg';
        }

        return '';
    }

    private function build_specialization_talent_groups(array $spec_entry, array $loadout): array
    {
        $groups = array();
        $definitions = array(
            array('slug' => 'class', 'title' => __('Class Talents', 'guild-roster-importer-for-wow'), 'sources' => array($loadout['selected_class_talents'] ?? null, $spec_entry['selected_class_talents'] ?? null, $spec_entry['class_talents'] ?? null)),
            array('slug' => 'spec', 'title' => __('Spec Talents', 'guild-roster-importer-for-wow'), 'sources' => array($loadout['selected_spec_talents'] ?? null, $spec_entry['selected_spec_talents'] ?? null, $spec_entry['spec_talents'] ?? null, $spec_entry['talents'] ?? null)),
            array('slug' => 'hero', 'title' => __('Hero Talents', 'guild-roster-importer-for-wow'), 'sources' => array($loadout['selected_hero_talents'] ?? null, $spec_entry['selected_hero_talents'] ?? null, $spec_entry['hero_talents'] ?? null)),
        );

        foreach ($definitions as $definition) {
            $rows = $this->normalize_talent_rows_from_sources($definition['sources']);
            if (! empty($rows)) {
                $groups[] = array(
                    'slug' => (string) $definition['slug'],
                    'title' => (string) $definition['title'],
                    'rows' => $rows,
                );
            }
        }

        return $groups;
    }

    private function order_specialization_groups_for_layout(array $groups): array
    {
        if (count($groups) < 3) {
            return $groups;
        }

        $indexed = array();
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $slug = (string) ($group['slug'] ?? '');
            if ($slug !== '') {
                $indexed[$slug] = $group;
            }
        }

        if (isset($indexed['class'], $indexed['hero'], $indexed['spec'])) {
            return array($indexed['class'], $indexed['hero'], $indexed['spec']);
        }

        return $groups;
    }

    private function normalize_talent_rows_from_sources(array $sources): array
    {
        foreach ($sources as $source) {
            $rows = $this->normalize_talent_rows($source);
            if (! empty($rows)) {
                return $rows;
            }
        }

        return array();
    }

    private function normalize_talent_rows($source): array
    {
        if (! is_array($source) || empty($source)) {
            return array();
        }

        $items = array();
        foreach ($source as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $label = $this->extract_talent_label($entry);
            if ($label === '') {
                continue;
            }

            $row_index = $this->extract_talent_grid_value($entry, array('display_row', 'row', 'tier_index', 'tier', 'cell'));
            $column_index = $this->extract_talent_grid_value($entry, array('display_col', 'column', 'column_index', 'choice', 'index'));
            $items[] = array(
                'row' => $row_index > 0 ? $row_index : 1,
                'column' => $column_index > 0 ? $column_index : count($items) + 1,
                'label' => $label,
            );
        }

        if (empty($items)) {
            return array();
        }

        usort($items, static function (array $left, array $right): int {
            if ($left['row'] === $right['row']) {
                return $left['column'] <=> $right['column'];
            }

            return $left['row'] <=> $right['row'];
        });

        $rows = array();
        foreach ($items as $item) {
            $row_key = 'row_' . (int) $item['row'];
            if (! isset($rows[$row_key])) {
                $rows[$row_key] = array();
            }

            $rows[$row_key][] = array(
                /* translators: %s: talent tree column number. */
                'column' => sprintf(__('C%s', 'guild-roster-importer-for-wow'), (string) $item['column']),
                'label' => $item['label'],
            );
        }

        return $rows;
    }

    private function extract_talent_label(array $entry): string
    {
        $sources = array(
            $entry['spell_tooltip']['spell']['name'] ?? '',
            $entry['tooltip']['talent']['name'] ?? '',
            $entry['tooltip']['spell_tooltip']['spell']['name'] ?? '',
            $entry['talent']['name'] ?? '',
            $entry['spell']['name'] ?? '',
            $entry['selected_hero_talent']['spell_tooltip']['spell']['name'] ?? '',
            $entry['display_string'] ?? '',
            $entry['name'] ?? '',
        );

        foreach ($sources as $source) {
            if (is_string($source) && trim($source) !== '') {
                return trim($source);
            }
        }

        return '';
    }

    private function extract_talent_grid_value(array $entry, array $keys): int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $entry)) {
                continue;
            }

            $value = $entry[$key];
            if (is_numeric($value)) {
                return (int) $value;
            }
            if (is_array($value)) {
                foreach (array('id', 'value', 'index') as $nested_key) {
                    if (isset($value[$nested_key]) && is_numeric($value[$nested_key])) {
                        return (int) $value[$nested_key];
                    }
                }
            }
        }

        return 0;
    }

    private function extract_specialization_loadout_name(array $loadout): string
    {
        $candidates = array(
            $loadout['name'] ?? '',
            $loadout['loadout_name'] ?? '',
            $loadout['talent_loadout']['name'] ?? '',
        );

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function extract_specialization_loadout_code(array $loadout, array $spec_entry): string
    {
        $preferred_keys = array(
            'talent_loadout_code',
            'loadout_code',
            'talent_code',
            'import_string',
            'import_code',
            'share_code',
            'export_string',
            'export_code',
            'build_code',
            'build_string',
            'hash',
        );

        foreach (array($loadout, $spec_entry) as $source) {
            $match = $this->find_first_string_by_keys($source, $preferred_keys);
            if ($match !== '') {
                return $match;
            }
        }

        return '';
    }

    private function find_first_string_by_keys($value, array $preferred_keys): string
    {
        if (! is_array($value)) {
            return '';
        }

        foreach ($preferred_keys as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $candidate = $value[$key];
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($value as $nested) {
            if (! is_array($nested)) {
                continue;
            }

            $match = $this->find_first_string_by_keys($nested, $preferred_keys);
            if ($match !== '') {
                return $match;
            }
        }

        return '';
    }

    private function normalize_specialization_role_label(string $role_label): string
    {
        $normalized = strtolower(trim($role_label));
        $map = array(
            'damage' => __('Damage', 'guild-roster-importer-for-wow'),
            'dps' => __('Damage', 'guild-roster-importer-for-wow'),
            'tank' => __('Tank', 'guild-roster-importer-for-wow'),
            'healer' => __('Healer', 'guild-roster-importer-for-wow'),
        );

        return (string) ($map[$normalized] ?? $role_label);
    }

    private function get_specialization_badge_label(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        if (! is_array($words) || empty($words)) {
            return '?';
        }

        if (count($words) === 1) {
            return strtoupper(substr((string) $words[0], 0, 2));
        }

        return strtoupper(substr((string) $words[0], 0, 1) . substr((string) $words[1], 0, 1));
    }

    private function get_specialization_icon_url(int $spec_id, string $name = ''): string
    {
        $icon_map = array(
            62 => 'spell_holy_magicalsentry',
            63 => 'spell_fire_firebolt02',
            64 => 'spell_frost_frostbolt02',
            65 => 'spell_holy_holybolt',
            66 => 'ability_paladin_shieldofthetemplar',
            70 => 'spell_holy_auraoflight',
            71 => 'ability_warrior_savageblow',
            72 => 'ability_warrior_innerrage',
            73 => 'ability_warrior_defensivestance',
            102 => 'spell_nature_starfall',
            103 => 'ability_druid_catform',
            104 => 'ability_racial_bearform',
            105 => 'spell_nature_healingtouch',
            250 => 'spell_deathknight_bloodpresence',
            251 => 'spell_deathknight_frostpresence',
            252 => 'spell_deathknight_unholypresence',
            253 => 'ability_hunter_bestialdiscipline',
            254 => 'ability_hunter_focusedaim',
            255 => 'ability_hunter_camouflage',
            256 => 'spell_holy_powerwordshield',
            257 => 'spell_holy_guardianspirit',
            258 => 'spell_shadow_shadowwordpain',
            259 => 'ability_rogue_eviscerate',
            260 => 'ability_rogue_waylay',
            261 => 'ability_stealth',
            262 => 'spell_nature_lightning',
            263 => 'spell_shaman_improvedstormstrike',
            264 => 'spell_nature_magicimmunity',
            265 => 'spell_shadow_deathcoil',
            266 => 'spell_shadow_metamorphosis',
            267 => 'spell_shadow_rainoffire',
            268 => 'spell_monk_brewmaster_spec',
            269 => 'spell_monk_windwalker_spec',
            270 => 'spell_monk_mistweaver_spec',
            577  => 'ability_demonhunter_specdps',
            581  => 'ability_demonhunter_spectank',
            1480 => 'classicon_demonhunter_void',
            1467 => 'classicon_evoker_devastation',
            1468 => 'classicon_evoker_preservation',
            1473 => 'classicon_evoker_augmentation',
        );

        $icon = (string) ($icon_map[$spec_id] ?? '');
        if ($icon === '') {
            return '';
        }

        $local_path = GUILROIM_PLUGIN_DIR . 'assets/images/specializations/' . $icon . '.jpg';
        if (file_exists($local_path)) {
            return GUILROIM_PLUGIN_URL . 'assets/images/specializations/' . rawurlencode($icon) . '.jpg';
        }

        return '';
    }

    private function extract_health_value(array $summary, array $statistics): string
    {
        $value = $this->find_numeric_stat_value($statistics, array('health', 'max_health'));
        if ($value <= 0) {
            $value = (float) ($summary['health'] ?? 0);
        }

        return $value > 0 ? number_format_i18n((int) round($value)) : '';
    }

    private function extract_resource_value(string $resource_label, array $summary, array $statistics): string
    {
        $resource_map = array(
            'Mana' => array('mana', 'power', 'resource'),
            'Energy' => array('energy'),
            'Focus' => array('focus'),
            'Maelstrom' => array('maelstrom'),
            'Runic Power' => array('runic_power', 'runicpower'),
            'Rage' => array('rage'),
            'Fury' => array('fury'),
        );

        $top_level_power = $this->extract_top_level_power_value($statistics, $resource_label);
        if ($top_level_power > 0) {
            return number_format_i18n((int) round($top_level_power));
        }

        $value = $this->extract_resource_value_from_entry($statistics, $resource_label);
        if ($value <= 0) {
            $value = $this->find_numeric_stat_value($statistics, $resource_map[$resource_label] ?? array());
        }
        if ($value <= 0 && $resource_label === 'Mana') {
            $value = (float) ($summary['mana'] ?? 0);
        }

        return $value > 0 ? number_format_i18n((int) round($value)) : '';
    }

    private function extract_primary_stat_value(string $primary_stat_label, array $statistics): string
    {
        $value = $this->find_numeric_stat_value($statistics, array(strtolower(str_replace(' ', '_', $primary_stat_label))));

        return $value > 0 ? number_format_i18n((int) round($value)) : '';
    }

    private function extract_named_stat_value(array $statistics, array $candidates): string
    {
        $value = $this->find_numeric_stat_value($statistics, $candidates);

        return $value > 0 ? number_format_i18n((int) round($value)) : '';
    }

    private function extract_critical_strike_data(array $statistics): array
    {
        return $this->extract_precise_rating_stat_object($statistics, array('melee_crit', 'spell_crit', 'critical_strike', 'crit'));
    }

    private function extract_haste_data(array $statistics): array
    {
        return $this->extract_precise_rating_stat_object($statistics, array('melee_haste', 'spell_haste', 'ranged_haste', 'haste'));
    }

    private function extract_mastery_data(array $statistics): array
    {
        return $this->extract_precise_rating_stat_object($statistics, array('mastery'));
    }

    private function extract_versatility_data(array $statistics): array
    {
        $rating = $this->find_numeric_stat_value($statistics, array('versatility'));
        $done_bonus = $this->find_numeric_stat_value($statistics, array('versatility_damage_done_bonus', 'damage_heal_versatility'));
        $mitigation = $this->find_numeric_stat_value($statistics, array('versatility_damage_taken_bonus', 'mitigation_versatility'));

        return array(
            'percent' => $done_bonus > 0 ? $this->format_percentage_value($done_bonus) : '',
            'rating' => $rating > 0 ? number_format_i18n((int) round($rating)) : '',
            /* translators: %s: versatility damage reduction percentage. */
            'extra' => $mitigation > 0 ? sprintf(__('Damage Taken Reduced: %s', 'guild-roster-importer-for-wow'), $this->format_percentage_value($mitigation)) : '',
        );
    }

    private function extract_precise_rating_stat_object(array $statistics, array $candidates): array
    {
        $entry = $this->find_stat_entry($statistics, $candidates);
        if (! is_array($entry)) {
            return array();
        }

        $percent = (isset($entry['value']) && is_numeric($entry['value']) && (float) $entry['value'] > 0)
            ? $this->format_percentage_value((float) $entry['value'])
            : '';
        $rating = (isset($entry['rating_normalized']) && is_numeric($entry['rating_normalized']) && (float) $entry['rating_normalized'] > 0)
            ? number_format_i18n((int) round((float) $entry['rating_normalized']))
            : '';

        return array(
            'percent' => $percent,
            'rating' => $rating,
            'extra' => '',
        );
    }

    private function extract_rating_stat(array $statistics, array $candidates): array
    {
        $entry = $this->find_stat_entry($statistics, $candidates);
        if ($entry === null) {
            return array();
        }

        $percent = $this->extract_stat_percent($entry);
        $rating = $this->extract_stat_rating($entry);

        return array(
            'percent' => $percent,
            'rating' => $rating > 0 ? number_format_i18n((int) round($rating)) : '',
            'extra' => '',
        );
    }

    private function get_primary_stat_label(string $class_name, string $spec_name): string
    {
        $class_key = strtolower(trim($class_name));
        $spec_key = strtolower(trim($spec_name));

        $spec_map = array(
            'holy' => 'Intellect',
            'retribution' => 'Strength',
            'arms' => 'Strength',
            'fury' => 'Strength',
            'balance' => 'Intellect',
            'feral' => 'Agility',
            'guardian' => 'Agility',
            'blood' => 'Strength',
            'unholy' => 'Strength',
            'beast mastery' => 'Agility',
            'marksmanship' => 'Agility',
            'survival' => 'Agility',
            'discipline' => 'Intellect',
            'shadow' => 'Intellect',
            'assassination' => 'Agility',
            'outlaw' => 'Agility',
            'subtlety' => 'Agility',
            'elemental' => 'Intellect',
            'enhancement' => 'Agility',
            'affliction' => 'Intellect',
            'demonology' => 'Intellect',
            'destruction' => 'Intellect',
            'brewmaster' => 'Agility',
            'windwalker' => 'Agility',
            'mistweaver' => 'Intellect',
            'havoc' => 'Agility',
            'vengeance' => 'Agility',
            'devastation' => 'Intellect',
            'preservation' => 'Intellect',
            'augmentation' => 'Intellect',
            'arcane' => 'Intellect',
            'fire' => 'Intellect',
        );

        if ($spec_key === 'protection') {
            return $class_key === 'paladin' || $class_key === 'warrior' ? 'Strength' : 'Agility';
        }
        if ($spec_key === 'restoration') {
            return in_array($class_key, array('druid', 'shaman'), true) ? 'Intellect' : 'Agility';
        }
        if ($spec_key === 'frost') {
            return $class_key === 'mage' ? 'Intellect' : 'Strength';
        }
        if (isset($spec_map[$spec_key]) && $spec_map[$spec_key] !== 'Healer') {
            return $spec_map[$spec_key];
        }

        $class_defaults = array(
            'warrior' => 'Strength',
            'paladin' => 'Strength',
            'hunter' => 'Agility',
            'rogue' => 'Agility',
            'priest' => 'Intellect',
            'death knight' => 'Strength',
            'shaman' => 'Intellect',
            'mage' => 'Intellect',
            'warlock' => 'Intellect',
            'monk' => 'Agility',
            'druid' => 'Intellect',
            'demon hunter' => 'Agility',
            'evoker' => 'Intellect',
        );

        return (string) ($class_defaults[$class_key] ?? 'Strength');
    }

    private function get_resource_label(string $class_name, string $spec_name): string
    {
        $class_key = strtolower(trim($class_name));
        $spec_key = strtolower(trim($spec_name));

        if ($class_key === 'warrior') {
            return 'Rage';
        }
        if ($class_key === 'hunter') {
            return 'Focus';
        }
        if ($class_key === 'rogue') {
            return 'Energy';
        }
        if ($class_key === 'death knight') {
            return 'Runic Power';
        }
        if ($class_key === 'demon hunter') {
            return 'Fury';
        }
        if ($class_key === 'shaman' && $spec_key === 'enhancement') {
            return 'Maelstrom';
        }
        if ($class_key === 'mage' || $class_key === 'priest' || $class_key === 'warlock' || $class_key === 'shaman' || $class_key === 'evoker') {
            return 'Mana';
        }
        if ($class_key === 'paladin') {
            return 'Mana';
        }
        if ($class_key === 'monk') {
            return $spec_key === 'mistweaver' ? 'Mana' : 'Energy';
        }
        if ($class_key === 'druid') {
            if ($spec_key === 'feral') {
                return 'Energy';
            }
            if ($spec_key === 'guardian') {
                return 'Rage';
            }
            return 'Mana';
        }

        return 'Mana';
    }

    private function find_stat_entry(array $data, array $candidates)
    {
        foreach ($candidates as $candidate) {
            $found = $this->find_recursive_value_by_key($data, $candidate);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function find_numeric_stat_value(array $data, array $candidates): float
    {
        $entry = $this->find_stat_entry($data, $candidates);
        if ($entry === null) {
            return 0.0;
        }

        return $this->extract_numeric_value($entry);
    }

    private function extract_stat_percent($entry): string
    {
        if (is_scalar($entry)) {
            if (is_string($entry) && str_contains($entry, '%')) {
                return trim((string) $entry);
            }
            $parsed = $this->extract_numeric_from_string((string) $entry);
            return ($parsed > 0 && $parsed <= 100) ? $this->format_percentage_value($parsed) : '';
        }

        if (! is_array($entry)) {
            return '';
        }

        foreach (array('display_string', 'display', 'percent', 'percentage') as $key) {
            if (! isset($entry[$key])) {
                continue;
            }
            $value = $entry[$key];
            if (is_string($value) && str_contains($value, '%')) {
                return trim($value);
            }
            if (is_numeric($value) && in_array($key, array('percent', 'percentage'), true)) {
                return $this->format_percentage_value((float) $value);
            }
        }

        if (isset($entry['value']) && is_numeric($entry['value']) && (float) $entry['value'] > 0 && (float) $entry['value'] <= 100) {
            return $this->format_percentage_value((float) $entry['value']);
        }

        foreach ($entry as $value) {
            $nested = $this->extract_stat_percent($value);
            if ($nested !== '') {
                return $nested;
            }
        }

        return '';
    }

    private function extract_stat_rating($entry): float
    {
        if (is_numeric($entry)) {
            return (float) $entry > 100 ? (float) $entry : 0.0;
        }

        if (! is_array($entry)) {
            return 0.0;
        }

        $rating = $this->find_numeric_value_by_key_pattern($entry, array('ratingnormalized', 'rating_value', 'ratingvalue', 'baserating', 'rating'));
        if ($rating > 0) {
            return $rating;
        }

        foreach (array('effective', 'base', 'value') as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key]) && (float) $entry[$key] > 100) {
                return (float) $entry[$key];
            }
        }

        foreach ($entry as $value) {
            $nested = $this->extract_stat_rating($value);
            if ($nested > 0) {
                return $nested;
            }
        }

        return 0.0;
    }

    private function extract_top_level_power_value(array $statistics, string $resource_label): float
    {
        $power_type_name = strtolower(trim((string) ($statistics['power_type']['name'] ?? '')));
        $power_value = isset($statistics['power']) && is_numeric($statistics['power']) ? (float) $statistics['power'] : 0.0;

        if ($power_value <= 0) {
            return 0.0;
        }

        return $power_type_name === strtolower(trim($resource_label)) ? $power_value : 0.0;
    }

    private function extract_numeric_value($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            return $this->extract_numeric_from_string($value);
        }
        if (! is_array($value)) {
            return 0.0;
        }

        foreach (array('effective', 'value', 'base', 'max', 'current', 'rating') as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                return (float) $value[$key];
            }
        }

        foreach ($value as $nested) {
            $numeric = $this->extract_numeric_value($nested);
            if ($numeric > 0) {
                return $numeric;
            }
        }

        return 0.0;
    }

    private function extract_numeric_from_string(string $value): float
    {
        $normalized = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
        if ($normalized === '') {
            return 0.0;
        }
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $normalized, $matches)) {
            return (float) str_replace(',', '.', $matches[0]);
        }

        return 0.0;
    }

    private function format_percentage_value(float $value): string
    {
        return number_format_i18n($value, 2) . '%';
    }

    private function find_recursive_value_by_key(array $data, string $target)
    {
        $normalized_target = $this->normalize_stat_key($target);

        foreach ($data as $key => $value) {
            if ($this->normalize_stat_key((string) $key) === $normalized_target) {
                return $value;
            }
            if (is_array($value)) {
                $nested = $this->find_recursive_value_by_key($value, $target);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function find_numeric_value_by_key_pattern(array $data, array $patterns): float
    {
        foreach ($data as $key => $value) {
            $normalized_key = $this->normalize_stat_key((string) $key);
            foreach ($patterns as $pattern) {
                $normalized_pattern = $this->normalize_stat_key($pattern);
                if ($normalized_pattern !== '' && $normalized_key !== '' && str_contains($normalized_key, $normalized_pattern) && is_numeric($value)) {
                    return (float) $value;
                }
            }

            if (is_array($value)) {
                $nested = $this->find_numeric_value_by_key_pattern($value, $patterns);
                if ($nested > 0) {
                    return $nested;
                }
            }
        }

        return 0.0;
    }

    private function extract_resource_value_from_entry($data, string $resource_label): float
    {
        $target = $this->normalize_stat_key($resource_label);

        if (! is_array($data)) {
            return 0.0;
        }

        $matched = false;
        foreach (array('type', 'name', 'resource', 'power_type') as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $type_value = $this->normalize_stat_key((string) $data[$key]);
                if ($type_value === $target) {
                    $matched = true;
                    foreach (array('max', 'maximum', 'value', 'current', 'amount') as $value_key) {
                        if (isset($data[$value_key]) && is_numeric($data[$value_key])) {
                            return (float) $data[$value_key];
                        }
                    }
                    $value = $this->extract_numeric_value($data);
                    if ($value > 0) {
                        return $value;
                    }
                }
            }
        }

        if (! $matched) {
            foreach ($data as $key => $value) {
                if (! is_array($value)) {
                    $normalized_key = $this->normalize_stat_key((string) $key);
                    if ($normalized_key === $target && is_numeric($value)) {
                        return (float) $value;
                    }
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $nested = $this->extract_resource_value_from_entry($value, $resource_label);
                if ($nested > 0) {
                    return $nested;
                }
            }
        }

        return 0.0;
    }

    private function normalize_stat_key(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value)));
    }

    private function get_stat_icon_svg(string $icon): string
    {
        $method = 'get_stat_icon_' . preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($icon)));
        if (method_exists($this, $method)) {
            return (string) $this->{$method}();
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><circle cx="32" cy="32" r="20"></circle></svg>';
    }

    private function get_stat_icon_health(): string
    {
        return '<span class="Icon Media-icon Icon--health Icon--svg"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M32.105 26.297c-3.863-9.098-14.842-6.841-14.914 3.719-.04 5.799 3.176 8.808 14.937 16.919 10.688-7.172 14.945-11.183 14.905-16.982-.073-10.586-11.244-12.391-14.928-3.656"></path><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path></g></svg></span>';
    }

    private function get_stat_icon_energy(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><g fill-rule="evenodd" clip-rule="evenodd"><path d="M31.409 23.649c3.846-.227 7.227 1.088 9.833 2.731 1.498.945 3.643 2.645 5.151 4.214.595.619 1.327 1.131 1.327 1.795 0 1.025-2.203 2.685-2.887 3.277-3.192 2.763-7.219 5.516-12.72 5.541-3.937.017-6.724-1.368-9.286-2.966a27.6 27.6 0 0 1-5.073-4.058c-.528-.539-1.249-1.149-1.249-1.795 0-.982 2.086-2.63 2.731-3.2 2.147-1.893 4.385-3.451 7.18-4.526 1.484-.57 3.168-.905 4.993-1.013m-4.292 5.306c-1.205 1.767-1.364 4.867 0 6.867 1.554 2.28 5.535 3.533 8.428 1.561 1.805-1.23 3.388-4.187 2.185-7.257-.853-2.178-3.225-4.031-6.321-3.668-1.818.213-3.408 1.2-4.292 2.497"></path><path d="M31.565 30.516c1.667-.413 3.019.943 2.419 2.653-.278.792-1.451 1.514-2.653 1.093-.982-.344-1.446-1.653-1.092-2.653.15-.425.743-.949 1.326-1.093"></path></g></g></svg>';
    }

    private function get_stat_icon_mana(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="m44.584 43.126-7.489-11.73v-4.722h.425c1.753 0 3.335-1.432 3.335-3.29v-1.35c0-1.857-1.582-3.328-3.335-3.328H26.736c-1.753 0-3.428 1.471-3.428 3.328v1.35c0 1.857 1.674 3.29 3.428 3.29h.332v4.721l-7.442 11.731c-.26.407-.277.968-.059 1.401s.652.739 1.115.739h22.892c.463 0 .874-.306 1.092-.739a1.46 1.46 0 0 0-.082-1.401M29.378 32.543c.14-.219.197-.478.197-.742v-6.376c0-.734-.527-1.407-1.219-1.407h-1.62c-.371 0-.921-.241-.921-.633v-1.35c0-.393.55-.672.921-.672H37.52c.371 0 .829.28.829.672v1.35c0 .393-.458.633-.829.633h-1.619c-.692 0-1.312.673-1.312 1.407v6.376c0 .264.104.523.244.742l3.053 4.755H26.371z"></path></g></svg>';
    }

    private function get_stat_icon_focus(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><g fill-rule="evenodd" clip-rule="evenodd"><path d="M31.409 23.649c3.846-.227 7.227 1.088 9.833 2.731 1.498.945 3.643 2.645 5.151 4.214.595.619 1.327 1.131 1.327 1.795 0 1.025-2.203 2.685-2.887 3.277-3.192 2.763-7.219 5.516-12.72 5.541-3.937.017-6.724-1.368-9.286-2.966a27.6 27.6 0 0 1-5.073-4.058c-.528-.539-1.249-1.149-1.249-1.795 0-.982 2.086-2.63 2.731-3.2 2.147-1.893 4.385-3.451 7.18-4.526 1.484-.57 3.168-.905 4.993-1.013m-4.292 5.306c-1.205 1.767-1.364 4.867 0 6.867 1.554 2.28 5.535 3.533 8.428 1.561 1.805-1.23 3.388-4.187 2.185-7.257-.853-2.178-3.225-4.031-6.321-3.668-1.818.213-3.408 1.2-4.292 2.497"></path><path d="M31.565 30.516c1.667-.413 3.019.943 2.419 2.653-.278.792-1.451 1.514-2.653 1.093-.982-.344-1.446-1.653-1.092-2.653.15-.425.743-.949 1.326-1.093"></path></g></g></svg>';
    }

    private function get_stat_icon_strength(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M49.771 31.52H47.57v-6.691c0-.821-.672-1.492-1.492-1.492-.821 0-1.492.672-1.492 1.492 0-.821-.672-1.492-1.492-1.492s-1.494.671-1.494 1.491c0-.821-.672-1.492-1.492-1.492s-1.492.672-1.492 1.492v6.691H25.608v-6.691c0-.821-.672-1.492-1.492-1.492s-1.492.672-1.492 1.492c0-.821-.672-1.492-1.492-1.492s-1.492.672-1.492 1.492c0-.821-.672-1.492-1.492-1.492-.821 0-1.492.672-1.492 1.492v6.691h-2.201v2.154h2.201v6.691c0 .821.672 1.492 1.492 1.492.821 0 1.492-.672 1.492-1.492 0 .821.672 1.492 1.492 1.492s1.492-.672 1.492-1.492c0 .821.672 1.492 1.492 1.492s1.492-.672 1.492-1.492v-6.691h13.007v6.691c0 .821.672 1.492 1.492 1.492s1.492-.672 1.492-1.492c0 .821.672 1.492 1.492 1.492s1.492-.672 1.492-1.492c0 .821.672 1.492 1.492 1.492.821 0 1.492-.672 1.492-1.492v-6.691h2.201V31.52z"></path></g></svg>';
    }

    private function get_stat_icon_agility(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M46.804 34.219c.179 3.128-6.415 10.197-7.82 4.58-.414-1.655.806-3.86 1.905-5.034.877-.937 3.26-2.676 4.614-2.312.515.139 1.057.698 1.159 1.126zm-8.215-1.839c-4.919-.719-1.104-7.935 1.369-9.446 2.494-1.524 5.354.637 4.918 3.456-.325 2.104-3.862 6.345-6.287 5.99m-1.392-13.102c1.996 2.27-2.691 9.045-5.34 8.312-4.923-1.363-.48-8.416 1.495-9.475.671-.36 1.341-.582 2.183-.27zm-15.139 9.51c-2.339-2.141.838-8.034 3.039-9.299 3.92-2.253 3.392 5.612 2.012 7.496-.858 1.172-2.537 2.562-3.989 2.618zm14.093 12.24c-.003.132.003.234.019.295.493 1.816 1.522 3.409-.036 5.133-1.427 1.579-4.028 1.509-5.731.345-1.638-1.12-2.027-3.49-3.449-4.853-1.705-1.634-3.554-1.703-5.701-2.009-2.349-.335-3.406-2.041-3.771-4.325-.376-2.355.885-3.062 3.061-3.379 1.45-.211 2.958-.352 4.245-1.081 2.406-1.364 2.797-3.668 5.762-1.705 1.754 1.161 3.994 2.469 5.233 4.215.733 1.033 1.435 1.805 1.283 2.982-.187 1.461-.875 2.806-.915 4.382"></path></g></svg>';
    }

    private function get_stat_icon_stamina(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M37.832 31.246h-4.29v-4.259h-2.861v4.259h-4.254v2.864h4.254v4.259h2.861V34.11h4.29z"></path><path d="M32.111 15.676 15.129 32.678 32.111 49.68l16.983-17.002zM19.169 32.678l12.943-12.957 12.943 12.957-12.944 12.958z"></path></g></svg>';
    }

    private function get_stat_icon_intellect(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M28.832 44.802h6.559v-1.101h-6.559zm1.078 2.202h4.402v-1.101H29.91zm2.246-28.625h-.09c-1.078 0-9.704.127-9.704 11.01 0 2.696 1.5 4.496 2.949 6.234 1.255 1.507 2.442 2.928 2.442 4.776V42.6h8.715v-2.202c0-1.848 1.187-3.269 2.442-4.776 1.449-1.737 2.949-3.537 2.949-6.234.001-10.882-8.624-11.009-9.703-11.009"></path></g></svg>';
    }

    private function get_stat_icon_critical_strike(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M32.111 29.689a2.989 2.989 0 1 0 0 5.977 2.989 2.989 0 0 0 0-5.977"></path><path d="m41.603 33.535 9.219-.857-9.219-.857a9.54 9.54 0 0 0-8.634-8.633l-.857-9.218-.857 9.218c-4.574.41-8.224 4.06-8.634 8.633l-9.219.857 9.219.857c.41 4.573 4.06 8.224 8.634 8.633l.857 9.218.857-9.218c4.573-.41 8.224-4.06 8.634-8.633m-8.527 7.472.215-2.313h-2.36l.215 2.313a8.4 8.4 0 0 1-7.365-7.364l2.313.215v-2.359l-2.313.215a8.4 8.4 0 0 1 7.365-7.364l-.215 2.313h2.36l-.215-2.313a8.4 8.4 0 0 1 7.365 7.364l-2.314-.215v2.359l2.314-.215a8.4 8.4 0 0 1-7.365 7.364"></path></g></svg>';
    }

    private function get_stat_icon_haste(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M33.446 23.135c-.89 0-1.612.722-1.612 1.612v8.049l-4.079 4.078a1.611 1.611 0 1 0 2.28 2.28l4.551-4.55c.302-.302.472-.712.472-1.14v-8.716a1.61 1.61 0 0 0-1.612-1.613m-1.335-6.467c-8.509 0-15.431 6.92-15.431 15.427s6.922 15.426 15.431 15.426 15.431-6.92 15.431-15.426-6.922-15.427-15.431-15.427M19.179 38.606a14.3 14.3 0 0 1-1.545-6.511c0-7.981 6.495-14.473 14.477-14.473v2.097c-6.826 0-12.379 5.552-12.379 12.376 0 1.959.444 3.831 1.32 5.566zm12.932 4.91c-6.3 0-11.426-5.124-11.426-11.422s5.126-11.422 11.426-11.422 11.426 5.124 11.426 11.422-5.126 11.422-11.426 11.422"></path></g></svg>';
    }

    private function get_stat_icon_mastery(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M46.934 26.287c-1.107 0-2.004.885-2.004 1.976 0 .31.079.599.208.861l-6.74 4.691-.387-8.422c1.064-.048 1.914-.906 1.914-1.967 0-1.091-.898-1.976-2.005-1.976s-2.004.885-2.004 1.976c0 .732.408 1.364 1.008 1.705l-4.812 8.237-4.812-8.237a1.96 1.96 0 0 0 1.008-1.705c0-1.091-.897-1.976-2.004-1.976s-2.004.885-2.004 1.976c0 1.061.85 1.919 1.914 1.967l-.387 8.422-6.74-4.691c.129-.261.208-.551.208-.861 0-1.092-.897-1.976-2.005-1.976s-2.004.885-2.004 1.976.897 1.976 2.004 1.976c.298 0 .578-.068.832-.183l5.048 13.236c2.178-.849 5.377-1.385 8.943-1.385s6.764.536 8.942 1.385l5.048-13.236c.254.115.534.183.832.183 1.107 0 2.005-.885 2.005-1.976-.001-1.091-.899-1.976-2.006-1.976"></path></g></svg>';
    }

    private function get_stat_icon_versatility(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="m40.128 30.517 3.792 1.018 4.647-4.652-.674-2.517-3.432 3.436-3.833-1.028-1.027-3.839 3.431-3.436-2.513-.674-4.646 4.652 1.016 3.798-2.898 2.902-6.569-6.577 1.893-1.895 4.978-2.562-7.639-.103-1.893 1.895-.721-.722-2.975 2.979.721.722-2.118 2.121-1.758.496-2.254 2.257 3.957 3.961 2.253-2.256.496-1.76-.033-.033 2.119-2.121 6.568 6.576-2.786 2.79-3.793-1.018-4.646 4.652.674 2.517 3.431-3.436 3.834 1.029 1.027 3.838-3.432 3.436 2.513.674 4.647-4.652-1.017-3.798 2.786-2.789 8.621 8.632 2.975-2.979-8.621-8.632z"></path></g></svg>';
    }

    private function get_stat_icon_rage(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path d="M32.111 16.668c-8.679 0-15.747 7.069-15.747 15.747s7.034 15.747 15.747 15.747a15.726 15.726 0 0 0 15.747-15.747c.001-8.678-7.068-15.747-15.747-15.747m-9.434 13.501a2.18 2.18 0 0 1-2.191-2.191c0-.438.097-.877.341-1.217l4.042 1.217a2.184 2.184 0 0 1-2.192 2.191m14.999 8.182H26.719c-.292 0-.536-.243-.487-.536.243-3.068 2.825-5.454 5.941-5.454 3.117 0 5.698 2.386 5.99 5.454 0 .292-.195.536-.487.536m3.555-8.182a2.18 2.18 0 0 1-2.191-2.191l4.042-1.217c.195.341.341.779.341 1.217a2.18 2.18 0 0 1-2.192 2.191"></path></g></svg>';
    }

    private function get_stat_icon_fury(): string
    {
        return $this->get_stat_icon_rage();
    }

    private function get_stat_icon_maelstrom(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185a27 27 0 0 0-2.173 1.939l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.802.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z"></path><path fill-rule="evenodd" d="m41.172 17.971-7.614 10.431h7.31L23.507 47.94l5.787-13.222H23.05l6.7-16.747z" clip-rule="evenodd"></path></g></svg>';
    }

    private function get_stat_icon_runic_power(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" xml:space="preserve" viewBox="0 0 64 64" class="Icon-svg"><g xmlns="http://www.w3.org/2000/svg"><path d="M57.355 42.637A27.25 27.25 0 0 0 59.501 32c0-3.685-.724-7.262-2.146-10.637V6.756H42.794a27.2 27.2 0 0 0-10.683-2.172c-3.702 0-7.295.733-10.683 2.172H6.867v14.606A27.3 27.3 0 0 0 4.722 32c0 3.687.724 7.264 2.145 10.637v14.607h14.561a27.2 27.2 0 0 0 10.683 2.173c3.701 0 7.294-.734 10.683-2.173h14.561zm-1.5-34.381v10.068a28 28 0 0 0-1.021-1.636c-.065-.098-.138-.19-.204-.286a27 27 0 0 0-1.188-1.591q-.194-.242-.393-.481a27 27 0 0 0-1.545-1.704 27 27 0 0 0-2.174-1.94 27 27 0 0 0-1.573-1.182c-.102-.071-.2-.147-.303-.217a28 28 0 0 0-1.642-1.031zm-47.488 0h10.042q-.842.488-1.643 1.032c-.102.069-.199.145-.3.215q-.811.565-1.577 1.185-.24.193-.476.391a27 27 0 0 0-1.697 1.548l-.017.018a27 27 0 0 0-1.919 2.164 27 27 0 0 0-1.19 1.593c-.066.096-.138.188-.203.285q-.538.799-1.021 1.636V8.256zm0 47.488V45.676q.483.84 1.022 1.638c.064.096.135.186.2.281a27 27 0 0 0 1.581 2.072c.494.587 1.002 1.16 1.543 1.702l.002.002a27 27 0 0 0 2.173 1.94q.767.621 1.579 1.187c.101.07.197.146.299.215q.801.543 1.642 1.031zm13.502.062A25.83 25.83 0 0 1 8.306 42.2 25.8 25.8 0 0 1 6.222 32c0-3.538.701-6.969 2.084-10.199A25.84 25.84 0 0 1 21.869 8.194a25.7 25.7 0 0 1 10.242-2.111c3.553 0 6.998.71 10.242 2.111A25.84 25.84 0 0 1 55.916 21.8 25.8 25.8 0 0 1 58.001 32c0 3.536-.701 6.968-2.085 10.199a25.83 25.83 0 0 1-13.563 13.606 25.7 25.7 0 0 1-10.242 2.111 25.7 25.7 0 0 1-10.242-2.11m33.986-.062H45.813q.842-.488 1.642-1.031c.103-.069.2-.146.302-.216a28 28 0 0 0 1.571-1.181q.245-.197.484-.398a28 28 0 0 0 1.69-1.541l.035-.038a27 27 0 0 0 1.907-2.152q.619-.769 1.182-1.583c.068-.098.141-.192.208-.292q.537-.797 1.02-1.635z" style="display: inline;"></path><g style="display: inline;"><path d="M25.176 18.471v12.816l-5.387-4.714v6.023l5.387 4.714v9.412h5.217V18.471zM39.047 46.722V33.906l5.387 4.714v-6.024l-5.387-4.713v-9.412h-5.218v28.251z"></path></g></g></svg>';
    }


    private function fetch_item_icon_url(array $options, int $item_id, string $region, string $locale): string
    {
        $client_id = trim((string) ($options['blizzard_client_id'] ?? ''));
        $client_secret = trim((string) ($options['blizzard_client_secret'] ?? ''));
        if ($client_id === '' || $client_secret === '' || $item_id <= 0 || $region === '') {
            return '';
        }

        $cache_key = 'guilroim_item_icon_' . md5($region . '|' . $locale . '|' . $item_id . '|schema_4');
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached === 'none' ? '' : $cached;
        }

        $token = $this->api->get_access_token($client_id, $client_secret);
        if (is_wp_error($token)) {
            return '';
        }

        $media_url = sprintf('https://%s.api.blizzard.com/data/wow/media/item/%d', rawurlencode($region), $item_id);
        $data = $this->api->api_get_json($media_url, 'static-' . $region, $locale, $token);
        if (is_wp_error($data)) {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $icon_url = $this->extract_item_icon_url_from_data($data);
        if ($icon_url === '') {
            set_transient($cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return '';
        }

        set_transient($cache_key, $icon_url, 30 * DAY_IN_SECONDS);
        return $icon_url;
    }

    private function extract_item_icon_url_from_data(array $data): string
    {
        if (! empty($data['assets']) && is_array($data['assets'])) {
            foreach (array('icon', 'main', 'image', 'small', 'large') as $wanted_key) {
                foreach ($data['assets'] as $asset) {
                    if (! is_array($asset)) {
                        continue;
                    }

                    $key = strtolower((string) ($asset['key'] ?? ''));
                    $value = (string) ($asset['value'] ?? $asset['url'] ?? '');
                    if ($key === $wanted_key) {
                        $localized = $this->api->localize_remote_media_url($value, 'item-icons');
                        if ($localized !== '') {
                            return $localized;
                        }
                    }
                }
            }
        }

        foreach ($data as $key => $value) {
            $normalized_key = strtolower((string) $key);
            if (is_string($value) && (str_contains($normalized_key, 'icon') || str_contains($normalized_key, 'image') || $normalized_key === 'href' || $normalized_key === 'url')) {
                $localized = $this->api->localize_remote_media_url($value, 'item-icons');
                if ($localized !== '') {
                    return $localized;
                }
            }

            if (is_array($value)) {
                $nested = $this->extract_item_icon_url_from_data($value);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function is_image_url(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $parsed = wp_parse_url($value);
        if (! is_array($parsed) || empty($parsed['host']) || empty($parsed['path'])) {
            return false;
        }

        $extension = strtolower((string) pathinfo((string) $parsed['path'], PATHINFO_EXTENSION));
        return in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'gif'), true);
    }

    private function build_item_row_markup(string $classes, string $icon_markup, string $slot_label, string $item_markup, string $item_level, bool $is_mirrored, bool $is_empty, array $item_meta = array(), string $href = '#', string $wowhead_data = ''): string
    {
        if ($is_empty) {
            $body_content = '<div class="wgrp-item-link wgrp-item-link-empty">' . esc_html($item_markup) . '</div>';
        } else {
            $body_content = $item_markup;
        }

        $enchant = (string) ($item_meta['enchant'] ?? '');
        $gem_markup = $this->build_gems_markup($item_meta['gems'] ?? array(), (string) ($item_meta['region'] ?? ''));

        $meta_markup = '<div class="wgrp-item-meta">';
        if ($is_mirrored) {
            if (! $is_empty && $enchant !== '') {
                $meta_markup .= '<span class="wgrp-item-detail">' . esc_html($enchant) . '</span>';
            }
            if (! $is_empty && $gem_markup !== '') {
                $meta_markup .= $gem_markup;
            }
            $meta_markup .= '<span class="wgrp-ilvl">' . esc_html($item_level) . '</span>';
        } else {
            $meta_markup .= '<span class="wgrp-ilvl">' . esc_html($item_level) . '</span>';
            if (! $is_empty && $enchant !== '') {
                $meta_markup .= '<span class="wgrp-item-detail">' . esc_html($enchant) . '</span>';
            }
            if (! $is_empty && $gem_markup !== '') {
                $meta_markup .= $gem_markup;
            }
        }
        $meta_markup .= '</div>';

        $body = '<div class="wgrp-item-body"><div class="wgrp-slot">' . esc_html($slot_label) . '</div>' . $body_content . $meta_markup . '</div>';
        $row_inner = $is_mirrored ? $body . $icon_markup : $icon_markup . $body;

        if ($is_empty || $href === '#') {
            return '<div class="' . esc_attr($classes) . '">' . $row_inner . '</div>';
        }

        $anchor_classes = 'wgrp-item-anchor' . ($is_mirrored ? ' wgrp-item-anchor-mirrored' : '');
        $wowhead_attribute = $wowhead_data !== '' ? ' data-wowhead="' . esc_attr($wowhead_data) . '"' : '';
        $wowhead_display_attributes = ' data-wh-rename-link="false" data-wh-iconize-link="false"';

        return '<div class="' . esc_attr($classes) . '"><a class="' . esc_attr($anchor_classes) . '" href="' . esc_url($href) . '"' . $wowhead_attribute . $wowhead_display_attributes . ' target="_blank" rel="noopener noreferrer">' . $row_inner . '</a></div>';
    }

    private function get_slot_short_label(string $slot): string
    {
        $map = array(
            'HEAD' => 'HD',
            'NECK' => 'NK',
            'SHOULDER' => 'SH',
            'BACK' => 'BK',
            'CHEST' => 'CH',
            'SHIRT' => 'SR',
            'TABARD' => 'TB',
            'WRIST' => 'WR',
            'HANDS' => 'HN',
            'WAIST' => 'WS',
            'LEGS' => 'LG',
            'FEET' => 'FT',
            'FINGER_1' => 'R1',
            'FINGER_2' => 'R2',
            'TRINKET_1' => 'T1',
            'TRINKET_2' => 'T2',
            'MAIN_HAND' => 'MH',
            'OFF_HAND' => 'OH',
        );

        return (string) ($map[$slot] ?? 'SL');
    }

    private function get_empty_slot_icon_url(string $slot): string
    {
        $map = array(
            'HEAD' => 'helm.png',
            'NECK' => 'neck.png',
            'SHOULDER' => 'shoulders.png',
            'BACK' => 'chest.png',
            'CHEST' => 'chest.png',
            'SHIRT' => 'shirt.png',
            'TABARD' => 'tabard.png',
            'WRIST' => 'wrists.png',
            'HANDS' => 'gloves.png',
            'WAIST' => 'waist.png',
            'LEGS' => 'legs.png',
            'FEET' => 'feet.png',
            'FINGER_1' => 'ring.png',
            'FINGER_2' => 'ring.png',
            'TRINKET_1' => 'trinket.png',
            'TRINKET_2' => 'trinket.png',
            'MAIN_HAND' => 'main-hand.png',
            'OFF_HAND' => 'off-hand.png',
        );

        if (! isset($map[$slot])) {
            return '';
        }

        return GUILROIM_PLUGIN_URL . 'assets/images/empty-slots/' . rawurlencode($map[$slot]);
    }

    private function get_character_theme(string $class_name): array
    {
        $map = array(
            'warrior' => array('accent' => '#c69b6d', 'soft' => 'rgba(198,155,109,.26)', 'border' => 'rgba(198,155,109,.28)'),
            'paladin' => array('accent' => '#f48cba', 'soft' => 'rgba(244,140,186,.24)', 'border' => 'rgba(244,140,186,.28)'),
            'hunter' => array('accent' => '#aad372', 'soft' => 'rgba(170,211,114,.24)', 'border' => 'rgba(170,211,114,.28)'),
            'rogue' => array('accent' => '#fff468', 'soft' => 'rgba(255,244,104,.22)', 'border' => 'rgba(255,244,104,.26)'),
            'priest' => array('accent' => '#f2f2f2', 'soft' => 'rgba(255,255,255,.18)', 'border' => 'rgba(255,255,255,.2)'),
            'death knight' => array('accent' => '#c41e3a', 'soft' => 'rgba(196,30,58,.24)', 'border' => 'rgba(196,30,58,.28)'),
            'shaman' => array('accent' => '#0070dd', 'soft' => 'rgba(0,112,221,.24)', 'border' => 'rgba(0,112,221,.28)'),
            'mage' => array('accent' => '#3fc7eb', 'soft' => 'rgba(63,199,235,.24)', 'border' => 'rgba(63,199,235,.28)'),
            'warlock' => array('accent' => '#8788ee', 'soft' => 'rgba(135,136,238,.24)', 'border' => 'rgba(135,136,238,.28)'),
            'monk' => array('accent' => '#00ff98', 'soft' => 'rgba(0,255,152,.24)', 'border' => 'rgba(0,255,152,.28)'),
            'druid' => array('accent' => '#ff7c0a', 'soft' => 'rgba(255,124,10,.24)', 'border' => 'rgba(255,124,10,.28)'),
            'demon hunter' => array('accent' => '#a330c9', 'soft' => 'rgba(163,48,201,.24)', 'border' => 'rgba(163,48,201,.28)'),
            'evoker' => array('accent' => '#33937f', 'soft' => 'rgba(51,147,127,.24)', 'border' => 'rgba(51,147,127,.28)'),
        );

        $key = strtolower(trim($class_name));
        return $map[$key] ?? array('accent' => '#f0c75d', 'soft' => 'rgba(240,199,93,.24)', 'border' => 'rgba(240,199,93,.26)');
    }

    private function get_profile_background_url(string $class_name): string
    {
        $class_slug = sanitize_title($class_name);
        $extensions = array('webp', 'jpg', 'jpeg', 'png');

        foreach ($extensions as $extension) {
            $candidate_path = GUILROIM_PLUGIN_DIR . 'assets/images/profile-backgrounds/' . $class_slug . '.' . $extension;
            if ($class_slug !== '' && file_exists($candidate_path)) {
                return GUILROIM_PLUGIN_URL . 'assets/images/profile-backgrounds/' . rawurlencode($class_slug) . '.' . rawurlencode($extension);
            }
        }

        foreach ($extensions as $extension) {
            $default_path = GUILROIM_PLUGIN_DIR . 'assets/images/profile-backgrounds/default.' . $extension;
            if (file_exists($default_path)) {
                return GUILROIM_PLUGIN_URL . 'assets/images/profile-backgrounds/default.' . rawurlencode($extension);
            }
        }

        return '';
    }

    private function normalize_faction(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'horde') {
            return 'horde';
        }
        if ($value === 'alliance') {
            return 'alliance';
        }

        return '';
    }

    private function get_faction_icon_url(string $faction): string
    {
        $extensions = array('webp', 'png', 'jpg', 'jpeg');
        foreach ($extensions as $extension) {
            $local_file = GUILROIM_PLUGIN_DIR . 'assets/images/factions/' . $faction . '.' . $extension;
            if ($faction !== '' && file_exists($local_file)) {
                return GUILROIM_PLUGIN_URL . 'assets/images/factions/' . rawurlencode($faction) . '.' . rawurlencode($extension);
            }
        }

        return '';
    }

    private function build_item_meta(array $item): array
    {
        return array(
            'enchant' => $this->extract_item_enchant($item),
            'gems' => $this->extract_item_gems($item),
        );
    }

    private function extract_item_enchant(array $item): string
    {
        $sources = array(
            $item['enchantments'] ?? null,
            $item['permanent_enchant'] ?? null,
            $item['temporary_enchant'] ?? null,
            $item['weapon_enchant'] ?? null,
        );

        foreach ($sources as $source) {
            $value = $this->extract_first_named_value($source, array('display_string', 'name', 'description', 'enchantment_display_string'));
            if ($value !== '') {
                $clean = $this->normalize_enchant_text($value);
                if ($clean !== '') {
                    return 'Enchanted: ' . $clean;
                }
            }
        }

        return '';
    }

    private function extract_item_gems(array $item): array
    {
        $sockets = $item['sockets'] ?? null;
        if (! is_array($sockets)) {
            return array();
        }

        $gems = array();
        foreach ($sockets as $socket) {
            if (! is_array($socket)) {
                continue;
            }

            $item_id = absint($socket['item']['id'] ?? $socket['gem']['item']['id'] ?? $socket['gem']['id'] ?? 0);
            $name = $this->normalize_plain_text(
                $this->extract_first_named_value(
                    array(
                        $socket['item']['name'] ?? '',
                        $socket['gem']['name'] ?? '',
                        $socket['gem']['item']['name'] ?? '',
                        $socket['display_string'] ?? '',
                    ),
                    array('name', 'display_string', 'description')
                )
            );
            $type = strtolower((string) ($socket['socket_type']['type'] ?? $socket['socket_type']['name'] ?? $socket['type'] ?? ''));
            $color = $this->map_socket_color($type, $name);
            $icon_url = trim((string) ($socket['guilroim_icon_url'] ?? $socket['icon_url'] ?? $socket['item']['icon_url'] ?? $socket['gem']['icon_url'] ?? ''));

            if ($item_id <= 0 && ($name === '' || preg_match('/\\b(empty|socket)\\b/i', $name))) {
                continue;
            }

            if ($name !== '' || $item_id > 0) {
                $gems[] = array(
                    'name' => $name,
                    'item_id' => $item_id,
                    'color' => $color,
                    'icon_url' => $icon_url,
                );
            }
        }

        return $gems;
    }

    private function extract_first_named_value($value, array $preferred_keys): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach ($preferred_keys as $preferred_key) {
            foreach ($value as $key => $entry) {
                if ((string) $key !== $preferred_key) {
                    continue;
                }

                $resolved = $this->extract_first_named_value($entry, $preferred_keys);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }

        foreach ($value as $entry) {
            $resolved = $this->extract_first_named_value($entry, $preferred_keys);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return '';
    }

    private function build_gems_markup(array $gems, string $region = ''): string
    {
        $markup = '';
        foreach ($gems as $gem) {
            if (! is_array($gem)) {
                continue;
            }

            $markup .= $this->build_gem_markup($gem, $region);
        }

        return $markup;
    }

    private function build_gem_markup(array $gem, string $region = ''): string
    {
        $name = (string) ($gem['name'] ?? '');
        $item_id = absint($gem['item_id'] ?? 0);
        $custom_icon = trim((string) ($gem['icon_url'] ?? ''));
        if ($custom_icon === '') {
            $custom_icon = $this->get_custom_gem_icon_url($name, $item_id);
        }
        if ($custom_icon === '') {
            $custom_icon = $this->get_fallback_gem_icon_url((string) ($gem['color'] ?? ''), $name);
        }
        if ($custom_icon !== '') {
            $icon = '<img class="wgrp-gem-image" src="' . esc_url($custom_icon) . '" alt="' . esc_attr($name) . '" title="' . esc_attr($name) . '" loading="lazy" width="14" height="14" />';
        } else {
            $color = sanitize_html_class((string) ($gem['color'] ?? 'prismatic'));
            $icon = '<span class="wgrp-gem-chip wgrp-gem-color-' . esc_attr($color) . '" title="' . esc_attr($name) . '"></span>';
        }

        if ($name !== '' || $item_id > 0) {
            $title = $name !== '' ? $name : __('Equipped gem', 'guild-roster-importer-for-wow');
            return '<span class="wgrp-gem-icon-link" title="' . esc_attr($title) . '">' . $icon . '</span>';
        }

        return '';
    }

    private function normalize_enchant_text(string $value): string
    {
        $value = $this->normalize_plain_text($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/^enchanted:\s*/i', '', $value);
        $value = preg_replace('/^enchant:\s*/i', '', $value);
        $value = preg_replace('/^enchant\s+[a-z ]+\s*-\s*/i', '', $value);

        if (str_contains($value, ' - ')) {
            $parts = preg_split('/\s*-\s*/', $value);
            if (is_array($parts) && ! empty($parts)) {
                $value = (string) end($parts);
            }
        }

        return trim($value);
    }

    private function extract_character_title(array $summary, string $name): array
    {
        $title_source = $summary['active_title']['display_string'] ?? $summary['active_title']['name'] ?? '';
        if (! is_string($title_source) || trim($title_source) === '') {
            return array('position' => '', 'title' => '');
        }

        $title_source = trim($title_source);
        if (preg_match('/\{name\}/i', $title_source)) {
            if (preg_match('/^\s*\{name\}/i', $title_source)) {
                return array('position' => 'suffix', 'title' => trim(preg_replace('/\{name\}/i', '', $title_source)));
            }

            return array('position' => 'prefix', 'title' => trim(preg_replace('/\{name\}/i', '', $title_source)));
        }
        if (str_contains($title_source, '%s')) {
            if (str_starts_with($title_source, '%s')) {
                return array('position' => 'suffix', 'title' => trim(str_replace('%s', '', $title_source)));
            }

            return array('position' => 'prefix', 'title' => trim(str_replace('%s', '', $title_source)));
        }

        $lower_name = strtolower($name);
        $lower_title = strtolower($title_source);
        if ($lower_name !== '' && str_contains($lower_title, $lower_name)) {
            $title_only = trim(str_ireplace($name, '', $title_source));
            if (str_starts_with($lower_title, $lower_name)) {
                return array('position' => 'suffix', 'title' => $title_only);
            }

            return array('position' => 'prefix', 'title' => $title_only);
        }

        return array('position' => 'prefix', 'title' => $title_source);
    }

    private function format_character_name_display(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return (string) mb_convert_case(mb_strtolower($normalized, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords(strtolower($normalized));
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

    private function get_item_quality_class(array $item): string
    {
        $quality = strtolower(trim((string) ($item['quality']['type'] ?? $item['quality']['name'] ?? $item['inventory_type']['quality']['type'] ?? '')));

        $map = array(
            'poor' => 'wgrp-item-quality-poor',
            'common' => 'wgrp-item-quality-common',
            'uncommon' => 'wgrp-item-quality-uncommon',
            'rare' => 'wgrp-item-quality-rare',
            'epic' => 'wgrp-item-quality-epic',
            'legendary' => 'wgrp-item-quality-legendary',
            'artifact' => 'wgrp-item-quality-artifact',
            'heirloom' => 'wgrp-item-quality-heirloom',
        );

        return $map[$quality] ?? 'wgrp-item-quality-common';
    }

    private function build_wowhead_item_url(array $item, array $item_meta): string
    {
        $item_id = absint($item['item']['id'] ?? 0);
        if ($item_id <= 0) {
            return '#';
        }

        return 'https://www.wowhead.com/item=' . $item_id;
    }

    private function build_wowhead_item_tooltip_data(array $item, array $item_meta): string
    {
        $query = array();

        $bonus_lists = $item['bonus_list'] ?? $item['bonus_lists'] ?? array();
        if (is_array($bonus_lists) && ! empty($bonus_lists)) {
            $bonus_ids = array_values(array_filter(array_map('absint', $bonus_lists)));
            if (! empty($bonus_ids)) {
                $query['bonus'] = implode(':', $bonus_ids);
            }
        }

        $gem_ids = array();
        foreach ((array) ($item_meta['gems'] ?? array()) as $gem) {
            if (! is_array($gem)) {
                continue;
            }

            $gem_item_id = absint($gem['item_id'] ?? 0);
            if ($gem_item_id > 0) {
                $gem_ids[] = (string) $gem_item_id;
            }
        }

        if (! empty($gem_ids)) {
            $query['gems'] = implode(':', $gem_ids);
        }

        $enchantment_id = $this->extract_item_enchantment_id($item);
        if ($enchantment_id > 0) {
            $query['ench'] = (string) $enchantment_id;
        }

        return ! empty($query) ? html_entity_decode(http_build_query($query, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES, 'UTF-8') : '';
    }

    private function normalize_plain_text(string $value): string
    {
        $value = preg_replace('/\|A:[^|]+\|a/', '', $value);
        $value = preg_replace('/\|c[0-9a-fA-F]{8}/', '', $value);
        $value = str_replace('|r', '', $value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return $value;
    }

    private function get_custom_gem_icon_url(string $name, int $item_id = 0): string
    {
        $name = strtolower(trim($name));
        $item_id = absint($item_id);

        $item_id_map = array(
            240893 => 'flawless-versatile-peridot.jpg',
            240894 => 'flawless-versatile-peridot.jpg',
            240901 => 'flawless-versatile-amethyst.jpg',
            240902 => 'flawless-versatile-amethyst.jpg',
            240909 => 'flawless-versatile-garnet.jpg',
            240910 => 'flawless-versatile-garnet.jpg',
            240911 => 'flawless-versatile-lapis.jpg',
            240912 => 'flawless-versatile-lapis.jpg',
        );

        if (isset($item_id_map[$item_id])) {
            $file_name = $item_id_map[$item_id];
            $path = GUILROIM_PLUGIN_DIR . 'assets/images/gems/' . $file_name;
            return file_exists($path) ? GUILROIM_PLUGIN_URL . 'assets/images/gems/' . rawurlencode($file_name) : '';
        }

        if ($name === '') {
            return '';
        }

        $map = array(
            'flawless versatile peridot' => 'flawless-versatile-peridot.jpg',
            'flawless versatile lapis' => 'flawless-versatile-lapis.jpg',
            'flawless versatile amethyst' => 'flawless-versatile-amethyst.jpg',
            'flawless versatile garnet' => 'flawless-versatile-garnet.jpg',
            'peridot' => 'peridot.jpg',
            'lapis' => 'lapis.jpg',
            'amethyst' => 'amethyst.jpg',
            'garnet' => 'garnet.jpg',
            'indecipherable eversong diamond' => 'indecipherable-eversong-diamond.jpg',
            'powerful eversong diamond' => 'powerful-eversong-diamond.jpg',
            'stoic eversong diamond' => 'stoic-eversong-diamond.jpg',
            'telluric eversong diamond' => 'telluric-eversong-diamond.jpg',
        );

        foreach ($map as $needle => $file_name) {
            if (str_contains($name, $needle)) {
                $path = GUILROIM_PLUGIN_DIR . 'assets/images/gems/' . $file_name;
                return file_exists($path) ? GUILROIM_PLUGIN_URL . 'assets/images/gems/' . rawurlencode($file_name) : '';
            }
        }

        return '';
    }

    private function get_fallback_gem_icon_url(string $color, string $name = ''): string
    {
        $color = sanitize_key($color);
        $name = strtolower(trim($name));
        if ($name !== '' && str_contains($name, 'empty')) {
            return '';
        }

        $map = array(
            'red' => 'garnet.jpg',
            'blue' => 'lapis.jpg',
            'yellow' => 'telluric-eversong-diamond.jpg',
            'green' => 'peridot.jpg',
            'orange' => 'powerful-eversong-diamond.jpg',
            'purple' => 'amethyst.jpg',
            'meta' => 'indecipherable-eversong-diamond.jpg',
            'prismatic' => 'stoic-eversong-diamond.jpg',
        );

        $file_name = (string) ($map[$color] ?? '');
        if ($file_name === '') {
            return '';
        }

        $path = GUILROIM_PLUGIN_DIR . 'assets/images/gems/' . $file_name;
        return file_exists($path) ? GUILROIM_PLUGIN_URL . 'assets/images/gems/' . rawurlencode($file_name) : '';
    }

    private function get_local_zone_image_url(string $slug): string
    {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return '';
        }

        foreach (array('webp', 'jpg', 'jpeg', 'png') as $extension) {
            $file_name = $slug . '-small.' . $extension;
            $path = GUILROIM_PLUGIN_DIR . 'assets/images/zones/' . $file_name;

            if (file_exists($path)) {
                return GUILROIM_PLUGIN_URL . 'assets/images/zones/' . rawurlencode($file_name);
            }
        }

        return '';
    }

    private function map_socket_color(string $type, string $name): string
    {
        $map = array(
            'red' => 'red',
            'blue' => 'blue',
            'yellow' => 'yellow',
            'green' => 'green',
            'orange' => 'orange',
            'purple' => 'purple',
            'meta' => 'meta',
            'prismatic' => 'prismatic',
            'cogwheel' => 'blue',
            'hydraulic' => 'blue',
        );

        foreach ($map as $needle => $color) {
            if (($type !== '' && str_contains($type, $needle)) || ($name !== '' && str_contains($name, $needle))) {
                return $color;
            }
        }

        return 'prismatic';
    }

    private function extract_item_enchantment_id(array $item): int
    {
        $sources = array(
            $item['enchantments'] ?? null,
            $item['permanent_enchant'] ?? null,
            $item['temporary_enchant'] ?? null,
            $item['weapon_enchant'] ?? null,
        );

        foreach ($sources as $source) {
            $id = $this->extract_first_numeric_id($source);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private function extract_first_numeric_id($value): int
    {
        if (is_numeric($value)) {
            return absint($value);
        }

        if (! is_array($value)) {
            return 0;
        }

        foreach (array('id', 'spell_id', 'enchantment_id') as $preferred_key) {
            if (isset($value[$preferred_key]) && is_numeric($value[$preferred_key])) {
                return absint($value[$preferred_key]);
            }
        }

        foreach ($value as $entry) {
            $id = $this->extract_first_numeric_id($entry);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }
}
