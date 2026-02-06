<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Settings {
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
     * WP_List_Table object.
     * @var object
     */
    protected $debugListTable;

    /**
     * List table notice messages.
     * @var array
     */
    protected $messages = [];

    /**
     * Is Debug Log set?
     * @var boolean|object
     */
    protected $isDebugLog;

    /**
     * Error message
     * @var string
     */
    protected $error;

    /**
     * Constructor
     * @return void
     */
    public function __construct() {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * Initiate hooks.
     * @return void
     */
    public function loaded() {
        add_action('network_admin_menu', [$this, 'networkAdminMenu']);
        add_action('network_admin_menu', [$this, 'settingsSection']);
        add_action('network_admin_menu', [$this, 'settingsUpdate']);

        if (is_super_admin() || $this->options->adminMenu) {
            add_action('admin_menu', [$this, 'adminSubMenu']);
        }

        add_filter('set-screen-option', [$this, 'setScreenOption'], 10, 3);

        $this->isDebugLog = Utils::isDebugLog();
        if ($this->isDebugLog instanceof \WP_Error && is_wp_error($this->isDebugLog)) {
            if (is_multisite()) {
                add_action('network_admin_notices', [$this, 'adminErrorNotice']);
            } else {
                add_action('admin_notices', [$this, 'adminErrorNotice']);
            }
            $this->error = $this->isDebugLog->get_error_message();
            $this->isDebugLog = false;
        }
    }

    /**
     * Admin Notice
     * @return void
     */
    public function adminErrorNotice() {
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr('notice notice-error'),
            esc_html($this->error)
        );
    }

    /**
     * Add network admin menu.
     */
    public function networkAdminMenu() {
        $logPage = add_menu_page(
            __('Log', 'rrze-log'),
            __('Log', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this, 'logPage'],
            'dashicons-list-view'
        );
        add_action("load-$logPage", [$this, 'screenOptions']);

        if ($this->isDebugLog && $this->isUserInDebugLogAccess()) {
            $debugLogPage = add_submenu_page(
                'rrze-log',
                __('Debug', 'rrze-log'),
                __('Debug', 'rrze-log'),
                'manage_options',
                'rrze-log-debug',
                [$this, 'debugLogPage']
            );
            add_action("load-$debugLogPage", [$this, 'debugScreenOptions']);
        }

        add_submenu_page(
            'rrze-log',
            __('Settings', 'rrze-log'),
            __('Settings', 'rrze-log'),
            'manage_options',
            'rrze-log-settings',
            [$this, 'settingsPage']
        );
    }

    public function adminSubMenu() {
        $logPage = add_submenu_page(
            'tools.php',
            __('RRZE-Log', 'rrze-log'),
            __('RRZE-Log', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this, 'logPage']
        );

        add_action("load-$logPage", [$this, 'screenOptions']);

        if ($this->isDebugLog && $this->isUserInDebugLogAccess()) {
            $debugLogPage = add_submenu_page(
                'tools.php',
                __('WP-Debug', 'rrze-log'),
                __('WP-Debug', 'rrze-log'),
                'manage_options',
                'rrze-log-debug',
                [$this, 'debugLogPage']
            );

            add_action("load-$debugLogPage", [$this, 'debugScreenOptions']);
        }
    }

    /**
     * Display settings page.
     */
    public function settingsPage() {
        global $title;
        ?>
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
    public function settingsSection() {
        add_settings_section(
            'rrze-log-settings',
            __('RRZE Log', 'rrze-log'),
            '__return_false',
            'rrze-log-settings'
        );

        add_settings_field(
            'rrze-log-enabled',
            __('Enable Log', 'rrze-log'),
            [$this, 'enabledField'],
            'rrze-log-settings',
            'rrze-log-settings'
        );

        add_settings_field(
            'rrze-log-maxLines',
            __('Truncate log file to last N lines', 'rrze-log'),
            [$this, 'maxLinesField'],
            'rrze-log-settings',
            'rrze-log-settings'
        );

        add_settings_field(
            'rrze-log-adminMenu',
            __('Enable administration menus', 'rrze-log'),
            [$this, 'adminMenuField'],
            'rrze-log-settings',
            'rrze-log-settings'
        );

        if (is_multisite() && is_super_admin()) {
            add_settings_field(
                'rrze-log-auditEnabled',
                __('Enable Admin Audit Log', 'rrze-log'),
                [$this, 'auditEnabledField'],
                'rrze-log-settings',
                'rrze-log-settings'
            );
        }

        if ($this->isDebugLog) {
            add_settings_section(
                'rrze-log-wp-debug-settings',
                __('WP Debug Log', 'rrze-log'),
                '__return_false',
                'rrze-log-settings'
            );

            add_settings_field(
                'rrze-log-maxLines',
                __('Truncate log file to last N lines', 'rrze-log'),
                [$this, 'debugMaxLinesField'],
                'rrze-log-settings',
                'rrze-log-wp-debug-settings'
            );

            add_settings_field(
                'rrze-log-debugLogAccess',
                __('Log Access', 'rrze-log'),
                [$this, 'debugLogAccessField'],
                'rrze-log-settings',
                'rrze-log-wp-debug-settings'
            );
        }
    }

    /**
     * Display enabled field.
     */
    public function enabledField() {
        ?>
        <label>
            <input type="checkbox" id="rrze-log-enabled" name="<?php printf('%s[enabled]', $this->optionName); ?>" value="1" <?php checked($this->options->enabled, 1); ?>>
            <?php _e('Enables network-wide logging', 'rrze-log'); ?>
        </label>
        <?php
    }

    /**
     * Display adminMenu field.
     */
    public function adminMenuField() {
        ?>
        <label>
            <input type="checkbox" id="rrze-log-admin-menu" name="<?php printf('%s[adminMenu]', $this->optionName); ?>" value="1" <?php checked($this->options->adminMenu, 1); ?>>
            <?php _e('Enables network wide the Log menu for administrators', 'rrze-log'); ?>
        </label>
        <?php
    }

   /**
    * Display auditEnabled field (Superadmins only).
    */
   public function auditEnabledField() {
       $enforced = $this->isAuditEnabledEnforcedByNetwork();
       $disabled = $enforced ? ' disabled="disabled"' : '';
       ?>
       <label>
           <input type="checkbox" id="rrze-log-audit-enabled" name="<?php printf('%s[auditEnabled]', $this->optionName); ?>" value="1" <?php checked($enforced ? 1 : $this->options->auditEnabled, 1); ?><?php echo $disabled; ?>>
           <?php _e('Enables admin/superadmin action logging to a separate audit log file.', 'rrze-log'); ?>
       </label>
       <?php if ($enforced) { ?>
           <p class="description">
               <?php _e('This setting is enforced by network configuration (rrze_settings) and cannot be disabled here.', 'rrze-log'); ?>
           </p>
       <?php } ?>
       <?php
   }


    /**
     * Display maxLines field.
     */
    public function maxLinesField() {
        ?>
        <label for="rrze-log-ttl">
            <input type="number" min="1000" max="5000" step="1" name="<?php printf('%s[maxLines]', $this->optionName); ?>" value="<?php echo esc_attr($this->options->maxLines); ?>" class="small-text">
        </label>
        <p class="description"><?php _e('Keep only the newest lines in the log file, up to the number specified here.', 'rrze-log'); ?></p>
        <?php
    }

    /**
     * Display debugMaxLines field.
     */
    public function debugMaxLinesField() {
        ?>
        <label for="rrze-log-ttl">
            <input type="number" min="1000" max="5000" step="1" name="<?php printf('%s[debugMaxLines]', $this->optionName); ?>" value="<?php echo esc_attr($this->options->debugMaxLines); ?>" class="small-text">
        </label>
        <p class="description"><?php _e('Keep only the newest lines in the log file, up to the number specified here.', 'rrze-log'); ?></p>
        <?php
    }

    public function debugLogAccessField() {
        ?>
        <textarea id="debug-log-access" cols="50" rows="5" name="<?php printf('%s[debugLogAccess]', $this->optionName); ?>"><?php echo esc_attr($this->getTextarea($this->options->debugLogAccess)); ?></textarea>
        <p class="description"><?php _e('List of usernames with access to view the wp debug log file. Enter one username per line.', 'rrze-log'); ?></p>
        <?php
    }

    /**
     * Validate options input.
     * @param  array $input
     * @return array
     */
    public function optionsValidate($input) {
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;

        $input['maxLines'] = !empty($input['maxLines']) && absint($input['maxLines'])
            ? min(absint($input['maxLines']), 5000)
            : $this->options->maxLines;

        $input['adminMenu'] = !empty($input['adminMenu']) ? 1 : 0;

        $enforced = $this->isAuditEnabledEnforcedByNetwork();

        if ($enforced) {
            $input['auditEnabled'] = 1;
        } elseif (is_multisite() && is_super_admin()) {
            $input['auditEnabled'] = !empty($input['auditEnabled']) ? 1 : 0;
        } else {
            $input['auditEnabled'] = !empty($this->options->auditEnabled) ? 1 : 0;
        }
        
        if ($this->isDebugLog) {
            $input['debugMaxLines'] = !empty($input['debugMaxLines']) && absint($input['debugMaxLines'])
                ? min(absint($input['debugMaxLines']), 5000)
                : $this->options->debugMaxLines;

            $input['debugLogAccess'] = isset($input['debugLogAccess']) ? $input['debugLogAccess'] : '';
            $debugLogAccess = $this->sanitizeTextarea($input['debugLogAccess']);
            $debugLogAccess = !empty($debugLogAccess) ? $this->sanitizeWpLogAccess($debugLogAccess) : '';
            $input['debugLogAccess'] = !empty($debugLogAccess) ? $debugLogAccess : '';
        }

        $this->options = (object) wp_parse_args($input, (array) $this->options);
        return (array) $this->options;
    }

    /**
     * Update network admin options.
     * @return void
     */
    public function settingsUpdate() {
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
     * @return void
     */
    public function settingsUpdateNotice() {
        $class = 'notice updated';
        $message = __("Settings saved.", 'rrze-settings');

        printf('<div class="%1s"><p>%2s</p></div>', esc_attr($class), esc_html($message));
    }

    /**
     * Set screen options.
     * @param  boolean $status
     * @param  string  $option
     * @param  integer $value
     * @return integer
     */
    public function setScreenOption($status, $option, $value) {
        if ('rrze_log_per_page' == $option) {
            return $value;
        }
        return $status;
    }

    /**
     * Add screen options.
     * @return void
     */
    public function screenOptions() {
        $option = 'per_page';
        $args = [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page',
        ];

        add_screen_option($option, $args);

        $this->listTable = new ListTable();
    }

    /**
     * Add debug screen options.
     * @return void
     */
    public function debugScreenOptions() {
        $option = 'per_page';
        $args = [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page',
        ];

        add_screen_option($option, $args);

        $this->debugListTable = new DebugListTable();
    }

    /**
     * Display log list table page.
     * @return void
     */
    public function logPage() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        $this->listTable->prepare_items();

        $action = isset($_GET['action']) ? $_GET['action'] : 'index';

        $s = isset($_REQUEST['s']) ? $_REQUEST['s'] : '';
        $level = isset($_REQUEST['level']) && in_array($_REQUEST['level'], Constants::LEVELS) ? $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) ? $_REQUEST['logfile'] : date('Y-m-d');

        $data = [
            'action' => $action,
            's' => $s,
            'level' => $level,
            'logfile' => $logFile,
            'listTable' => $this->listTable,
            'title' => __('Log', 'rrze-log'),
        ];

        $this->show('list-table', $data);
    }

    /**
     * Display WP debug log list table page.
     * Direct access protection: deny if debug log not enabled or user not allowed.
     * @return void
     */
    public function debugLogPage() {
        if (!$this->isDebugLog || !current_user_can('manage_options') || !$this->isUserInDebugLogAccess()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        $this->debugListTable->prepare_items();

        $action = isset($_GET['action']) ? $_GET['action'] : 'index';

        $s = isset($_REQUEST['s']) ? $_REQUEST['s'] : '';
        $level = isset($_REQUEST['level']) && in_array($_REQUEST['level'], Constants::DEBUG_LEVELS) ? $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) ? $_REQUEST['logfile'] : date('Y-m-d');

        $data = [
            'action' => $action,
            's' => $s,
            'level' => $level,
            'logfile' => $logFile,
            'listTable' => $this->debugListTable,
            'title' => __('Debug', 'rrze-log'),
        ];

        $this->show('list-table', $data);
    }

    /**
     * Render a view.
     * @param  string $view
     * @param  array  $data
     * @return void
     */
    protected function show($view, $data = []) {
        $data['messages'] = $this->messages;
        include 'Views/base.php';
    }

    /**
     * Get textarea value.
     * @param  array|string $option
     * @return string
     */
    protected function getTextarea($option) {
        if (!empty($option) && is_array($option)) {
            return implode(PHP_EOL, $option);
        }
        return '';
    }

    /**
     * Sanitize textarea input.
     * @param  string  $input
     * @param  boolean $sort
     * @return string|array
     */
    protected function sanitizeTextarea(string $input, bool $sort = true) {
        if (!empty($input)) {
            $inputAry = explode(PHP_EOL, sanitize_textarea_field($input));
            $inputAry = array_filter(array_map('trim', $inputAry));
            $inputAry = array_unique(array_values($inputAry));
            if ($sort) {
                sort($inputAry);
            }
            return !empty($inputAry) ? $inputAry : '';
        }
        return '';
    }

    /**
     * Sanitize WP log access input.
     * @param  array $data
     * @return array
     */
    public function sanitizeWpLogAccess(array $data) {
        $debugLogAccess = [];
        foreach ($data as $row) {
            $aryRow = explode(' - ', $row);
            $userLogin = isset($aryRow[0]) ? trim($aryRow[0]) : '';
            if (!$userLogin) {
                continue;
            }
            $args = [
                'blog_id' => 0,
                'role' => 'administrator',
                'fields' => [
                    'user_login',
                    'user_nicename',
                    'display_name',
                ],
                'search' => $userLogin,
                'search_columns' => [
                    'user_login',
                ],
            ];
            $users = get_users($args);
            $user = !empty($users[0]) && is_object(($users[0])) ? $users[0] : null;
            if (is_null($user)) {
                continue;
            }
            $userName = $user->display_name ?: $user->user_nicename;
            $debugLogAccess[$userLogin] = implode(' - ', [$userLogin, $userName]);
        }
        ksort($debugLogAccess);
        return $debugLogAccess;
    }

    /**
     * Check if current user is in debug log access list.
     * @return boolean
     */
    protected function isUserInDebugLogAccess() {
        if (is_super_admin()) {
            return true;
        }
        $debugLogAccess = $this->options->debugLogAccess;
        if (!empty($debugLogAccess) && is_array($debugLogAccess)) {
            $currentUserLogin = wp_get_current_user()->data->user_login;
            foreach ($debugLogAccess as $row) {
                $aryRow = explode(' - ', $row);
                $userLogin = isset($aryRow[0]) ? trim($aryRow[0]) : '';
                if ($userLogin == $currentUserLogin) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
    * Check whether audit logging is enforced by network settings (rrze_settings).
    * @return bool
    */
   protected function isAuditEnabledEnforcedByNetwork(): bool {
       if (!is_multisite()) {
           return false;
       }

       $settingsOptions = get_site_option('rrze_settings');

       if (is_array($settingsOptions)) {
           $settingsOptions = (object) $settingsOptions;
       }

       if (!is_object($settingsOptions) || !isset($settingsOptions->plugins) || !is_object($settingsOptions->plugins)) {
           return false;
       }

       return !empty($settingsOptions->plugins->rrze_log_auditEnabled);
   }

}
