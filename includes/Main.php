<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Settings;
use RRZE\Log\Log;

class Main
{
    protected $settings;

    protected $log;

    public function __construct()
    {
        $this->settings = new Settings();

        $this->log = new Log();

        add_action('rrze.log.error', [$this->log, 'writeError']);
        add_action('rrze.log.debug', [$this->log, 'writeDebug']);
        add_action('rrze.log.warning', [$this->log, 'writeWarning']);
        add_action('rrze.log.notice', [$this->log, 'writeNotice']);
        add_action('rrze.log.info', [$this->log, 'writeInfo']);

        if (is_multisite()) {
            add_action('network_admin_menu', [$this->settings, 'networkSettingsMenu']);
            add_action('admin_init', [$this->settings, 'networkSettingsSections']);
            add_filter('network_admin_plugin_action_links_' . plugin_basename(RRZE_PLUGIN_FILE), [$this, 'networkAdminPluginActionLink']);
        } else {
            add_action('admin_menu', [$this->settings, 'adminSettingsMenu']);
            add_action('admin_init', [$this->settings, 'adminSettingsSections']);
            add_filter('plugin_action_links_' . plugin_basename(RRZE_PLUGIN_FILE), [$this, 'pluginActionLink']);
        }
    }

    public function networkAdminPluginActionLink($links)
    {
        if (!current_user_can('manage_network_options')) {
            return $links;
        }
        return array_merge(
            $links,
            [
                sprintf(
                    '<a href="%s">%s</a>',
                    add_query_arg(
                        ['page' => 'rrzelog-network'],
                        network_admin_url('settings.php')
                    ),
                    __('Settings', 'rrze-log')
                )
            ]
        );
    }

    public function pluginActionLink($links)
    {
        if (!current_user_can('manage_options')) {
            return $links;
        }
        return array_merge(
            $links,
            [
                sprintf(
                    '<a href="%s">%s</a>',
                    add_query_arg(
                        ['page' => 'rrzelog'],
                        admin_url('options-general.php')
                    ),
                    __('Settings', 'rrze-log')
                )
            ]
        );
    }
}
