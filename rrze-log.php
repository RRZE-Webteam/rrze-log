<?php

/*
Plugin Name:     RRZE Log
Plugin URI:      https://gitlab.rrze.fau.de/rrze-webteam/rrze-log
Description:     The plugin allows you to log certain actions of the plugins and themes in a log file, which are or may be necessary for further investigations.
Version:         2.2.0
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

// Autoloader (PSR-4)
spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__;
    $base_dir = __DIR__ . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, __NAMESPACE__ . '\activation');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivation');
add_action('plugins_loaded', __NAMESPACE__ . '\loaded');

add_filter('pre_update_option_active_plugins', __NAMESPACE__ . '\loadedFirst');
add_filter('pre_update_site_option_active_sitewide_plugins', __NAMESPACE__ . '\loadedFirst');

/**
 * [loadTextdomain description]
 */
function loadTextdomain()
{
    load_plugin_textdomain('rrze-log', false, sprintf('%s/languages/', dirname(plugin_basename(__FILE__))));
}

/**
 * [systemRequirements description]
 * @return string [description]
 */
function systemRequirements(): string
{
    loadTextdomain();

    $error = '';
    if (version_compare(PHP_VERSION, Constants::PLUGIN_PHP_VERSION, '<')) {
        $error = sprintf(
            /* translators: 1: Server PHP version number, 2: Required PHP version number. */
            __('The server is running PHP version %1$s. The Plugin requires at least PHP version %2$s.', 'rrze-log'),
            PHP_VERSION,
            Constants::PLUGIN_PHP_VERSION
        );
    } elseif (version_compare($GLOBALS['wp_version'], Constants::PLUGIN_WP_VERSION, '<')) {
        $error = sprintf(
            /* translators: 1: Server WordPress version number, 2: Required WordPress version number. */
            __('The server is running WordPress version %1$s. The Plugin requires at least WordPress version %2$s.', 'rrze-log'),
            $GLOBALS['wp_version'],
            Constants::PLUGIN_WP_VERSION
        );
    } elseif (!is_multisite()) {
        $error = __('The WordPress instance must be multisite.', 'rrze-log');
    }
    return $error;
}

/**
 * [activation description]
 */
function activation()
{
    loadTextdomain();

    if ($error = systemRequirements()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(sprintf(__('Plugins: %1$s: %2$s', 'rrze-log'), plugin_basename(__FILE__), $error));
    }
}

/**
 * [deactivation description]
 */
function deactivation()
{
    //
}

/**
 * Ensures that the plugin is always loaded first.
 * @param  array  $activePlugins [description]
 * @return array                 [description]
 */
function loadedFirst(array $activePlugins)
{
    $basename = plugin_basename(__FILE__);
    $key = array_search($basename, $activePlugins);

    if (false !== $key) {
        array_splice($activePlugins, $key, 1);
        array_unshift($activePlugins, $basename);
    }

    return $activePlugins;
}

/**
 * [plugin description]
 * @return object
 */
function plugin(): object
{
    static $instance;
    if (null === $instance) {
        $instance = new Plugin(__FILE__);
    }
    return $instance;
}

/**
 * [loaded description]
 * @return void
 */
function loaded()
{
    add_action('init', __NAMESPACE__ . '\loadTextdomain');
    plugin()->onLoaded();

    if ($error = systemRequirements()) {
        add_action('admin_init', function () use ($error) {
            if (current_user_can('activate_plugins')) {
                $pluginData = get_plugin_data(plugin()->getFile());
                $pluginName = $pluginData['Name'];
                $tag = is_plugin_active_for_network(plugin()->getBaseName()) ? 'network_admin_notices' : 'admin_notices';
                add_action($tag, function () use ($pluginName, $error) {
                    printf(
                        '<div class="notice notice-error"><p>' .
                            /* translators: 1: The plugin name, 2: The error string. */
                            __('Plugins: %1$s: %2$s', 'rrze-multilang') .
                            '</p></div>',
                        esc_html($pluginName),
                        esc_html($error)
                    );
                });
            }
        });
        return;
    }

    $main = new Main;
    $main->onLoaded();
}
