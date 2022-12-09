<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\ListTable;

class Settings
{
    /**
     * Option name.
     * @var string
     */
    protected $optionName;

    /**
     * Optiona values.
     * @var object
     */
    protected $options;

    /**
     * WP_List_Table object.
     * @var object
     */
    protected $listTable;

    /**
     * List table notice messages.
     * @var array
     */
    protected $messages = [];

    /**
     * Set properties.
     */
    public function __construct()
    {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * Add hooks.
     */
    public function onLoaded()
    {
        add_action('network_admin_menu', [$this, 'networkAdminMenu']);
        add_action('network_admin_menu', [$this, 'settingsSection']);
        add_action('network_admin_menu', [$this, 'settingsUpdate']);

        add_filter('set-screen-option', [$this, 'setScreenOption'], 10, 3);
    }

    /**
     * Add network admin menu.
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

        add_submenu_page(
            'rrze-log',
            __('Settings', 'rrze-updater'),
            __('Settings', 'rrze-updater'),
            'manage_options',
            'rrze-log-settings',
            [$this, 'settingsPage']
        );

        add_action("load-$logPage", [$this, 'screenOptions']);
    }

    /**
     * Display settings page.
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
     * Add settings sections.
     */
    public function settingsSection()
    {
        add_settings_section('rrze-log-settings', false, '__return_false', 'rrze-log-settings');
        add_settings_field('rrze-log-enabled', __('Enable Log', 'rrze-log'), [$this, 'enabledField'], 'rrze-log-settings', 'rrze-log-settings');
        add_settings_field('rrze-log-logTTL', __('Time to live', 'rrze-log'), [$this, 'logTTLField'], 'rrze-log-settings', 'rrze-log-settings');
    }

    /**
     * Display enabled field.
     */
    public function enabledField()
    {
    ?>
        <label>
            <input type="checkbox" id="rrze-log-enabled" name="<?php printf('%s[enabled]', $this->optionName); ?>" value="1" <?php checked($this->options->enabled, 1); ?>>
        </label>
    <?php
    }

    /**
     * Display logTTL field.
     */
    public function logTTLField()
    {
    ?>
        <label for="rrze-log-ttl">
            <input type="number" min="1" max="365" step="1" name="<?php printf('%s[logTTL]', $this->optionName); ?>" value="<?php echo esc_attr($this->options->logTTL) ?>" class="small-text">
        </label>
        <p class="description"><?php _e('How many days can the log file remain on disk before it is removed?', 'rrze-log'); ?></p>
    <?php
    }

    /**
     * Validate options.
     * @param  array $input [description]
     * @return array        [description]
     */
    public function optionsValidate($input)
    {
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $input['logTTL'] = !empty($input['logTTL']) && absint($input['logTTL']) ? absint($input['logTTL']) : $this->options->logTTL;

        $this->options = (object) wp_parse_args($input, (array) $this->options);
        return (array) $this->options;
    }

    /**
     * Update network admin options.
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
     * Update network admin notice.
     */
    public function settingsUpdateNotice()
    {
        $class = 'notice updated';
        $message = __("Settings saved.", 'rrze-settings');

        printf('<div class="%1s"><p>%2s</p></div>', esc_attr($class), esc_html($message));
    }

    /**
     * Set screen options.
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
     * Add screen options.
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
        $this->listTable = new ListTable();
    }

    /**
     * Display list table page.
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
     * Display list table notices.
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
