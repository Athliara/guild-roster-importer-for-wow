<?php

if (! defined('ABSPATH')) {
    exit;
}

class WoW_Guild_Roster_Settings
{
    public const PAGE_SLUG = 'guilroim-settings';
    public const PLUGIN_LABEL = 'Guild Roster Importer for WoW';
    public const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyBpZD0ic3ZnIiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHdpZHRoPSI0MDAiIGhlaWdodD0iNDAwIiB2aWV3Qm94PSIwLCAwLCA0MDAsNDAwIj48ZyBpZD0ic3ZnZyI+PHBhdGggaWQ9InBhdGgwIiBkPSJNMTY3Ljk5MyA1Mi41OTMgQyAxNTMuODYwIDU1LjYyMSwxMzUuNzg3IDYyLjYzMSwxMjQuMzM2IDY5LjUyNyBMIDExNS4yNDYgNzUuMDAwIDk1LjEyMyA3NS4wMDAgTCA3NS4wMDAgNzUuMDAwIDc1LjAwMCA5NS4xMjMgTCA3NS4wMDAgMTE1LjI0NiA2OS41MjcgMTI0LjMzNiBDIDQzLjI1MiAxNjcuOTY5LDQzLjI1MiAyMzIuMDMxLDY5LjUyNyAyNzUuNjY0IEwgNzUuMDAwIDI4NC43NTQgNzUuMDAwIDMwNC44NzcgTCA3NS4wMDAgMzI1LjAwMCA5NS4xMjMgMzI1LjAwMCBMIDExNS4yNDYgMzI1LjAwMCAxMjQuMzM2IDMzMC40NzMgQyAxNjcuOTY5IDM1Ni43NDgsMjMyLjAzMSAzNTYuNzQ4LDI3NS42NjQgMzMwLjQ3MyBMIDI4NC43NTQgMzI1LjAwMCAzMDQuODc3IDMyNS4wMDAgTCAzMjUuMDAwIDMyNS4wMDAgMzI1LjAwMCAzMDQuODc3IEwgMzI1LjAwMCAyODQuNzU0IDMzMC40NzMgMjc1LjY2NCBDIDM1Ni43NDggMjMyLjAzMSwzNTYuNzQ4IDE2Ny45NjksMzMwLjQ3MyAxMjQuMzM2IEwgMzI1LjAwMCAxMTUuMjQ2IDMyNS4wMDAgOTUuMTIzIEwgMzI1LjAwMCA3NS4wMDAgMzA0Ljg3NyA3NS4wMDAgTCAyODQuNzU0IDc1LjAwMCAyNzUuNjY0IDY5LjUyNyBDIDI0Ni45NTkgNTIuMjQxLDIwMi4zNjcgNDUuMjI4LDE2Ny45OTMgNTIuNTkzIE0yMjcuMDM1IDc5LjA3MSBDIDIzOC41MTEgODEuNjMxLDI2MC40ODYgOTAuOTkxLDI2OC42MjEgOTYuNzgzIEMgMjcyLjMzOSA5OS40MzEsMjc1LjUxNiAxMDAuMDAwLDI4Ni41NjkgMTAwLjAwMCBMIDMwMC4wMDAgMTAwLjAwMCAzMDAuMDAwIDExMy40MzEgQyAzMDAuMDAwIDEyNC40ODQsMzAwLjU2OSAxMjcuNjYxLDMwMy4yMTcgMTMxLjM3OSBDIDMyOS43MzQgMTY4LjYxOSwzMjkuNzM0IDIzMS4zODEsMzAzLjIxNyAyNjguNjIxIEMgMzAwLjU2OSAyNzIuMzM5LDMwMC4wMDAgMjc1LjUxNiwzMDAuMDAwIDI4Ni41NjkgTCAzMDAuMDAwIDMwMC4wMDAgMjg2LjU2OSAzMDAuMDAwIEMgMjc1LjUxNiAzMDAuMDAwLDI3Mi4zMzkgMzAwLjU2OSwyNjguNjIxIDMwMy4yMTcgQyAyMzEuMzgxIDMyOS43MzQsMTY4LjYxOSAzMjkuNzM0LDEzMS4zNzkgMzAzLjIxNyBDIDEyNy42NjEgMzAwLjU2OSwxMjQuNDg0IDMwMC4wMDAsMTEzLjQzMSAzMDAuMDAwIEwgMTAwLjAwMCAzMDAuMDAwIDEwMC4wMDAgMjg2LjU2OSBDIDEwMC4wMDAgMjc1LjUxNiw5OS40MzEgMjcyLjMzOSw5Ni43ODMgMjY4LjYyMSBDIDcwLjI2NiAyMzEuMzgxLDcwLjI2NiAxNjguNjE5LDk2Ljc4MyAxMzEuMzc5IEMgOTkuNDMxIDEyNy42NjEsMTAwLjAwMCAxMjQuNDg0LDEwMC4wMDAgMTEzLjQzMSBMIDEwMC4wMDAgMTAwLjAwMCAxMTMuNDMxIDEwMC4wMDAgQyAxMjQuMzgzIDEwMC4wMDAsMTI3LjY3NiA5OS40MjAsMTMxLjI3NiA5Ni44NTcgQyAxNTUuMzY2IDc5LjcwMywxOTYuMDE5IDcyLjE1MiwyMjcuMDM1IDc5LjA3MSBNMTE4LjMzOSAxNDQuNDM1IEMgMTI0LjQzOSAxNTEuMzY1LDEyNC40NTEgMTUxLjQwNSwxMzYuNDU1IDIwNS40NDcgQyAxNDkuNDUxIDI2My45NDgsMTQ5LjI5OSAyNjEuNzIzLDE0MC45MjMgMjcwLjcwMyBMIDEzNi45MTUgMjc1LjAwMCAxNjIuMjA4IDI3NS4wMDAgQyAxNzYuMTE4IDI3NS4wMDAsMTg3LjUwMCAyNzQuNjE0LDE4Ny41MDAgMjc0LjE0MSBDIDE4Ny41MDAgMjczLjY2OSwxODYuMzkwIDI3MS4xMzYsMTg1LjAzNCAyNjguNTE0IEMgMTgyLjc1MyAyNjQuMTAzLDE5Ni40MzMgMjE3LjIxNSwyMDAuMDAwIDIxNy4yMTUgQyAyMDMuNTY3IDIxNy4yMTUsMjE3LjI0NyAyNjQuMTAzLDIxNC45NjYgMjY4LjUxNCBDIDIxMy42MTAgMjcxLjEzNiwyMTIuNTAwIDI3My42NjksMjEyLjUwMCAyNzQuMTQxIEMgMjEyLjUwMCAyNzQuNjE0LDIyMy44ODIgMjc1LjAwMCwyMzcuNzkyIDI3NS4wMDAgTCAyNjMuMDg1IDI3NS4wMDAgMjU5LjA3NyAyNzAuNzAzIEMgMjUwLjcwMSAyNjEuNzIzLDI1MC41NDkgMjYzLjk0OCwyNjMuNTQ1IDIwNS40NDcgQyAyNzUuNTQ5IDE1MS40MDUsMjc1LjU2MSAxNTEuMzY1LDI4MS42NjEgMTQ0LjQzNSBMIDI4Ny43NjYgMTM3LjUwMCAyNjIuNjMzIDEzNy41MDAgQyAyNDguODEwIDEzNy41MDAsMjM3LjUwMCAxMzcuODI4LDIzNy41MDAgMTM4LjIyOCBDIDIzNy41MDAgMTM4LjYyOSwyMzguNjk3IDE0MS40NjYsMjQwLjE2MCAxNDQuNTM0IEMgMjQyLjc2NCAxNDkuOTk1LDIzMS4zOTYgMjA3LjQ5MCwyMjguMTEzIDIwNS40NjEgQyAyMjcuNTg0IDIwNS4xMzQsMjIxLjI5MyAxODkuNzA5LDIxNC4xMzMgMTcxLjE4MyBDIDIwNi45NzQgMTUyLjY1NywyMDAuNjE0IDEzNy41MDAsMjAwLjAwMCAxMzcuNTAwIEMgMTk5LjM4NiAxMzcuNTAwLDE5My4wMjYgMTUyLjY1NywxODUuODY3IDE3MS4xODMgQyAxNzguNzA3IDE4OS43MDksMTcyLjQxNiAyMDUuMTM0LDE3MS44ODcgMjA1LjQ2MSBDIDE2OC42MDQgMjA3LjQ5MCwxNTcuMjM2IDE0OS45OTUsMTU5Ljg0MCAxNDQuNTM0IEMgMTYxLjMwMyAxNDEuNDY2LDE2Mi41MDAgMTM4LjYyOSwxNjIuNTAwIDEzOC4yMjggQyAxNjIuNTAwIDEzNy44MjgsMTUxLjE5MCAxMzcuNTAwLDEzNy4zNjcgMTM3LjUwMCBMIDExMi4yMzQgMTM3LjUwMCAxMTguMzM5IDE0NC40MzUgIiBzdHJva2U9Im5vbmUiIGZpbGw9IiNhN2FhYWQiIGZpbGwtcnVsZT0iZXZlbm9kZCI+PC9wYXRoPjwvZz48L3N2Zz4=';
    private string $option_name = 'guilroim_options';

    public function get_option_name(): string
    {
        return $this->option_name;
    }

    public function get_defaults(): array
    {
        return array(
            'guild_name' => '',
            'server' => '',
            'region' => 'us',
            'theme' => 'dark-obsidian',
            'blizzard_client_id' => '',
            'blizzard_client_secret' => '',
            'sort_by' => 'rank',
            'sort_order' => 'asc',
            'results_per_page' => 25,
            'displays' => array(),
        );
    }

    public function get_options(): array
    {
        $options = get_option($this->option_name, array());
        $normalized = wp_parse_args(is_array($options) ? $options : array(), $this->get_defaults());
        $normalized['displays'] = $this->sanitize_displays($normalized['displays'] ?? array());

        return $normalized;
    }

    public static function get_locale_for_region(string $region): string
    {
        $locale_map = array(
            'us' => 'en_US',
            'eu' => 'en_GB',
            'kr' => 'ko_KR',
            'tw' => 'zh_TW',
        );

        $region = strtolower(trim($region));

        return $locale_map[$region] ?? 'en_US';
    }

    public static function normalize_region($value, string $fallback = 'us'): string
    {
        $region = sanitize_key((string) $value);
        $allowed = array('us', 'eu', 'kr', 'tw');
        if ($region === '' && $fallback === '') {
            return '';
        }

        $fallback = sanitize_key($fallback);

        if (! in_array($fallback, $allowed, true)) {
            $fallback = 'us';
        }

        return in_array($region, $allowed, true) ? $region : $fallback;
    }

    public function get_display_config(string $display_id): array
    {
        $options = $this->get_options();

        foreach ($options['displays'] as $display) {
            if (($display['id'] ?? '') === $display_id) {
                return $display;
            }
        }

        return array();
    }

    public function register_settings(): void
    {
        register_setting(
            'guilroim_options_group',
            $this->option_name,
            array($this, 'sanitize_options')
        );
    }

    public function sanitize_options(array $input): array
    {
        $defaults = $this->get_defaults();
        $sanitized = $defaults;

        $sanitized['guild_name'] = sanitize_text_field($input['guild_name'] ?? '');
        $sanitized['server'] = sanitize_text_field($input['server'] ?? '');
        $sanitized['region'] = $this->sanitize_region($input['region'] ?? 'us');
        $sanitized['theme'] = $this->sanitize_theme($input['theme'] ?? 'dark-obsidian');
        $sanitized['blizzard_client_id'] = sanitize_text_field($input['blizzard_client_id'] ?? '');
        $sanitized['blizzard_client_secret'] = sanitize_text_field($input['blizzard_client_secret'] ?? '');
        $sanitized['sort_by'] = $this->sanitize_sort_by($input['sort_by'] ?? 'rank');
        $sanitized['sort_order'] = $this->sanitize_sort_order($input['sort_order'] ?? 'asc');
        $sanitized['results_per_page'] = $this->sanitize_results_per_page($input['results_per_page'] ?? 25);
        $sanitized['displays'] = $this->sanitize_displays($input['displays'] ?? array());

        return $sanitized;
    }

    public function add_settings_page(): void
    {
        add_menu_page(
            __('Guild Roster Importer for WoW', 'guild-roster-importer-for-wow'),
            __('Guild Roster', 'guild-roster-importer-for-wow'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page'),
            self::MENU_ICON,
            58
        );
    }

    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style('guilroim-admin-settings', GUILROIM_PLUGIN_URL . 'assets/css/admin-settings.css', array(), GUILROIM_PLUGIN_VERSION);
        wp_enqueue_script('guilroim-admin-settings', GUILROIM_PLUGIN_URL . 'assets/js/admin-settings.js', array(), GUILROIM_PLUGIN_VERSION, true);
        wp_localize_script(
            'guilroim-admin-settings',
            'guilroimAdminSettings',
            array(
                'localeMap' => array(
                    'us' => 'en_US',
                    'eu' => 'en_GB',
                    'kr' => 'ko_KR',
                    'tw' => 'zh_TW',
                ),
                'shortcodeTag' => 'guilroim_roster',
            )
        );
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options();
        $sync_status = sanitize_key((string) (filter_input(INPUT_GET, 'guilroim_sync_status', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR) ?? ''));
        $sync_message = sanitize_text_field((string) (filter_input(INPUT_GET, 'guilroim_sync_message', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR) ?? ''));
        $mythic_sync_status = sanitize_key((string) (filter_input(INPUT_GET, 'guilroim_mythic_sync_status', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR) ?? ''));
        $mythic_sync_message = sanitize_text_field((string) (filter_input(INPUT_GET, 'guilroim_mythic_sync_message', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR) ?? ''));
        $settings_updated = (bool) filter_input(INPUT_GET, 'settings-updated', FILTER_VALIDATE_BOOLEAN);
        $locale = self::get_locale_for_region((string) ($options['region'] ?? 'us'));
        ?>
        <div class="wrap wgr-admin-page">
            <div class="wgr-admin-hero">
                <span class="wgr-admin-hero__logo" aria-hidden="true"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted internal SVG markup. ?><?php echo $this->get_logo_svg_markup(); ?></span>
                <div class="wgr-admin-hero__content">
                    <h1><?php esc_html_e('Guild Roster Importer for WoW', 'guild-roster-importer-for-wow'); ?></h1>
                    <p><?php esc_html_e('Import your Battle.net guild roster, style the roster output, and generate saved display shortcodes.', 'guild-roster-importer-for-wow'); ?></p>
                </div>
            </div>
            <form method="post" action="options.php" class="wgr-admin-form">
                <?php settings_fields('guilroim_options_group'); ?>
                <div class="wgr-admin-tabs" role="tablist" aria-label="<?php esc_attr_e('Guild Roster Importer for WoW settings', 'guild-roster-importer-for-wow'); ?>">
                    <button type="button" class="wgr-admin-tab is-active" data-tab="guild-import"><?php esc_html_e('Guild Import', 'guild-roster-importer-for-wow'); ?></button>
                    <button type="button" class="wgr-admin-tab" data-tab="appearance"><?php esc_html_e('Appearance', 'guild-roster-importer-for-wow'); ?></button>
                    <button type="button" class="wgr-admin-tab" data-tab="tooltips"><?php esc_html_e('Tooltips', 'guild-roster-importer-for-wow'); ?></button>
                </div>

                <section class="wgr-admin-panel is-active" data-panel="guild-import">
                    <div class="wgr-admin-card">
                        <div class="wgr-admin-field-grid wgr-admin-field-grid--compact">
                            <?php $this->render_text_field('guild_name', __('Guild Name', 'guild-roster-importer-for-wow'), (string) $options['guild_name'], __('Enter the WoW Guild name.', 'guild-roster-importer-for-wow')); ?>
                            <?php $this->render_text_field('server', __('Realm (Server)', 'guild-roster-importer-for-wow'), (string) $options['server'], __('Enter the WoW Guild Realm Name.', 'guild-roster-importer-for-wow')); ?>
                            <?php $this->render_region_field((string) $options['region'], $locale); ?>
                        </div>
                        <div class="wgr-admin-field-grid wgr-admin-field-grid--compact">
                            <?php $this->render_text_field('blizzard_client_id', __('Battle.net Client Key', 'guild-roster-importer-for-wow'), (string) $options['blizzard_client_id'], __('Create API credentials in the Battle.net Developer Portal: develop.battle.net.', 'guild-roster-importer-for-wow')); ?>
                            <?php $this->render_text_field('blizzard_client_secret', __('Battle.net Client Secret', 'guild-roster-importer-for-wow'), (string) $options['blizzard_client_secret'], __('Keep this private. It is used together with Client ID for Blizzard API authentication.', 'guild-roster-importer-for-wow'), 'password', array('autocomplete' => 'new-password')); ?>
                        </div>
                        <div class="wgr-admin-actions"><?php submit_button(__('Save Changes', 'guild-roster-importer-for-wow'), 'primary', 'submit', false); ?></div>
                        <?php if ($settings_updated) : ?>
                            <div class="wgr-inline-notice wgr-inline-notice--success"><p><?php esc_html_e('Settings saved.', 'guild-roster-importer-for-wow'); ?></p></div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="wgr-admin-panel" data-panel="appearance" hidden>
                    <div class="wgr-admin-card">
                        <div class="wgr-admin-field-grid wgr-admin-field-grid--list">
                            <?php $this->render_theme_field((string) $options['theme']); ?>
                            <?php $this->render_results_per_page_field('results_per_page', __('Results per Page', 'guild-roster-importer-for-wow'), (int) $options['results_per_page'], __('Number of members shown per page before pagination.', 'guild-roster-importer-for-wow')); ?>
                            <?php $this->render_sort_by_field('sort_by', __('Default Sorting By', 'guild-roster-importer-for-wow'), (string) $options['sort_by'], __('Choose the initial sort column for new displays.', 'guild-roster-importer-for-wow')); ?>
                            <?php $this->render_sort_order_field('sort_order', __('Default Sorting Order', 'guild-roster-importer-for-wow'), (string) $options['sort_order'], __('Choose the initial sort direction for new displays.', 'guild-roster-importer-for-wow')); ?>
                        </div>
                        <div class="wgr-admin-display-builder">
                            <div class="wgr-admin-display-builder__header">
                                <div>
                                    <h2><?php esc_html_e('Saved Displays', 'guild-roster-importer-for-wow'); ?></h2>
                                    <p><?php esc_html_e('Create one or more roster displays for the same guild and use the generated shortcode in pages, posts, or widgets.', 'guild-roster-importer-for-wow'); ?></p>
                                </div>
                                <button type="button" class="button button-secondary" data-add-display><?php esc_html_e('Create Display', 'guild-roster-importer-for-wow'); ?></button>
                            </div>
                            <div class="wgr-admin-display-list" data-display-list>
                                <?php if (empty($options['displays'])) : ?>
                                    <div class="wgr-admin-empty-state" data-display-empty><?php esc_html_e('No displays created yet. Click "Create Display" to add one.', 'guild-roster-importer-for-wow'); ?></div>
                                <?php else : ?>
                                    <?php foreach ($options['displays'] as $index => $display) : ?>
                                        <?php $this->render_display_tile((int) $index, $display); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <template id="wgr-display-template"><?php $this->render_display_tile(-1, $this->get_default_display_config('__display_id__')); ?></template>
                        </div>
                        <div class="wgr-admin-actions"><?php submit_button(__('Save Changes', 'guild-roster-importer-for-wow'), 'primary', 'submit', false); ?></div>
                        <?php if ($settings_updated) : ?>
                            <div class="wgr-inline-notice wgr-inline-notice--success"><p><?php esc_html_e('Settings saved.', 'guild-roster-importer-for-wow'); ?></p></div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="wgr-admin-panel" data-panel="tooltips" hidden>
                    <div class="wgr-admin-card">
                        <h2><?php esc_html_e('Item Tooltips', 'guild-roster-importer-for-wow'); ?></h2>
                        <p><?php esc_html_e('Guild Roster Importer for WoW keeps the submitted plugin package self-contained. It does not load third-party tooltip scripts, remote CSS, or remote JavaScript. Item icons, character media, dungeon images, and gem icons are displayed from local plugin files or locally cached media.', 'guild-roster-importer-for-wow'); ?></p>
                        <h3><?php esc_html_e('Current item display', 'guild-roster-importer-for-wow'); ?></h3>
                        <p><?php esc_html_e('Character profiles display equipment with the data available from the Battle.net API sync: item name, quality color, item level, enchant text, socketed gems, and local item or gem icons when available.', 'guild-roster-importer-for-wow'); ?></p>
                        <ol>
                            <li><?php esc_html_e('Run a roster sync after changing Battle.net credentials or guild settings.', 'guild-roster-importer-for-wow'); ?></li>
                            <li><?php esc_html_e('Open a character profile and check the equipment panel for item icons, item names, item levels, enchants, and gems.', 'guild-roster-importer-for-wow'); ?></li>
                            <li><?php esc_html_e('If an icon is missing, sync again so the plugin can request and cache the media locally.', 'guild-roster-importer-for-wow'); ?></li>
                            <li><?php esc_html_e('Clear any page cache after a sync if your site uses a caching plugin.', 'guild-roster-importer-for-wow'); ?></li>
                        </ol>
                        <h3><?php esc_html_e('Tooltip roadmap', 'guild-roster-importer-for-wow'); ?></h3>
                        <p><?php esc_html_e('A future native tooltip can be built from the same locally stored item data. That keeps the character profile self-contained while allowing richer hover details such as item level, enchant, sockets, and equipped gems.', 'guild-roster-importer-for-wow'); ?></p>
                    </div>
                </section>
            </form>

            <div class="wgr-admin-card wgr-admin-sync-card">
                <div class="wgr-admin-sync-list">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgr-admin-sync-form">
                        <input type="hidden" name="action" value="guilroim_sync_roster" />
                        <?php wp_nonce_field('guilroim_sync_roster'); ?>
                        <div class="wgr-admin-sync-row">
                            <?php submit_button(__('Sync Guild Data', 'guild-roster-importer-for-wow'), 'secondary', 'submit', false); ?>
                            <p class="description"><?php esc_html_e('Refresh the stored guild roster data quickly. The scheduled daily refresh runs automatically at 05:00 CET/CEST.', 'guild-roster-importer-for-wow'); ?></p>
                        </div>
                        <?php if ($sync_status !== '' && $sync_message !== '') : ?>
                            <div class="wgr-inline-notice <?php echo $sync_status === 'success' ? 'wgr-inline-notice--success' : 'wgr-inline-notice--error'; ?>"><p><?php echo esc_html($sync_message); ?></p></div>
                        <?php endif; ?>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wgr-admin-sync-form">
                        <input type="hidden" name="action" value="guilroim_sync_mythic" />
                        <?php wp_nonce_field('guilroim_sync_mythic'); ?>
                        <div class="wgr-admin-sync-row">
                            <?php submit_button(__('Sync Mythic+ Data', 'guild-roster-importer-for-wow'), 'secondary', 'submit', false); ?>
                            <p class="description"><?php esc_html_e('Refresh stored Mythic+ scores for max-level characters only, without slowing down the main guild roster sync.', 'guild-roster-importer-for-wow'); ?></p>
                        </div>
                        <?php if ($mythic_sync_status !== '' && $mythic_sync_message !== '') : ?>
                            <div class="wgr-inline-notice <?php echo $mythic_sync_status === 'success' ? 'wgr-inline-notice--success' : 'wgr-inline-notice--error'; ?>"><p><?php echo esc_html($mythic_sync_message); ?></p></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="wgr-admin-card">
                <h2><?php esc_html_e('Available Attributes for Shortcodes', 'guild-roster-importer-for-wow'); ?></h2>
                <p><?php esc_html_e('Use the display shortcodes generated above, or add the attributes below when you need to override display behavior for a specific embed.', 'guild-roster-importer-for-wow'); ?></p>
                <table class="widefat striped wgr-admin-attribute-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Attribute', 'guild-roster-importer-for-wow'); ?></th>
                        <th><?php esc_html_e('Description', 'guild-roster-importer-for-wow'); ?></th>
                        <th><?php esc_html_e('Example', 'guild-roster-importer-for-wow'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>display</code></td>
                        <td><?php esc_html_e('Load a saved display tile configuration.', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>display="display-1"</code></td>
                    </tr>
                    <tr>
                        <td><code>title</code></td>
                        <td><?php esc_html_e('Override the roster title.', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>title="Main Team"</code></td>
                    </tr>
                    <tr>
                        <td><code>ranks_to_display</code></td>
                        <td><?php esc_html_e('Comma-separated rank IDs to include.', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>ranks_to_display="0,1,2"</code></td>
                    </tr>
                    <tr>
                        <td><code>theme</code></td>
                        <td><?php esc_html_e('Theme variant.', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>theme="dark-obsidian"</code></td>
                    </tr>
                    <tr>
                        <td><code>show_count</code></td>
                        <td><?php esc_html_e('Show or hide member count (`true`/`false`).', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>show_count="true"</code></td>
                    </tr>
                    <tr>
                        <td><code>sort_by</code></td>
                        <td><?php esc_html_e('Initial sort column.', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>sort_by="rank"</code></td>
                    </tr>
                    <tr>
                        <td><code>sort_order</code></td>
                        <td><?php esc_html_e('Initial sort direction (`asc`/`desc`).', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>sort_order="asc"</code></td>
                    </tr>
                    <tr>
                        <td><code>page_size</code></td>
                        <td><?php esc_html_e('Results per page (`10`, `25`, or `50`).', 'guild-roster-importer-for-wow'); ?></td>
                        <td><code>page_size="25"</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function sanitize_region($value): string
    {
        return self::normalize_region($value);
    }

    private function sanitize_theme($value): string
    {
        $theme = sanitize_key((string) $value);
        $allowed = array('dark-obsidian', 'dark-void', 'light-parchment', 'light-sky');

        return in_array($theme, $allowed, true) ? $theme : 'dark-obsidian';
    }

    private function sanitize_sort_by($value): string
    {
        $sort_by = sanitize_key((string) $value);
        $allowed = array('name', 'rank', 'level', 'class', 'role', 'race', 'mythic_score');

        return in_array($sort_by, $allowed, true) ? $sort_by : 'rank';
    }

    private function sanitize_sort_order($value): string
    {
        $sort_order = sanitize_key((string) $value);
        $allowed = array('asc', 'desc');

        return in_array($sort_order, $allowed, true) ? $sort_order : 'asc';
    }

    private function sanitize_results_per_page($value): int
    {
        $results_per_page = absint($value);
        $allowed = array(10, 25, 50);

        return in_array($results_per_page, $allowed, true) ? $results_per_page : 25;
    }

    private function sanitize_displays($raw_displays): array
    {
        if (! is_array($raw_displays)) {
            return array();
        }

        $displays = array();

        foreach ($raw_displays as $display) {
            if (! is_array($display)) {
                continue;
            }

            $id = sanitize_key((string) ($display['id'] ?? ''));
            if ($id === '') {
                $id = 'display-' . (count($displays) + 1);
            }

            $displays[] = array(
                'id' => $id,
                'title' => sanitize_text_field($display['title'] ?? ''),
                'ranks_to_display' => preg_replace('/[^0-9,\s]/', '', (string) ($display['ranks_to_display'] ?? '')),
                'single_characters' => $this->sanitize_single_characters($display['single_characters'] ?? array()),
            );
        }

        return $displays;
    }

    private function get_default_display_config(string $id): array
    {
        return array(
            'id' => $id,
            'title' => '',
            'ranks_to_display' => '',
            'single_characters' => array(),
        );
    }

    private function sanitize_single_characters($raw_characters): array
    {
        if (! is_array($raw_characters)) {
            return array();
        }

        $characters = array();
        foreach ($raw_characters as $character) {
            if (! is_array($character)) {
                continue;
            }

            $name = sanitize_text_field((string) ($character['name'] ?? ''));
            $realm = sanitize_text_field((string) ($character['realm'] ?? ''));
            if ($name === '' && $realm === '') {
                continue;
            }

            $characters[] = array(
                'name' => $name,
                'realm' => $realm,
            );
        }

        return $characters;
    }

    private function get_logo_svg_markup(): string
    {
        $svg = base64_decode((string) substr(self::MENU_ICON, strpos(self::MENU_ICON, ',') + 1), true);

        return is_string($svg) ? $svg : '';
    }

    private function render_text_field(string $key, string $label, string $value, string $description, string $type = 'text', array $attributes = array()): void
    {
        $name = $this->option_name . '[' . $key . ']';
        ?>
        <div class="wgr-admin-field">
            <label for="<?php echo esc_attr('wgr-' . $key); ?>" class="wgr-admin-field__label"><?php echo esc_html($label); ?></label>
            <input id="<?php echo esc_attr('wgr-' . $key); ?>" class="regular-text" type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>"<?php foreach ($attributes as $attribute_name => $attribute_value) : ?> <?php echo esc_attr($attribute_name); ?>="<?php echo esc_attr((string) $attribute_value); ?>"<?php endforeach; ?> />
            <p class="description"><?php echo esc_html($description); ?></p>
        </div>
        <?php
    }

    private function render_region_field(string $value, string $locale): void
    {
        $regions = array('us' => 'US', 'eu' => 'EU', 'kr' => 'KR', 'tw' => 'TW');
        ?>
        <div class="wgr-admin-field">
            <label for="wgr-region" class="wgr-admin-field__label"><?php esc_html_e('Region', 'guild-roster-importer-for-wow'); ?></label>
            <select id="wgr-region" name="<?php echo esc_attr($this->option_name . '[region]'); ?>" data-region-select>
                <?php foreach ($regions as $region_key => $region_label) : ?>
                    <option value="<?php echo esc_attr($region_key); ?>" <?php selected($value, $region_key); ?>><?php echo esc_html($region_label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php esc_html_e('Choose the WoW Guild Region.', 'guild-roster-importer-for-wow'); ?></p>
            <p class="description">
                <?php /* translators: %s: locale code selected for the chosen region. */ ?>
                <?php printf(wp_kses_post(__('Locale is selected automatically from the region: <strong data-locale-preview>%s</strong>.', 'guild-roster-importer-for-wow')), esc_html($locale)); ?>
            </p>
        </div>
        <?php
    }

    private function render_theme_field(string $value): void
    {
        $themes = array(
            'dark-obsidian' => __('Dark: Obsidian', 'guild-roster-importer-for-wow'),
            'dark-void' => __('Dark: Void', 'guild-roster-importer-for-wow'),
            'light-parchment' => __('Light: Parchment', 'guild-roster-importer-for-wow'),
            'light-sky' => __('Light: Sky', 'guild-roster-importer-for-wow'),
        );
        ?>
        <div class="wgr-admin-field">
            <label for="wgr-theme" class="wgr-admin-field__label"><?php esc_html_e('Theme', 'guild-roster-importer-for-wow'); ?></label>
            <select id="wgr-theme" name="<?php echo esc_attr($this->option_name . '[theme]'); ?>">
                <?php foreach ($themes as $theme_key => $theme_label) : ?>
                    <option value="<?php echo esc_attr($theme_key); ?>" <?php selected($value, $theme_key); ?>><?php echo esc_html($theme_label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    private function render_sort_by_field(string $key, string $label, string $value, string $description): void
    {
        $sort_fields = array(
            'rank' => __('Rank', 'guild-roster-importer-for-wow'),
            'name' => __('Name', 'guild-roster-importer-for-wow'),
            'level' => __('Level', 'guild-roster-importer-for-wow'),
            'class' => __('Class', 'guild-roster-importer-for-wow'),
            'role' => __('Role', 'guild-roster-importer-for-wow'),
            'race' => __('Race', 'guild-roster-importer-for-wow'),
            'mythic_score' => __('M+ Score', 'guild-roster-importer-for-wow'),
        );
        ?>
        <div class="wgr-admin-field">
            <label for="<?php echo esc_attr('wgr-' . $key); ?>" class="wgr-admin-field__label"><?php echo esc_html($label); ?></label>
            <select id="<?php echo esc_attr('wgr-' . $key); ?>" name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>">
                <?php foreach ($sort_fields as $sort_key => $sort_label) : ?>
                    <option value="<?php echo esc_attr($sort_key); ?>" <?php selected($value, $sort_key); ?>><?php echo esc_html($sort_label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php echo esc_html($description); ?></p>
        </div>
        <?php
    }

    private function render_sort_order_field(string $key, string $label, string $value, string $description): void
    {
        $sort_orders = array('asc' => __('Ascending', 'guild-roster-importer-for-wow'), 'desc' => __('Descending', 'guild-roster-importer-for-wow'));
        ?>
        <div class="wgr-admin-field">
            <label for="<?php echo esc_attr('wgr-' . $key); ?>" class="wgr-admin-field__label"><?php echo esc_html($label); ?></label>
            <select id="<?php echo esc_attr('wgr-' . $key); ?>" name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>">
                <?php foreach ($sort_orders as $order_key => $order_label) : ?>
                    <option value="<?php echo esc_attr($order_key); ?>" <?php selected($value, $order_key); ?>><?php echo esc_html($order_label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php echo esc_html($description); ?></p>
        </div>
        <?php
    }

    private function render_results_per_page_field(string $key, string $label, int $value, string $description): void
    {
        $page_sizes = array(10, 25, 50);
        ?>
        <div class="wgr-admin-field">
            <label for="<?php echo esc_attr('wgr-' . $key); ?>" class="wgr-admin-field__label"><?php echo esc_html($label); ?></label>
            <select id="<?php echo esc_attr('wgr-' . $key); ?>" name="<?php echo esc_attr($this->option_name . '[' . $key . ']'); ?>">
                <?php foreach ($page_sizes as $page_size) : ?>
                    <option value="<?php echo esc_attr((string) $page_size); ?>" <?php selected($value, $page_size); ?>><?php echo esc_html((string) $page_size); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php echo esc_html($description); ?></p>
        </div>
        <?php
    }

    private function render_display_tile(int $index, array $display): void
    {
        $display = wp_parse_args($display, $this->get_default_display_config(''));
        ?>
        <div class="wgr-display-tile" data-display-tile>
            <div class="wgr-display-tile__header">
                <h3><?php echo esc_html($display['title'] !== '' ? $display['title'] : __('New Display', 'guild-roster-importer-for-wow')); ?></h3>
                <button type="button" class="button-link-delete" data-remove-display><?php esc_html_e('Remove', 'guild-roster-importer-for-wow'); ?></button>
            </div>
            <input type="hidden" data-field="id" value="<?php echo esc_attr((string) $display['id']); ?>"<?php if ($index >= 0) : ?> name="<?php echo esc_attr($this->option_name . '[displays][' . $index . '][id]'); ?>"<?php endif; ?> />
            <div class="wgr-admin-field-grid wgr-admin-field-grid--tile">
                <div class="wgr-admin-field">
                    <label class="wgr-admin-field__label"><?php esc_html_e('Roster Title', 'guild-roster-importer-for-wow'); ?></label>
                    <input type="text" class="regular-text" data-field="title" value="<?php echo esc_attr((string) $display['title']); ?>"<?php if ($index >= 0) : ?> name="<?php echo esc_attr($this->option_name . '[displays][' . $index . '][title]'); ?>"<?php endif; ?> />
                </div>
                <div class="wgr-admin-field">
                    <label class="wgr-admin-field__label"><?php esc_html_e('Ranks to Display', 'guild-roster-importer-for-wow'); ?></label>
                    <input type="text" class="regular-text" data-field="ranks_to_display" value="<?php echo esc_attr((string) $display['ranks_to_display']); ?>"<?php if ($index >= 0) : ?> name="<?php echo esc_attr($this->option_name . '[displays][' . $index . '][ranks_to_display]'); ?>"<?php endif; ?> />
                    <p class="description"><?php esc_html_e('Comma-separated rank IDs. Leave empty to show all ranks.', 'guild-roster-importer-for-wow'); ?></p>
                </div>
            </div>
            <div class="wgr-display-character-builder">
                <div class="wgr-display-character-builder__header">
                    <span class="wgr-admin-field__label"><?php esc_html_e('Add Single Characters', 'guild-roster-importer-for-wow'); ?></span>
                    <button type="button" class="button button-secondary wgr-display-character-add" data-add-character><?php esc_html_e('+', 'guild-roster-importer-for-wow'); ?></button>
                </div>
                <p class="description"><?php esc_html_e('Add exact character entries by Name and Realm for this display.', 'guild-roster-importer-for-wow'); ?></p>
                <div class="wgr-display-character-list" data-character-list>
                    <?php if (! empty($display['single_characters']) && is_array($display['single_characters'])) : ?>
                        <?php foreach ($display['single_characters'] as $character_index => $character) : ?>
                            <?php $this->render_single_character_row($index, (int) $character_index, $character); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <template data-character-template>
                    <?php $this->render_single_character_row(-1, -1, array('name' => '', 'realm' => '')); ?>
                </template>
            </div>
            <div class="wgr-display-shortcode">
                <span class="wgr-display-shortcode__label"><?php esc_html_e('Shortcode', 'guild-roster-importer-for-wow'); ?></span>
                <code data-shortcode-preview><?php echo esc_html('[guilroim_roster display="' . (string) $display['id'] . '"]'); ?></code>
            </div>
        </div>
        <?php
    }

    private function render_single_character_row(int $display_index, int $character_index, array $character): void
    {
        $name = sanitize_text_field((string) ($character['name'] ?? ''));
        $realm = sanitize_text_field((string) ($character['realm'] ?? ''));
        $name_attr = $display_index >= 0 && $character_index >= 0 ? $this->option_name . '[displays][' . $display_index . '][single_characters][' . $character_index . '][name]' : '';
        $realm_attr = $display_index >= 0 && $character_index >= 0 ? $this->option_name . '[displays][' . $display_index . '][single_characters][' . $character_index . '][realm]' : '';
        ?>
        <div class="wgr-display-character-row" data-character-row>
            <input type="text" class="regular-text" data-character-field="name" placeholder="<?php esc_attr_e('Name', 'guild-roster-importer-for-wow'); ?>" value="<?php echo esc_attr($name); ?>"<?php if ($name_attr !== '') : ?> name="<?php echo esc_attr($name_attr); ?>"<?php endif; ?> />
            <input type="text" class="regular-text" data-character-field="realm" placeholder="<?php esc_attr_e('Realm', 'guild-roster-importer-for-wow'); ?>" value="<?php echo esc_attr($realm); ?>"<?php if ($realm_attr !== '') : ?> name="<?php echo esc_attr($realm_attr); ?>"<?php endif; ?> />
            <button type="button" class="button-link-delete" data-remove-character><?php esc_html_e('Remove', 'guild-roster-importer-for-wow'); ?></button>
        </div>
        <?php
    }
}
