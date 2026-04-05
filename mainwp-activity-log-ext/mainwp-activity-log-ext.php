<?php
/**
 * Plugin Name:       MainWP Activity Log (activitylog.io) Extension
 * Description:       Site Based Activity Log Viewing and Settings Manager for MainWP
 * Tested up to:      6.9.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0
 * Author:            ReallyUsefulPlugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mainwp-activity-log-ext
 * Website:           https://reallyusefulplugins.com
 */

if (!defined('ABSPATH')) exit;

/**
 * IMPORTANT: MainWP wants the ABSOLUTE PATH of the main plugin file as the
 * extension identifier. We expose it as RUP_MAINWP_MWALX_MWALX_MAIN_FILE and reuse it everywhere.
 */
define('RUP_MAINWP_MWALX_MWALX_VERSION', '1.0');
define('RUP_MAINWP_MWALX_MWALX_SLUG', 'mainwp-activity-log-ext');
define('RUP_MAINWP_MWALX_MWALX_MAIN_FILE', __FILE__);                  
define('RUP_MAINWP_MWALX_MWALX_PLUGIN_URL', plugins_url('', __FILE__));
define('RUP_MAINWP_MWALX_MWALX_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once RUP_MAINWP_MWALX_MWALX_PLUGIN_DIR . 'src/class-connector.php';
require_once RUP_MAINWP_MWALX_MWALX_PLUGIN_DIR . 'src/class-activator.php';

/**
 * IMPORTANT: expose the activator exactly like other MainWP extensions do
 * (e.g., SureMail). This is also what your Connector reads to get the key/file.
 */
$GLOBALS['mainWPExtensionActivator'] = new \RUP_MAINWP_MWALX\MWALX\RUP_MAINWP_MWALX_Activator();



/** Updater bootstrap */
add_action( 'plugins_loaded', function() {
    require_once __DIR__ . '/inc/updater.php';
    $updater_config = [
    	'vendor'	  => 'RUP',
        'plugin_file' => plugin_basename( __FILE__ ),
        'slug'        => 'mainwp-activity-log-ext',
        'name'        => 'MAINWP Activity Log Exention',
        'version'     => RUP_MAINWP_MWALX_MWALX_VERSION,
        'key'         => '',
        'allow_prerelease' => true,
        'server'      => 'https://raw.githubusercontent.com/stingray82/mainwp-activity-log/main/UUPD/mainwp-activity-log-ext/index.json',
    ];
    \RUP\Updater\Updater_V2::register( $updater_config );
}, 20);

/** MainWP icon */
add_filter('mainwp_child_stats_get_plugin_info', function($info, $slug) {
    if ('mainwp-activity-log-ext/mainwp-activity-log-ext.php' === $slug) {
        $info['icon'] = 'https://raw.githubusercontent.com/stingray82/mainwp-activity-log/main/UUPD/icon-128.png';
    }
    return $info;
}, 10, 2);

add_filter('uupd/allow_prerelease', function ($allow, $vendor, $slug) {
    if ($vendor === 'rup' && $slug === 'mainwp-activity-log-ext') {
        return get_option('mainwp_activity_log_ext_allow_prerelease') === 'yes';
    }
    return $allow;
}, 5, 3);
