<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\ListTable;

class Settings
{
    /**
     * [protected description]
     * @var string
     */
    protected $pluginFile;

    /**
     * [protected description]
     * @var string
     */
    protected $optionName;

    /**
     * [protected description]
     * @var object
     */
    protected $options;

    /**
     * [protected description]
     * @var object
     */
    protected $listTable;

    /**
     * [protected description]
     * @var array
     */
    protected $messages = [];

    /**
     * [__construct description]
     * @param string $pluginFile [description]
     * @param string $optionName [description]
     * @param string $options    [description]
     */
    public function __construct($pluginFile, $optionName, $options)
    {
        $this->pluginFile = $pluginFile;
        $this->optionName = $optionName;
        $this->options = $options;
    }

    /**
     * [onLoaded description]
     */
    public function onLoaded()
    {
        add_action('network_admin_menu', [$this, 'networkAdminMenu']);
        add_action('network_admin_menu', [$this, 'settingsSection']);
        add_action('network_admin_menu', [$this, 'settingsUpdate']);

        add_filter('set-screen-option', [$this, 'setScreenOption'], 10, 3);
    }

    /**
     * [networkAdminMenu description]
     */
    public function networkAdminMenu()
    {
        $logPage = add_menu_page(
            __('Log', 'rrze-log'),
            __('Log', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this, 'logPage'],
            'dashicons-list-view'
        );

        $settingsPage = add_submenu_page(
            'rrze-log',
            __('Settings', 'rrze-updater'),
            __('Settings', 'rrze-updater'),
            'manage_options',
            'rrze-log-settings',
            [$this, 'settingsPage']
        );

        add_action("load-$logPage", [$this, 'screenOptions']);
        $this->listTable = new ListTable();
    }

    /**
     * [settingsPage description]
     */
    public function settingsPage()
    {
        global $title; ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <form method="post">
            <?php do_settings_sections('rrze-log-settings'); ?>
            <?php settings_fields('rrze-log-settings'); ?>
            <?php submit_button(__('Save Changes', 'rrze-settings'), 'primary', 'rrze-log-settings-submit-primary'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * [settingsSection description]
     */
    public function settingsSection()
    {
        add_settings_section('rrze-log-settings', false, '__return_false', 'rrze-log-settings');
        add_settings_field('rrze-log-enabled', __('Enable Log', 'rrze-log'), [$this, 'enabledField'], 'rrze-log-settings', 'rrze-log-settings');
        add_settings_field('rrze-log-logTTL', __('Time to live', 'rrze-log'), [$this, 'logTTLField'], 'rrze-log-settings', 'rrze-log-settings');
    }

    /**
     * [enabledField description]
     */
    public function enabledField()
    {
        ?>
        <label>
            <input type="checkbox" id="rrze-log-enabled" name="<?php printf('%s[enabled]', $this->optionName); ?>" value="1"<?php checked($this->options->enabled, 1); ?>>
        </label>
        <?php
    }

    /**
     * [logTTLField description]
     */
    public function logTTLField()
    {
        ?>
        <label for="rrze-log-ttl">
            <input type="number" min="1" step="1" name="<?php printf('%s[logTTL]', $this->optionName); ?>" value="<?php echo esc_attr($this->options->logTTL) ?>" class="small-text">
        </label>
        <p class="description"><?php _e('How many days can the log file remain on disk before it is removed.', 'rrze-log'); ?></p>
        <?php
    }


    /**
     * [optionsValidate description]
     * @param  array $input [description]
     * @return array        [description]
     */
    public function optionsValidate($input)
    {
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $input['logTTL'] = !empty($input['logTT']) && absint($input['logTTL']) ? absint($input['logTTL']) : $this->options->logTTL;

        $this->options = (object) wp_parse_args($input, (array) $this->options);
        return (array) $this->options;
    }

    /**
     * [settingsUpdate description]
     */
    public function settingsUpdate()
    {
        if (is_network_admin() && isset($_POST['rrze-log-settings-submit-primary'])) {
            check_admin_referer('rrze-log-settings-options');
            $input = isset($_POST[$this->optionName]) ? $_POST[$this->optionName] : [];
            update_site_option($this->optionName, $this->optionsValidate($input));
            $this->options = Options::getOptions();
            add_action('network_admin_notices', [$this, 'settingsUpdateNotice']);
        }
    }

    /**
     * [settingsUpdateNotice description]
     */
    public function settingsUpdateNotice()
    {
        $class = 'notice updated';
        $message = __("Settings saved.", 'rrze-settings');

        printf('<div class="%1s"><p>%2s</p></div>', esc_attr($class), esc_html($message));
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

    /**
     * [screenOptions description]
     */
    public function screenOptions()
    {
        $option = 'per_page';
        $args = [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page'
        ];

        add_screen_option($option, $args);
    }

    /**
     * [logPage description]
     */
    public function logPage()
    {
        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        $this->listTable->prepare_items();

        $action = isset($_GET['action']) ? $_GET['action'] : 'index';

        $s = isset($_REQUEST['s']) ? $_REQUEST['s'] : '';
        $level = isset($_REQUEST['level']) && in_array($_REQUEST['level'], Logger::LEVELS) ? $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) ? $_REQUEST['logfile'] : date('Y-m-d');

        $data = [
            'action' => $action,
            's' => $s,
            'level' => $level,
            'logfile' => $logFile,
            'listTable' => $this->listTable
        ];

        $this->show('list-table', $data);
    }

    /**
     * [show description]
     * @param  string $view [description]
     * @param  array  $data [description]
     */
    protected function show($view, $data = [])
    {
        if (!current_user_can('update_plugins') || !current_user_can('update_themes')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        $data['messages'] = $this->messages;

        include 'Views/base.php';
    }
}
