<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Options;
use RRZE\Log\Controller;

class Settings
{
    /**
     * [protected description]
     * @var string
     */
    protected $pluginFile;

    protected $options;

    protected $optionName;

    /**
     * [public description]
     * @var object
     */
    public $controller;

    public function __construct($pluginFile, $optionName, $options)
    {
        $this->pluginFile = $pluginFile;

        $this->optionName = $optionName;
        $this->options = $options;
    }

    /**
     * [onLoaded description]
     * @return void
     */
    public function onLoaded()
    {
        $this->controller = new Controller($this->pluginFile);

        add_action('network_admin_menu', [$this, 'networkAdminMenu']);
        add_filter('set-screen-option', [$this, 'setScreenOption'], 10, 3);
    }

    /**
     * [networkAdminMenu description]
     * @return void
     */
    public function networkAdminMenu()
    {
        $logPage = add_menu_page(
            __('Log', 'rrze-log'),
            __('Log', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this->controller, 'logList'],
            'dashicons-list-view'
        );

        /**
        $settingsPage = add_submenu_page(
            'rrze-log',
            __('Services', 'rrze-updater'),
            __('Services', 'rrze-updater'),
            'manage_options',
            'rrze-log-settings',
            [$this->controller, 'logList']
        );
        */

        add_action("load-$logPage", [$this->controller, 'logPageScreenOptions']);
    }
 
    /**
     * [setScreenOption description]
     * @param string $status [description]
     * @param string $option [description]
     * @param string $value  [description]
     * @return string        [description]
     */
    public function setScreenOption($status, $option, $value)
    {
        if ('rrze_log_per_page' == $option) {
            return $value;
        }
        return $status;
    }
}
