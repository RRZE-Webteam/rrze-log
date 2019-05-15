<?php

/*
Plugin Name:     RRZE Log
Plugin URI:      https://gitlab.rrze.fau.de/rrze-webteam/rrze-logs
Description:     The plugin allows you to log certain actions of the plugins and themes in a log file, which are or may be necessary for further investigations.
Version:         1.8.0
Author:          RRZE Webteam
Author URI:      https://blogs.fau.de/webworking/
License:         GNU General Public License v2
License URI:     http://www.gnu.org/licenses/gpl-2.0.html
Domain Path:     /languages
Text Domain:     rrze-log
Network:         true
*/

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Main;

const RRZE_PHP_VERSION = '7.1';
const RRZE_WP_VERSION = '5.2';

const RRZE_PLUGIN_FILE = __FILE__;

const RRZELOG_DIR = WP_CONTENT_DIR . '/log/rrzelog';

spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__;
    $base_dir = __DIR__ . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, __NAMESPACE__ . '\activation');

add_action('plugins_loaded', __NAMESPACE__ . '\loaded');

add_filter('pre_update_option_active_plugins', __NAMESPACE__ . '\loaded_first');
add_filter('pre_update_site_option_active_sitewide_plugins', __NAMESPACE__ . '\loaded_first');

/**
 * [load_textdomain description]
 */
function load_textdomain()
{
    load_plugin_textdomain('rrze-log', false, sprintf('%s/languages/', dirname(plugin_basename(__FILE__))));
}

/**
 * [system_requirements description]
 * @return string [description]
 */
function system_requirements()
{
    $error = '';
    if (version_compare(PHP_VERSION, RRZE_PHP_VERSION, '<')) {
        $error = sprintf(
            __('The server is running PHP version %1$s. The Plugin requires at least PHP version %2$s.', 'rrze-log'),
            PHP_VERSION,
            RRZE_PHP_VERSION
        );
    } elseif (version_compare($GLOBALS['wp_version'], RRZE_WP_VERSION, '<')) {
        $error = sprintf(
            __('The server is running WordPress version %1$s. The Plugin requires at least WordPress version %2$s.', 'rrze-log'),
            $GLOBALS['wp_version'],
            RRZE_WP_VERSION
        );
    }
    return $error;
}

/**
 * [activation description]
 */
function activation()
{
    load_textdomain();

    if ($error = system_requirements()) {
        deactivate_plugins(plugin_basename(__FILE__), false, true);
        wp_die($error);
    }
}

/**
 * Ensures that the plugin is always loaded first.
 * @param  array  $active_plugins [description]
 * @return array                 [description]
 */
function loaded_first(array $active_plugins)
{
    $basename = plugin_basename(__FILE__);
    $key = array_search($basename, $active_plugins);

    if (false !== $key) {
        array_splice($active_plugins, $key, 1);
        array_unshift($active_plugins, $basename);
    }

    return $active_plugins;
}

/**
 * [loaded description]
 */
function loaded()
{
    load_textdomain();

    if ($error = system_requirements()) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        $plugin_data = get_plugin_data(__FILE__);
        $plugin_name = $plugin_data['Name'];
        $tag = is_network_admin() ? 'network_admin_notices' : 'admin_notices';
        add_action($tag, function () use ($plugin_name, $error) {
            printf('<div class="notice notice-error"><p>%1$s: %2$s</p></div>', esc_html($plugin_name), esc_html($error));
        });
    } else {
        new Main();
    }
}
