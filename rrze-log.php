<?php

/*
Plugin Name:        RRZE Log
Plugin URI:         https://github.com/RRZE-Webteam/rrze-log
Description:        The plugin allows you to log certain actions of the plugins and themes in a log file, which are or may be necessary for further investigations.
Version:            2.7.4
Author:             RRZE Webteam
Author URI:         https://www.wp.rrze.fau.de/
License:            GNU General Public License Version 3
License URI:        https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:        rrze-log
Domain Path:        /languages
Requires at least:  6.8
Requires PHP:       8.4
*/

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Plugin;
use RRZE\Log\Main;
use RRZE\Log\Cron;

/**
 * SPL Autoloader (PSR-4).
 * This function automatically loads classes from the RRZE\Log namespace
 * by mapping the namespace to the 'includes/' directory.
 * 
 * @param string $class The fully-qualified class name.
 * @return void
 */
spl_autoload_register(function ($class) {
    $prefix = __NAMESPACE__;
    $baseDir = __DIR__ . '/includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Register activation and deactivation hooks.
register_activation_hook(__FILE__, __NAMESPACE__ . '\activation');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivation');

add_action('plugins_loaded', __NAMESPACE__ . '\loaded');

add_filter('rrze.log.get', __NAMESPACE__ . '\Utils::getLogs');

/**
 * Handle the activation of the plugin.
 *
 * This function is called when the plugin is activated.
 * 
 * @param bool $networkWide Indicates if the plugin is activated network-wide.
 * @return void
 */
function activation($networkWide) {
    // Nothing to do here for activation.
}

/**
 * Handle the deactivation of the plugin.
 *
 * This function is called when the plugin is deactivated.
 * 
 * @return void
 */
function deactivation() {
    Cron::unschedule();
}

/**
 * Singleton pattern for initializing and accessing the main plugin instance.
 *
 * This method ensures that only one instance of the Plugin class is created and returned.
 *
 * @return Plugin The main instance of the Plugin class.
 */
function plugin(): Plugin {
    // Declare a static variable to hold the instance.
    static $instance;

    // Check if the instance is not already created.
    if (null === $instance) {
        // Add a new instance of the Plugin class, passing the current file (__FILE__) as a parameter.
        $instance = new Plugin(__FILE__);
    }

    // Return the main instance of the Plugin class.
    return $instance;
}

/**
 * Load plugin text domain for translations.
 * 
 * @return void
 */
function loadTextDomain() {
    load_plugin_textdomain('rrze-log', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * Check system requirements for the plugin.
 *
 * This method checks if the server environment meets the minimum WordPress and PHP version requirements
 * for the plugin to function properly.
 *
 * @return string An error message string if requirements are not met, or an empty string if requirements are satisfied.
 */
function systemRequirements(): string {
    // Initialize an error message string.
    $error = '';

    // Check if the WordPress version is compatible with the plugin's requirement.
    if (!is_wp_version_compatible(plugin()->getRequiresWP())) {
        $error = sprintf(
            /* translators: 1: Server WordPress version number, 2: Required WordPress version number. */
            __('The server is running WordPress version %1$s. The plugin requires at least WordPress version %2$s.', 'rrze-log'),
            wp_get_wp_version(),
            plugin()->getRequiresWP()
        );
    } elseif (!is_php_version_compatible(plugin()->getRequiresPHP())) {
        // Check if the PHP version is compatible with the plugin's requirement.
        $error = sprintf(
            /* translators: 1: Server PHP version number, 2: Required PHP version number. */
            __('The server is running PHP version %1$s. The plugin requires at least PHP version %2$s.', 'rrze-log'),
            phpversion(),
            plugin()->getRequiresPHP()
        );
    } elseif (is_multisite() && !is_plugin_active_for_network(plugin()->getBaseName())) {
        $error = __('This plugin must be activated network-wide on multisite installations.', 'rrze-log');
    }

    // Return the error message string, which will be empty if requirements are satisfied.
    return $error;
}

/**
 * Handle the loading of the plugin.
 *
 * This function is responsible for initializing the plugin, loading text domains for localization,
 * checking system requirements, and displaying error notices if necessary.
 */
function loaded() {
    // Load the plugin text domain for translations.
    loadTextDomain();

    // Trigger the 'loaded' method of the main plugin instance.
    plugin()->loaded();

    // Check system requirements and store any error messages.
    if ($error = systemRequirements()) {
        // If there is an error, add an action to display an admin notice with the error message.
        add_action('admin_init', function () use ($error) {
            // Check if the current user has the capability to activate plugins.
            if (current_user_can('activate_plugins')) {
                // Get plugin data to retrieve the plugin's name.
                $pluginName = plugin()->getName();

                // Determine the admin notice tag based on network-wide activation.
                $tag = is_plugin_active_for_network(plugin()->getBaseName()) ? 'network_admin_notices' : 'admin_notices';

                // Add an action to display the admin notice.
                add_action($tag, function () use ($pluginName, $error) {
                    printf(
                        '<div class="notice notice-error"><p>' .
                            /* translators: 1: The plugin name, 2: The error string. */
                            esc_html__('Plugins: %1$s: %2$s', 'rrze-log') .
                            '</p></div>',
                        $pluginName,
                        $error
                    );
                });
            }
        });

        // Return to prevent further initialization if there is an error.
        return;
    }

    // If there are no errors, create an instance of the 'Main' class and trigger its 'loaded' method.
    (new Main)->loaded();
}
