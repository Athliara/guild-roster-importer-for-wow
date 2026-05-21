<?php

if (! defined('ABSPATH')) {
    exit;
}

class WoW_Guild_Roster_Shortcode
{
    private WoW_Guild_Roster_API $api;
    private WoW_Guild_Roster_Settings $settings;
    private string $current_page_url = '';

    public function __construct(WoW_Guild_Roster_API $api, WoW_Guild_Roster_Settings $settings)
    {
        $this->api = $api;
        $this->settings = $settings;
    }

    public function register_assets(): void
    {
        $style_path = GUILROIM_PLUGIN_DIR . 'assets/css/roster-themes.css';
        $script_path = GUILROIM_PLUGIN_DIR . 'assets/js/roster-interactions.js';
        $style_version = file_exists($style_path) ? (string) filemtime($style_path) : GUILROIM_PLUGIN_VERSION;
        $script_version = file_exists($script_path) ? (string) filemtime($script_path) : GUILROIM_PLUGIN_VERSION;

        wp_register_style('guilroim-roster-themes', GUILROIM_PLUGIN_URL . 'assets/css/roster-themes.css', array(), $style_version);
        wp_register_script('guilroim-roster-interactions', GUILROIM_PLUGIN_URL . 'assets/js/roster-interactions.js', array(), $script_version, true);
    }

    public function register_shortcode(): void
    {
        add_shortcode('guilroim_roster', array($this, 'render_shortcode'));
    }

    public function render_shortcode(array $atts = array()): string
    {
        wp_enqueue_style('guilroim-roster-themes');
        wp_enqueue_script('guilroim-roster-interactions');
        $this->current_page_url = $this->get_current_page_url();

        $options = $this->settings->get_options();
        $requested_display = sanitize_key((string) ($atts['display'] ?? ''));
        $display_config = $requested_display !== '' ? $this->settings->get_display_config($requested_display) : array();
        $selected_characters = $this->normalize_selected_characters($display_config['single_characters'] ?? array());
        $atts = shortcode_atts(
            array(
                'display' => '',
                'theme' => $options['theme'] ?? 'dark-obsidian',
                'show_count' => 'true',
                'title' => (string) ($display_config['title'] ?? ''),
                'enrich' => 'false',
                'guild_name' => '',
                'server' => '',
                'region' => '',
                'ranks_to_display' => (string) ($display_config['ranks_to_display'] ?? ($options['ranks_to_display'] ?? '')),
                'sort_by' => (string) ($display_config['sort_by'] ?? ($options['sort_by'] ?? 'rank')),
                'sort_order' => (string) ($display_config['sort_order'] ?? ($options['sort_order'] ?? 'asc')),
                'page_size' => (string) ($display_config['results_per_page'] ?? ($options['results_per_page'] ?? 25)),
            ),
            $atts,
            'guilroim_roster'
        );

        $theme = sanitize_key((string) ($atts['theme'] ?? 'dark-obsidian'));
        $sort_by = $this->normalize_sort_by((string) ($atts['sort_by'] ?? 'rank'));
        $sort_order = $this->normalize_sort_order((string) ($atts['sort_order'] ?? 'asc'));
        $page_size = $this->normalize_page_size((string) ($atts['page_size'] ?? '25'));
        $enrich = filter_var((string) ($atts['enrich'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);

        $runtime_options = $options;
        $guild_name = trim((string) ($atts['guild_name'] ?? ''));
        $server = trim((string) ($atts['server'] ?? ''));
        $region = WoW_Guild_Roster_Settings::normalize_region((string) ($atts['region'] ?? ''), '');
        $ranks_to_display = trim((string) ($atts['ranks_to_display'] ?? ''));

        if ($guild_name !== '') {
            $runtime_options['guild_name'] = sanitize_text_field($guild_name);
        }
        if ($server !== '') {
            $runtime_options['server'] = sanitize_text_field($server);
        }
        if ($region !== '') {
            $runtime_options['region'] = $region;
        }
        if ($ranks_to_display !== '') {
            $runtime_options['ranks_to_display'] = preg_replace('/[^0-9,\s]/', '', $ranks_to_display);
        }
        $selected_ranks = $this->parse_ranks($ranks_to_display !== '' ? $ranks_to_display : (string) ($runtime_options['ranks_to_display'] ?? ''));
        if (! empty($selected_characters)) {
            // Fetch the full roster first so displays can include rank-based members plus explicitly added members.
            $runtime_options['ranks_to_display'] = '';
        }
        $runtime_options['sort_by'] = $sort_by;
        $runtime_options['sort_order'] = $sort_order;
        $runtime_options['skip_profile_enrichment'] = ! $enrich;
        $runtime_options['skip_mythic_score_fetch'] = true;

        wp_localize_script(
            'guilroim-roster-interactions',
            'guilroimAvatarApi',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('guilroim_avatar_nonce'),
                'cacheTtlMs' => 7 * DAY_IN_SECONDS * 1000,
                'mythicCacheTtlMs' => 12 * HOUR_IN_SECONDS * 1000,
                'enableLiveMythic' => false,
            )
        );

        $roster_data = $this->api->get_roster($runtime_options);

        if (is_wp_error($roster_data)) {
            if (current_user_can('manage_options')) {
                return '<div class="wgr-error">' . esc_html($roster_data->get_error_message()) . '</div>';
            }
            return '<div class="wgr-error">' . esc_html__('Guild roster is currently unavailable.', 'guild-roster-importer-for-wow') . '</div>';
        }

        $members = $roster_data['members'] ?? array();
        if (! empty($selected_characters) || ! empty($selected_ranks)) {
            $members = array_values(array_filter($members, function ($member) use ($selected_characters, $selected_ranks, $roster_data, $options): bool {
                $member_name = strtolower(trim((string) ($member['name'] ?? '')));
                $member_realm = strtolower(trim((string) ($member['realm'] ?? '')));
                $member_realm_slug = strtolower(trim((string) ($member['realm_slug'] ?? '')));
                $rank = isset($member['rank']) ? absint($member['rank']) : null;

                if ($member_realm === '') {
                    $member_realm = strtolower(trim((string) ($roster_data['server'] ?? ($options['server'] ?? ''))));
                }
                if ($member_realm_slug === '') {
                    $member_realm_slug = sanitize_title($member_realm);
                }

                $matches_character = false;
                foreach ($selected_characters as $selected_character) {
                    $selected_name = $selected_character['name'] ?? '';
                    $selected_realm = $selected_character['realm'] ?? '';
                    if ($selected_name === '') {
                        continue;
                    }
                    if ($member_name !== $selected_name) {
                        continue;
                    }
                    if ($selected_realm === '' || $member_realm === $selected_realm || $member_realm_slug === sanitize_title($selected_realm)) {
                        $matches_character = true;
                        break;
                    }
                }

                $matches_rank = ! empty($selected_ranks) && $rank !== null && in_array($rank, $selected_ranks, true);

                if (! empty($selected_characters) && ! empty($selected_ranks)) {
                    return $matches_character || $matches_rank;
                }
                if (! empty($selected_characters)) {
                    return $matches_character;
                }

                return $matches_rank;
            }));
            $roster_data['members'] = $members;
        }
        $count = count($members);
        $title = trim((string) $atts['title']);
        if ($title === '') {
            $title = sprintf('%s - %s (%s)', (string) ($roster_data['guild_name'] ?? ''), (string) ($roster_data['server'] ?? ''), (string) ($roster_data['region'] ?? ''));
        }

        $show_count = filter_var($atts['show_count'], FILTER_VALIDATE_BOOLEAN);
        $banner_url = trim((string) ($roster_data['guild_banner_url'] ?? ''));
        if ($banner_url === '') {
            $banner_url = GUILROIM_PLUGIN_URL . 'assets/images/guild-banner.svg';
        }
        $banner_frame_url = GUILROIM_PLUGIN_URL . 'assets/images/guild-banner-frame.png';

        ob_start();
        ?>
        <div class="wgr-roster wgr-theme-<?php echo esc_attr($theme); ?>" data-page-size="<?php echo esc_attr((string) $page_size); ?>" data-initial-sort-by="<?php echo esc_attr($sort_by); ?>" data-initial-sort-order="<?php echo esc_attr($sort_order); ?>">
            <div class="wgr-header">
                <div class="wgr-title-wrap">
                    <span class="wgr-guild-banner-wrap" aria-hidden="true">
                        <img class="wgr-guild-banner" src="<?php echo esc_url($banner_url); ?>" alt="<?php esc_attr_e('Guild banner', 'guild-roster-importer-for-wow'); ?>" />
                        <img class="wgr-guild-banner-frame" src="<?php echo esc_url($banner_frame_url); ?>" alt="" />
                    </span>
                    <h3 class="wgr-title"><?php echo esc_html($title); ?></h3>
                </div>
                <?php if ($show_count) : ?>
                    <span class="wgr-count"><?php echo esc_html((string) $count); ?> <?php esc_html_e('members', 'guild-roster-importer-for-wow'); ?></span>
                <?php endif; ?>
            </div>

            <?php if (empty($members)) : ?>
                <p class="wgr-empty">
                    <?php
                    echo ! empty($selected_characters)
                        ? esc_html__('No saved characters matched this display.', 'guild-roster-importer-for-wow')
                        : esc_html__('No members matched the selected ranks.', 'guild-roster-importer-for-wow');
                    ?>
                </p>
            <?php else : ?>
                <div class="wgr-table-wrap">
                    <div class="wgr-filter-bar">
                        <div class="wgr-filter-grid">
                            <input type="text" class="wgr-filter-input wgr-filter-name" placeholder="<?php esc_attr_e('Search name', 'guild-roster-importer-for-wow'); ?>" />
                            <?php
                            $class_filter_markup = $this->render_filter_multiselect(
                                'class',
                                __('All Classes', 'guild-roster-importer-for-wow'),
                                __('Class', 'guild-roster-importer-for-wow'),
                                __('Classes', 'guild-roster-importer-for-wow'),
                                array(
                                    'Death Knight',
                                    'Demon Hunter',
                                    'Druid',
                                    'Evoker',
                                    'Hunter',
                                    'Mage',
                                    'Monk',
                                    'Paladin',
                                    'Priest',
                                    'Rogue',
                                    'Shaman',
                                    'Warlock',
                                    'Warrior',
                                )
                            );
                            echo wp_kses($class_filter_markup, $this->get_allowed_filter_markup());

                            $role_filter_markup = $this->render_filter_multiselect(
                                'role',
                                __('All Roles', 'guild-roster-importer-for-wow'),
                                __('Role', 'guild-roster-importer-for-wow'),
                                __('Roles', 'guild-roster-importer-for-wow'),
                                array(
                                    __('Tank', 'guild-roster-importer-for-wow'),
                                    __('Healer', 'guild-roster-importer-for-wow'),
                                    __('Damage', 'guild-roster-importer-for-wow'),
                                )
                            );
                            echo wp_kses($role_filter_markup, $this->get_allowed_filter_markup());
                            ?>
                            <span class="wgr-filter-spacer" aria-hidden="true"></span>
                            <span class="wgr-filter-spacer" aria-hidden="true"></span>
                            <span class="wgr-filter-spacer" aria-hidden="true"></span>
                            <span class="wgr-filter-spacer" aria-hidden="true"></span>
                        </div>
                    </div>
                    <table class="wgr-table">
                        <colgroup>
                            <col class="wgr-col-name" />
                            <col class="wgr-col-class" />
                            <col class="wgr-col-role" />
                            <col class="wgr-col-race" />
                            <col class="wgr-col-level" />
                            <col class="wgr-col-rank" />
                            <col class="wgr-col-mythic-score" />
                        </colgroup>
                        <thead>
                            <tr>
                                <?php echo wp_kses($this->render_sortable_header('name', __('Name', 'guild-roster-importer-for-wow')), $this->get_allowed_table_header_markup()); ?>
                                <?php echo wp_kses($this->render_sortable_header('class', __('Class', 'guild-roster-importer-for-wow')), $this->get_allowed_table_header_markup()); ?>
                                <?php echo wp_kses($this->render_sortable_header('role', __('Role', 'guild-roster-importer-for-wow')), $this->get_allowed_table_header_markup()); ?>
                                <?php echo wp_kses($this->render_sortable_header('race', __('Race', 'guild-roster-importer-for-wow')), $this->get_allowed_table_header_markup()); ?>
                                <?php echo wp_kses($this->render_sortable_header('level', __('Level', 'guild-roster-importer-for-wow')), $this->get_allowed_table_header_markup()); ?>
                                <?php echo wp_kses($this->render_sortable_header('rank', __('Rank', 'guild-roster-importer-for-wow')), $this->get_allowed_table_header_markup()); ?>
                                <?php echo wp_kses($this->render_sortable_header('mythic_score', __('M+ Score', 'guild-roster-importer-for-wow')), $this->get_allowed_table_header_markup()); ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member) : ?>
                                <?php
                                $member_name = (string) ($member['name'] ?? '');
                                $member_realm = trim((string) ($member['realm'] ?? ''));
                                $member_realm_slug = trim((string) ($member['realm_slug'] ?? ''));
                                $member_region = strtolower((string) (($roster_data['region'] ?? '') !== '' ? $roster_data['region'] : ($options['region'] ?? '')));
                                if ($member_realm === '') {
                                    $member_realm = trim((string) ($roster_data['server'] ?? ''));
                                }
                                if ($member_realm === '') {
                                    $member_realm = trim((string) ($options['server'] ?? ''));
                                }
                                if ($member_realm_slug === '') {
                                    $member_realm_slug = sanitize_title($member_realm);
                                }
                                $class_id = isset($member['class_id']) ? absint($member['class_id']) : 0;
                                $race_id = isset($member['race_id']) ? absint($member['race_id']) : 0;

                                $class_name = trim((string) ($member['class'] ?? ''));
                                if ($class_name === '') {
                                    $class_name = $this->get_class_name_from_id($class_id);
                                }
                                if ($class_name === '') {
                                    $class_name = __('Unknown Class', 'guild-roster-importer-for-wow');
                                }

                                $role = trim((string) ($member['role'] ?? ''));
                                if ($role === '') {
                                    $role = 'Damage';
                                }

                                $race_name = $this->normalize_race_display_name((string) ($member['race'] ?? ''));
                                if ($race_name === '') {
                                    $race_name = $this->get_race_name_from_id($race_id);
                                }
                                if ($race_name === '') {
                                    $race_name = '-';
                                }

                                $level = (int) ($member['level'] ?? 0);
                                $rank = (int) ($member['rank'] ?? 0);
                                $cached_mythic_score = $this->api->get_cached_character_mythic_score($member_name, $member_realm_slug, $member_region);
                                $mythic_score = $cached_mythic_score !== null ? $cached_mythic_score : (int) ($member['mythic_score'] ?? 0);
                                $mythic_score_class = $this->get_mythic_score_class($mythic_score);
                                $class_icon = GUILROIM_PLUGIN_URL . 'assets/images/classes/' . $this->get_class_icon_filename($class_name, $class_id);
                                $gender = strtolower(trim((string) ($member['gender'] ?? '')));
                                $race_icon = GUILROIM_PLUGIN_URL . 'assets/images/races/' . $this->get_race_icon_filename($race_name, $race_id, $gender);
                                $role_icon = $this->get_role_icon_url($role);
                                $avatar_placeholder = GUILROIM_PLUGIN_URL . 'assets/images/avatar-placeholder.svg';
                                $avatar_url = trim((string) ($member['avatar_url'] ?? ''));
                                if ($avatar_url === '') {
                                    $avatar_url = $avatar_placeholder;
                                }
                                $class_slug = 'wgr-class-' . sanitize_html_class(strtolower(str_replace(' ', '-', $class_name)));
                                $character_url = add_query_arg(
                                    array(
                                        'guilroim_character' => $member_name,
                                        'guilroim_realm_slug' => $member_realm_slug,
                                        'guilroim_region' => $member_region,
                                    ),
                                    $this->current_page_url
                                );
                                $mythic_loaded = $cached_mythic_score !== null || $mythic_score > 0;
                                ?>
                                <tr data-name="<?php echo esc_attr(strtolower($member_name)); ?>" data-class="<?php echo esc_attr($class_name); ?>" data-role="<?php echo esc_attr($role); ?>">
                                    <td data-sort-value="<?php echo esc_attr($member_name); ?>"><span class="wgr-name-wrap"><img class="wgr-avatar-icon" src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($member_name); ?>" loading="lazy" width="30" height="30" data-avatar-character="<?php echo esc_attr($member_name); ?>" data-avatar-realm="<?php echo esc_attr($member_realm_slug); ?>" data-avatar-region="<?php echo esc_attr($member_region); ?>" data-avatar-placeholder="<?php echo esc_url($avatar_placeholder); ?>" /><a class="wgr-char-link <?php echo esc_attr($class_slug); ?>" href="<?php echo esc_url($character_url); ?>"><?php echo esc_html($member_name); ?></a></span></td>
                                    <td><span class="wgr-class-wrap"><img class="wgr-class-icon" src="<?php echo esc_url($class_icon); ?>" alt="<?php echo esc_attr($class_name); ?>" loading="lazy" width="30" height="30" /><span class="wgr-class-name"><?php echo esc_html($class_name); ?></span></span></td>
                                    <td><span class="wgr-role-wrap"><img class="wgr-role-icon" src="<?php echo esc_url($role_icon); ?>" alt="<?php echo esc_attr($role); ?>" loading="lazy" width="30" height="30" /><span class="wgr-role-name"><?php echo esc_html($role); ?></span></span></td>
                                    <td><span class="wgr-race-wrap"><img class="wgr-race-icon" src="<?php echo esc_url($race_icon); ?>" alt="<?php echo esc_attr($race_name); ?>" loading="lazy" width="30" height="30" /><span class="wgr-race-name"><?php echo esc_html($race_name); ?></span></span></td>
                                    <td data-sort-value="<?php echo esc_attr((string) $level); ?>"><?php echo esc_html((string) $level); ?></td>
                                    <td data-sort-value="<?php echo esc_attr((string) $rank); ?>"><?php echo esc_html((string) $rank); ?></td>
                                    <td data-sort-value="<?php echo esc_attr((string) $mythic_score); ?>"><span class="wgr-mythic-score <?php echo esc_attr($mythic_score_class); ?>" data-mythic-character="<?php echo esc_attr($member_name); ?>" data-mythic-realm="<?php echo esc_attr($member_realm_slug); ?>" data-mythic-region="<?php echo esc_attr($member_region); ?>" data-mythic-loaded="<?php echo $mythic_loaded ? 'true' : 'false'; ?>"><?php echo esc_html($mythic_score > 0 ? number_format_i18n($mythic_score) : '-'); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="wgr-pagination" aria-label="<?php esc_attr_e('Roster pagination', 'guild-roster-importer-for-wow'); ?>">
                    <button type="button" class="wgr-page-btn wgr-page-first"><?php esc_html_e('First', 'guild-roster-importer-for-wow'); ?></button>
                    <button type="button" class="wgr-page-btn wgr-page-prev"><?php esc_html_e('Previous', 'guild-roster-importer-for-wow'); ?></button>
                    <span class="wgr-page-info"></span>
                    <button type="button" class="wgr-page-btn wgr-page-next"><?php esc_html_e('Next', 'guild-roster-importer-for-wow'); ?></button>
                    <button type="button" class="wgr-page-btn wgr-page-last"><?php esc_html_e('Last', 'guild-roster-importer-for-wow'); ?></button>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function normalize_selected_characters(array $selected_characters): array
    {
        $normalized = array();

        foreach ($selected_characters as $selected_character) {
            if (! is_array($selected_character)) {
                continue;
            }

            $name = strtolower(trim((string) ($selected_character['name'] ?? '')));
            $realm = strtolower(trim((string) ($selected_character['realm'] ?? '')));
            if ($name === '') {
                continue;
            }

            $normalized[] = array(
                'name' => $name,
                'realm' => $realm,
            );
        }

        return $normalized;
    }

    private function normalize_sort_by(string $value): string
    {
        $value = sanitize_key($value);
        $allowed = array('name', 'class', 'race', 'role', 'level', 'rank', 'mythic_score');
        if (! in_array($value, $allowed, true)) {
            return 'rank';
        }
        return $value;
    }

    private function get_current_page_url(): string
    {
        $request_uri = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR);
        $request_uri = is_string($request_uri) ? wp_unslash($request_uri) : '/';
        $url = home_url($request_uri !== '' ? $request_uri : '/');

        return (string) remove_query_arg(array('guilroim_character', 'guilroim_realm', 'guilroim_region'), $url);
    }

    private function get_allowed_filter_markup(): array
    {
        return array(
            'div' => array(
                'class' => true,
                'hidden' => true,
                'data-label-all' => true,
                'data-label-singular' => true,
                'data-label-plural' => true,
            ),
            'button' => array(
                'type' => true,
                'class' => true,
                'aria-expanded' => true,
                'aria-hidden' => true,
                'data-filter-action' => true,
            ),
            'label' => array('class' => true),
            'input' => array(
                'type' => true,
                'value' => true,
            ),
            'span' => array(
                'class' => true,
                'aria-hidden' => true,
            ),
        );
    }

    private function get_allowed_table_header_markup(): array
    {
        return array(
            'th' => array('data-column' => true),
            'span' => array(
                'class' => true,
                'aria-hidden' => true,
            ),
            'button' => array(
                'type' => true,
                'class' => true,
                'data-sort' => true,
                'data-order' => true,
                'aria-label' => true,
            ),
        );
    }

    private function normalize_sort_order(string $value): string
    {
        $value = sanitize_key($value);
        if (! in_array($value, array('asc', 'desc'), true)) {
            return 'asc';
        }
        return $value;
    }

    private function normalize_page_size(string $value): int
    {
        $size = absint($value);
        if (! in_array($size, array(10, 25, 50), true)) {
            return 25;
        }
        return $size;
    }

    private function parse_ranks(string $ranks): array
    {
        $ranks = trim($ranks);
        if ($ranks === '') {
            return array();
        }

        $parts = preg_split('/\s*,\s*/', $ranks);
        if (! is_array($parts)) {
            return array();
        }

        $parsed = array();
        foreach ($parts as $part) {
            if ($part === '' || ! is_numeric($part)) {
                continue;
            }
            $parsed[] = absint($part);
        }

        return array_values(array_unique($parsed));
    }

    private function get_mythic_score_class(int $score): string
    {
        if ($score >= 3500) {
            return 'wgr-mythic-score-legendary';
        }
        if ($score >= 3000) {
            return 'wgr-mythic-score-epic';
        }
        if ($score >= 2500) {
            return 'wgr-mythic-score-azure';
        }
        if ($score >= 2000) {
            return 'wgr-mythic-score-rare';
        }
        if ($score >= 1500) {
            return 'wgr-mythic-score-uncommon';
        }
        if ($score >= 1000) {
            return 'wgr-mythic-score-emerald';
        }
        if ($score > 0) {
            return 'wgr-mythic-score-common';
        }

        return 'wgr-mythic-score-none';
    }

    private function render_filter_multiselect(string $filter_key, string $default_label, string $singular_label, string $plural_label, array $options): string
    {
        $items = array();

        foreach ($options as $option) {
            $value = trim((string) $option);
            if ($value === '') {
                continue;
            }

            $items[] = sprintf(
                '<label class="wgr-filter-option"><input type="checkbox" value="%1$s" /> <span>%2$s</span></label>',
                esc_attr($value),
                esc_html($value)
            );
        }

        return sprintf(
            '<div class="wgr-filter-multiselect wgr-filter-%1$s" data-label-all="%2$s" data-label-singular="%3$s" data-label-plural="%4$s">' .
                '<button type="button" class="wgr-filter-toggle" aria-expanded="false"><span class="wgr-filter-toggle-label">%2$s</span><span class="wgr-filter-toggle-caret" aria-hidden="true">&#9662;</span></button>' .
                '<div class="wgr-filter-panel" hidden>' .
                    '<div class="wgr-filter-actions">' .
                        '<button type="button" class="wgr-filter-action" data-filter-action="all">%5$s</button>' .
                        '<button type="button" class="wgr-filter-action" data-filter-action="clear">%6$s</button>' .
                    '</div>' .
                    '<div class="wgr-filter-options">%7$s</div>' .
                '</div>' .
            '</div>',
            esc_attr($filter_key),
            esc_attr($default_label),
            esc_attr($singular_label),
            esc_attr($plural_label),
            esc_html__('All', 'guild-roster-importer-for-wow'),
            esc_html__('Clear', 'guild-roster-importer-for-wow'),
            implode('', $items)
        );
    }

    private function render_sortable_header(string $column_key, string $label): string
    {
        /* translators: %s: roster column label. */
        $sort_ascending_label = sprintf(__('Sort %s ascending', 'guild-roster-importer-for-wow'), $label);
        /* translators: %s: roster column label. */
        $sort_descending_label = sprintf(__('Sort %s descending', 'guild-roster-importer-for-wow'), $label);

        return sprintf(
            '<th data-column="%1$s"><span class="wgr-th-inner"><span class="wgr-th-label">%2$s</span><span class="wgr-sort-controls"><button type="button" class="wgr-sort-btn" data-sort="%1$s" data-order="asc" aria-label="%3$s">&#9650;</button><button type="button" class="wgr-sort-btn" data-sort="%1$s" data-order="desc" aria-label="%4$s">&#9660;</button></span></span></th>',
            esc_attr($column_key),
            esc_html($label),
            esc_attr($sort_ascending_label),
            esc_attr($sort_descending_label)
        );
    }

    private function get_class_icon_filename(string $class_name, int $class_id = 0): string
    {
        $id_map = array(1=>'classicon_warrior.jpg',2=>'classicon_paladin.jpg',3=>'classicon_hunter.jpg',4=>'classicon_rogue.jpg',5=>'classicon_priest.jpg',6=>'classicon_deathknight.jpg',7=>'classicon_shaman.jpg',8=>'classicon_mage.jpg',9=>'classicon_warlock.jpg',10=>'classicon_monk.jpg',11=>'classicon_druid.jpg',12=>'classicon_demonhunter.jpg',13=>'classicon_evoker.jpg');
        if ($class_id > 0 && isset($id_map[$class_id])) {
            return $id_map[$class_id];
        }

        $map = array('death knight'=>'classicon_deathknight.jpg','demon hunter'=>'classicon_demonhunter.jpg','druid'=>'classicon_druid.jpg','evoker'=>'classicon_evoker.jpg','hunter'=>'classicon_hunter.jpg','mage'=>'classicon_mage.jpg','monk'=>'classicon_monk.jpg','paladin'=>'classicon_paladin.jpg','priest'=>'classicon_priest.jpg','rogue'=>'classicon_rogue.jpg','shaman'=>'classicon_shaman.jpg','warlock'=>'classicon_warlock.jpg','warrior'=>'classicon_warrior.jpg');
        $key = strtolower(trim($class_name));
        return $map[$key] ?? 'classicon_warrior.jpg';
    }

    private function get_class_name_from_id(int $class_id): string
    {
        $map = array(1=>'Warrior',2=>'Paladin',3=>'Hunter',4=>'Rogue',5=>'Priest',6=>'Death Knight',7=>'Shaman',8=>'Mage',9=>'Warlock',10=>'Monk',11=>'Druid',12=>'Demon Hunter',13=>'Evoker');
        return (string) ($map[$class_id] ?? '');
    }

    private function get_role_icon_url(string $role): string
    {
        $value = strtolower(trim($role));
        if ($value === 'tank') {
            return GUILROIM_PLUGIN_URL . 'assets/images/roles/tank.png';
        }
        if ($value === 'healer') {
            return GUILROIM_PLUGIN_URL . 'assets/images/roles/healer.png';
        }
        if ($value === 'damage') {
            return GUILROIM_PLUGIN_URL . 'assets/images/roles/damage.png';
        }
        return GUILROIM_PLUGIN_URL . 'assets/images/roles/unknown.svg';
    }

    private function get_race_icon_filename(string $race_name, int $race_id = 0, string $gender = ''): string
    {
        $gender = strtolower(trim($gender));
        if ($gender !== 'female' && $gender !== 'male') {
            $gender = 'male';
        }

        $wowhead_by_id = array(
            1=>'human',2=>'orc',3=>'dwarf',4=>'nightelf',5=>'undead',6=>'tauren',7=>'gnome',8=>'troll',9=>'goblin',
            10=>'bloodelf',11=>'draenei',22=>'worgen',24=>'pandaren',25=>'pandaren',26=>'pandaren',27=>'nightborne',
            28=>'highmountaintauren',29=>'voidelf',30=>'lightforgeddraenei',31=>'zandalaritroll',32=>'kultiran',
            34=>'darkirondwarf',35=>'vulpera',36=>'magharorc',37=>'mechagnome',52=>'dracthyr',70=>'dracthyr'
        );

        $wowhead_by_name = array(
            'human'=>'human','orc'=>'orc','dwarf'=>'dwarf','night elf'=>'nightelf','undead'=>'undead','tauren'=>'tauren',
            'gnome'=>'gnome','troll'=>'troll','goblin'=>'goblin','blood elf'=>'bloodelf','draenei'=>'draenei','worgen'=>'worgen',
            'pandaren'=>'pandaren','nightborne'=>'nightborne','highmountain tauren'=>'highmountaintauren','void elf'=>'voidelf',
            'lightforged draenei'=>'lightforgeddraenei','zandalari troll'=>'zandalaritroll','kul tiran'=>'kultiran',
            'dark iron dwarf'=>'darkirondwarf','vulpera'=>'vulpera','maghar orc'=>'magharorc','mechagnome'=>'mechagnome','dracthyr'=>'dracthyr',
            'dracthyr visage'=>'dracthyr','dracthyr (visage)'=>'dracthyr',
            'haranir'=>'harronir','harronir'=>'harronir'
        );
        $name_key = strtolower(trim($race_name));
        $base_name = '';
        if ($race_id > 0 && isset($wowhead_by_id[$race_id])) {
            $base_name = $wowhead_by_id[$race_id];
        } elseif (isset($wowhead_by_name[$name_key])) {
            $base_name = $wowhead_by_name[$name_key];
        }

        if ($base_name === '') {
            return 'unknown.svg';
        }

        $candidates = array(
            'wowhead-race/race_' . $base_name . '_' . $gender . '.jpg',
        );

        if ($gender === 'female') {
            $candidates[] = 'wowhead-race/race_' . $base_name . '_male.jpg';
        } else {
            $candidates[] = 'wowhead-race/race_' . $base_name . '_female.jpg';
        }

        foreach ($candidates as $candidate) {
            if (file_exists(GUILROIM_PLUGIN_DIR . 'assets/images/races/' . $candidate)) {
                return $candidate;
            }
        }

        return 'unknown.svg';
    }

    private function get_race_name_from_id(int $race_id): string
    {
        $map = array(1=>'Human',2=>'Orc',3=>'Dwarf',4=>'Night Elf',5=>'Undead',6=>'Tauren',7=>'Gnome',8=>'Troll',9=>'Goblin',10=>'Blood Elf',11=>'Draenei',22=>'Worgen',24=>'Pandaren',25=>'Pandaren',26=>'Pandaren',27=>'Nightborne',28=>'Highmountain Tauren',29=>'Void Elf',30=>'Lightforged Draenei',31=>'Zandalari Troll',32=>'Kul Tiran',34=>'Dark Iron Dwarf',35=>'Vulpera',36=>'Maghar Orc',37=>'Mechagnome',52=>'Dracthyr',70=>'Dracthyr');
        return (string) ($map[$race_id] ?? '');
    }

    private function normalize_race_display_name(string $race_name): string
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
}
