<?php
/**
 * Plugin Name: Guild Roster Importer for WoW
 * Plugin URI: https://a-wd.eu/
 * Description: Displays a World of Warcraft guild roster and character profiles on WordPress using the Blizzard Battle.net API.
 * Version: 1.0.4
 * Author: Athlios
 * Author URI: https://a-wd.eu
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: guild-roster-importer-for-wow
 * Domain Path: /
 */

if (! defined('ABSPATH')) {
    exit;
}

define('GUILROIM_PLUGIN_VERSION', '1.0.4');
define('GUILROIM_PLUGIN_FILE', __FILE__);
define('GUILROIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GUILROIM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GUILROIM_PLUGIN_DIR . 'includes/class-wow-guild-roster-settings.php';
require_once GUILROIM_PLUGIN_DIR . 'includes/class-wow-guild-roster-api.php';
require_once GUILROIM_PLUGIN_DIR . 'includes/class-wow-guild-roster-shortcode.php';
require_once GUILROIM_PLUGIN_DIR . 'includes/class-wow-guild-roster-character-page.php';
require_once GUILROIM_PLUGIN_DIR . 'includes/class-wow-guild-roster-plugin.php';

(new WoW_Guild_Roster_Plugin())->init();

register_activation_hook(GUILROIM_PLUGIN_FILE, array('WoW_Guild_Roster_Plugin', 'activate'));
register_deactivation_hook(GUILROIM_PLUGIN_FILE, array('WoW_Guild_Roster_Plugin', 'deactivate'));

















