<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

final class Settings {

    /**
     * Option name.
     * @var string
     */
    protected string $optionName;

    /**
     * Options values.
     * @var object
     */
    protected object $options;

    /**
     * WP_List_Table object.
     * @var object
     */
    protected $listTable;

    /**
     * WP_List_Table object.
     * @var object
     */
    protected $auditListTable;

    /**
     * WP_List_Table object.
     * @var object
     */
    protected $debugListTable;

    /**
     * List table notice messages.
     * @var array
     */
    protected array $messages = [];

    /**
     * Is Debug Log set?
     * @var bool
     */
    protected bool $isDebugLog = false;

    /**
     * Error message.
     * @var string
     */
    protected string $error = '';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * Initiate hooks.
     */
    public function loaded(): void {
        add_action('network_admin_menu', [$this, 'networkAdminMenu']);
        add_action('network_admin_menu', [$this, 'settingsSection']);
        add_action('network_admin_menu', [$this, 'settingsUpdate']);
        add_action('admin_menu', [$this, 'adminMenu']);

        add_filter('set-screen-option', [$this, 'setScreenOption'], 10, 3);

        $debug = Utils::isDebugLog();
        if ($debug instanceof \WP_Error && is_wp_error($debug)) {
            if (is_multisite()) {
                add_action('network_admin_notices', [$this, 'adminErrorNotice']);
            } else {
                add_action('admin_notices', [$this, 'adminErrorNotice']);
            }

            $this->error = $debug->get_error_message();
            $this->isDebugLog = false;
            return;
        }

        $this->isDebugLog = (bool) $debug;
    }

    /**
     * Admin error notice.
     */
    public function adminErrorNotice(): void {
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr('notice notice-error'),
            esc_html($this->error)
        );
    }

    /**
     * Add network admin menu.
     */
    public function networkAdminMenu(): void {
        $this->options = Options::getOptions();

        $logPage = add_menu_page(
            __('Protokoll', 'rrze-log'),
            __('Protokoll', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this, 'logPage'],
            'dashicons-list-view'
        );
        add_action("load-$logPage", [$this, 'screenOptions']);

        add_submenu_page(
            'rrze-log',
            __('Action Log', 'rrze-log'),
            __('Action Log', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this, 'logPage']
        );
        
        if ($this->isDebugLog) {
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
        
        if (is_super_admin() && !empty($this->options->auditEnabled)) {
            $auditPage = add_submenu_page(
                'rrze-log',
                __('Audit', 'rrze-log'),
                __('Audit', 'rrze-log'),
                'manage_options',
                'rrze-log-audit',
                [$this, 'auditLogPage']
            );
            add_action("load-$auditPage", [$this, 'auditScreenOptions']);
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


    /**
     * Add admin menu (Tools) if enabled.
     */
    public function adminMenu(): void {
        if (is_multisite() && is_network_admin()) {
            return;
        }

        $this->options = Options::getOptions();

        $logPage = add_menu_page(
            __('Protokoll', 'rrze-log'),
            __('Protokoll', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this, 'logPage'],
            'dashicons-list-view'
        );
        add_action("load-$logPage", [$this, 'screenOptions']);

        add_submenu_page(
            'rrze-log',
            __('Action Log', 'rrze-log'),
            __('Action Log', 'rrze-log'),
            'manage_options',
            'rrze-log',
            [$this, 'logPage']
        );

        if (!empty($this->options->auditEnabled)) {
            $auditPage = add_submenu_page(
                'rrze-log',
                __('Audit', 'rrze-log'),
                __('Audit', 'rrze-log'),
                'manage_options',
                'rrze-log-audit',
                [$this, 'auditLogPage']
            );
            add_action("load-$auditPage", [$this, 'auditScreenOptions']);
        }

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
    }




    /**
     * Display settings page.
     */
    public function settingsPage(): void {
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
     * Add settings sections and fields.
     */
    public function settingsSection(): void {
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

        if (is_super_admin()) {
            add_settings_section(
                'rrze-log-audit-settings',
                __('Admin Audit Log', 'rrze-log'),
                '__return_false',
                'rrze-log-settings'
            );

            add_settings_field(
                'rrze-log-auditEnabled',
                __('Enable Admin Audit Log', 'rrze-log'),
                [$this, 'auditEnabledField'],
                'rrze-log-settings',
                'rrze-log-audit-settings'
            );

            add_settings_field(
                'rrze-log-auditTypes',
                __('Audit Types', 'rrze-log'),
                [$this, 'auditTypesField'],
                'rrze-log-settings',
                'rrze-log-audit-settings'
            );

            add_settings_field(
                'rrze-log-auditMaxLines',
                __('Truncate audit log file to last N lines', 'rrze-log'),
                [$this, 'auditMaxLinesField'],
                'rrze-log-settings',
                'rrze-log-audit-settings'
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
                'rrze-log-debugMaxLines',
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
    public function enabledField(): void { ?>
        <label>
            <input type="checkbox" id="rrze-log-enabled" name="<?php printf('%s[enabled]', $this->optionName); ?>" value="1" <?php checked($this->options->enabled, 1); ?>>
            <?php _e('Enables network-wide logging', 'rrze-log'); ?>
        </label>
        <?php
    }

    /**
     * Display adminMenu field.
     */
    public function adminMenuField(): void { ?>
        <label>
            <input type="checkbox" id="rrze-log-admin-menu" name="<?php printf('%s[adminMenu]', $this->optionName); ?>" value="1" <?php checked($this->options->adminMenu, 1); ?>>
            <?php _e('Enables network wide the Log menu for administrators', 'rrze-log'); ?>
        </label>
        <?php
    }

    /**
     * Display maxLines field (main rrze-log file).
     */
    public function maxLinesField(): void { ?>
        <label for="rrze-log-maxLines">
            <input
                type="number"
                min="1000"
                max="50000"
                step="1"
                id="rrze-log-maxLines"
                name="<?php printf('%s[maxLines]', $this->optionName); ?>"
                value="<?php echo esc_attr((string) $this->options->maxLines); ?>"
                class="small-text"
            >
        </label>
        <p class="description"><?php _e('Keep only the newest lines in the main log file, up to the number specified here.', 'rrze-log'); ?></p>
        <?php
    }

    /**
     * Display auditEnabled field (superadmin only).
     */
    public function auditEnabledField(): void { ?>
        <label>
            <input type="checkbox" id="rrze-log-audit-enabled" name="<?php printf('%s[auditEnabled]', $this->optionName); ?>" value="1" <?php checked($this->options->auditEnabled ?? 0, 1); ?>>
            <?php _e('Enables logging of administrative actions (audit log).', 'rrze-log'); ?>
        </label>
        <?php
    }

    /**
     * Display auditTypes field (superadmin only).
     */
    public function auditTypesField(): void {
        $types = isset($this->options->auditTypes) && is_array($this->options->auditTypes) ? $this->options->auditTypes : [];
        $cms = !empty($types['cms']) ? 1 : 0;
        $site = !empty($types['site']) ? 1 : 0;
        $editorial = !empty($types['editorial']) ? 1 : 0; ?>
        <fieldset>
            <label>
                <input type="checkbox" name="<?php printf('%s[auditTypes][cms]', $this->optionName); ?>" value="1" <?php checked($cms, 1); ?>>
                <?php _e('CMS-Administration', 'rrze-log'); ?>
            </label>
            <br>

            <label>
                <input type="checkbox" name="<?php printf('%s[auditTypes][site]', $this->optionName); ?>" value="1" <?php checked($site, 1); ?>>
                <?php _e('Website-Administration', 'rrze-log'); ?>
            </label>
            <br>

            <label>
                <input type="checkbox" name="<?php printf('%s[auditTypes][editorial]', $this->optionName); ?>" value="1" <?php checked($editorial, 1); ?>>
                <?php _e('Redaktion', 'rrze-log'); ?>
            </label>

            <p class="description">
                <?php _e('Controls which categories are written to the audit log. Default when enabling audit: CMS + Website enabled, Editorial disabled.', 'rrze-log'); ?>
            </p>
        </fieldset>
        <?php
    }

    /**
     * Display auditMaxLines field (audit log file).
     */
    public function auditMaxLinesField(): void {
        $value = isset($this->options->auditMaxLines) ? (int) $this->options->auditMaxLines : 1000; ?>
        <label for="rrze-log-auditMaxLines">
            <input
                type="number"
                min="1000"
                max="50000"
                step="1"
                id="rrze-log-auditMaxLines"
                name="<?php printf('%s[auditMaxLines]', $this->optionName); ?>"
                value="<?php echo esc_attr((string) $value); ?>"
                class="small-text"
            >
        </label>
        <p class="description"><?php _e('Keep only the newest lines in the audit log file, up to the number specified here.', 'rrze-log'); ?></p>
        <?php
    }

    /**
     * Display debugMaxLines field.
     */
    public function debugMaxLinesField(): void { ?>
        <label for="rrze-log-debugMaxLines">
            <input type="number" min="1000" max="50000" step="1" id="rrze-log-debugMaxLines" name="<?php printf('%s[debugMaxLines]', $this->optionName); ?>" value="<?php echo esc_attr((string) $this->options->debugMaxLines); ?>" class="small-text">
        </label>
        <p class="description"><?php _e('Keep only the newest lines in the log file, up to the number specified here.', 'rrze-log'); ?></p>
        <?php
    }

    /**
     * Display debugLogAccess field.
     */
    public function debugLogAccessField(): void { ?>
        <textarea id="debug-log-access" cols="50" rows="5" name="<?php printf('%s[debugLogAccess]', $this->optionName); ?>"><?php echo esc_attr($this->getTextarea($this->options->debugLogAccess)); ?></textarea>
        <p class="description"><?php _e('List of usernames with access to view the wp debug log file. Enter one username per line.', 'rrze-log'); ?></p>
        <?php
    }

    /**
     * Validate options input.
     *
     * @param array $input
     * @return array
     */
    public function optionsValidate(array $input): array {
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;

        $input['maxLines'] = !empty($input['maxLines']) && absint($input['maxLines'])
            ? min(absint($input['maxLines']), 50000)
            : $this->options->maxLines;

        $input['adminMenu'] = !empty($input['adminMenu']) ? 1 : 0;

        if (is_super_admin()) {
            $auditEnabled = !empty($input['auditEnabled']) ? 1 : 0;
            $input['auditEnabled'] = $auditEnabled;

            if ($auditEnabled === 1) {
                $types = isset($input['auditTypes']) && is_array($input['auditTypes']) ? $input['auditTypes'] : [];
                $normalized = [
                    'cms' => !empty($types['cms']) ? 1 : 0,
                    'site' => !empty($types['site']) ? 1 : 0,
                    'editorial' => !empty($types['editorial']) ? 1 : 0,
                ];

                $sum = (int) $normalized['cms'] + (int) $normalized['site'] + (int) $normalized['editorial'];
                if ($sum <= 0) {
                    $normalized = [
                        'cms' => 1,
                        'site' => 1,
                        'editorial' => 0,
                    ];
                }

                $input['auditTypes'] = $normalized;
            } else {
                $input['auditTypes'] = isset($this->options->auditTypes) && is_array($this->options->auditTypes)
                    ? $this->options->auditTypes
                    : [
                        'cms' => 1,
                        'site' => 1,
                        'editorial' => 0,
                    ];
            }

            $currentAuditMaxLines = isset($this->options->auditMaxLines) ? (int) $this->options->auditMaxLines : 1000;
            $input['auditMaxLines'] = !empty($input['auditMaxLines']) && absint($input['auditMaxLines'])
                ? min(absint($input['auditMaxLines']), 50000)
                : $currentAuditMaxLines;
        } else {
            unset($input['auditEnabled'], $input['auditTypes'], $input['auditMaxLines']);
        }

        if ($this->isDebugLog) {
            $input['debugMaxLines'] = !empty($input['debugMaxLines']) && absint($input['debugMaxLines'])
                ? min(absint($input['debugMaxLines']), 50000)
                : $this->options->debugMaxLines;

            $input['debugLogAccess'] = isset($input['debugLogAccess']) ? (string) $input['debugLogAccess'] : '';
            $debugLogAccess = $this->sanitizeTextarea($input['debugLogAccess']);
            $debugLogAccess = !empty($debugLogAccess) ? $this->sanitizeWpLogAccess($debugLogAccess) : '';
            $input['debugLogAccess'] = !empty($debugLogAccess) ? $debugLogAccess : '';
        }

        $this->options = (object) wp_parse_args($input, (array) $this->options);
        return (array) $this->options;
    }

    /**
     * Update network admin options.
     */
    public function settingsUpdate(): void {
        if (!is_network_admin() || !isset($_POST['rrze-log-settings-submit-primary'])) {
            return;
        }

        check_admin_referer('rrze-log-settings-options');

        $input = isset($_POST[$this->optionName]) && is_array($_POST[$this->optionName]) ? $_POST[$this->optionName] : [];
        update_site_option($this->optionName, $this->optionsValidate($input));

        $this->options = Options::getOptions();

        add_action('network_admin_notices', [$this, 'settingsUpdateNotice']);
    }

    /**
     * Update notice.
     */
    public function settingsUpdateNotice(): void {
        $class = 'notice updated';
        $message = __("Settings saved.", 'rrze-settings');

        printf('<div class="%1s"><p>%2s</p></div>', esc_attr($class), esc_html($message));
    }

    /**
     * Set screen options.
     */
    public function setScreenOption($status, $option, $value) {
        if ($option === 'rrze_log_per_page') {
            return $value;
        }
        return $status;
    }

    /**
     * Add screen options for main log.
     */
    public function screenOptions(): void {
        add_screen_option('per_page', [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page',
        ]);

        $this->listTable = new ListTable();
    }

    /**
     * Add screen options for audit log.
     */
    public function auditScreenOptions(): void {
        add_screen_option('per_page', [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page',
        ]);

        $this->auditListTable = new AuditListTable();
    }

    /**
     * Add debug screen options.
     */
    public function debugScreenOptions(): void {
        add_screen_option('per_page', [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page',
        ]);

        $this->debugListTable = new DebugListTable();
    }

    /**
     * Display log list table page.
     */
    public function logPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        $this->listTable->prepare_items();

        $action = isset($_GET['action']) ? (string) $_GET['action'] : 'index';

        $s = isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '';
        $level = isset($_REQUEST['level']) && in_array($_REQUEST['level'], Constants::LEVELS, true) ? (string) $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) ? (string) $_REQUEST['logfile'] : date('Y-m-d');

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
     * Display audit log list table page.
     */
    public function auditLogPage(): void {
        $this->options = Options::getOptions();

        if (!is_super_admin() || empty($this->options->auditEnabled)) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        if (!$this->auditListTable instanceof AuditListTable) {
            $this->auditListTable = new AuditListTable();
        }

        $this->auditListTable->prepare_items();

        $data = [
            'action' => 'audit',
            's' => isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '',
            'listTable' => $this->auditListTable,
            'title' => __('Audit', 'rrze-log'),
        ];

        $this->show('list-table', $data);
    }

    /**
     * Display WP debug log list table page.
     */
    public function debugLogPage(): void {
        if (!current_user_can('manage_options') || !$this->isUserInDebugLogAccess()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        $this->debugListTable->prepare_items();

        $action = isset($_GET['action']) ? (string) $_GET['action'] : 'index';

        $s = isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '';
        $level = isset($_REQUEST['level']) && in_array($_REQUEST['level'], Constants::DEBUG_LEVELS, true) ? (string) $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) ? (string) $_REQUEST['logfile'] : date('Y-m-d');

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
     */
    protected function show(string $view, array $data = []): void {
        $data['messages'] = $this->messages;
        include 'Views/base.php';
    }

    /**
     * Get textarea value.
     *
     * @param array|string $option
     * @return string
     */
    protected function getTextarea($option): string {
        if (!empty($option) && is_array($option)) {
            return implode(PHP_EOL, $option);
        }
        return '';
    }

    /**
     * Sanitize textarea input.
     *
     * @param string $input
     * @param bool   $sort
     * @return string|array
     */
    protected function sanitizeTextarea(string $input, bool $sort = true) {
        if ($input === '') {
            return '';
        }

        $inputAry = explode(PHP_EOL, sanitize_textarea_field($input));
        $inputAry = array_filter(array_map('trim', $inputAry));
        $inputAry = array_unique(array_values($inputAry));

        if ($sort) {
            sort($inputAry);
        }

        return !empty($inputAry) ? $inputAry : '';
    }

    /**
     * Sanitize WP log access input.
     *
     * @param array $data
     * @return array
     */
    public function sanitizeWpLogAccess(array $data): array {
        $debugLogAccess = [];

        foreach ($data as $row) {
            $aryRow = explode(' - ', $row);
            $userLogin = isset($aryRow[0]) ? trim($aryRow[0]) : '';
            if ($userLogin === '') {
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
            $user = !empty($users[0]) && is_object($users[0]) ? $users[0] : null;
            if (!$user) {
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
     */
    protected function isUserInDebugLogAccess(): bool {
        if (is_super_admin()) {
            return true;
        }

        $debugLogAccess = $this->options->debugLogAccess;

        if (!empty($debugLogAccess) && is_array($debugLogAccess)) {
            $currentUserLogin = (string) wp_get_current_user()->data->user_login;

            foreach ($debugLogAccess as $row) {
                $aryRow = explode(' - ', $row);
                $userLogin = isset($aryRow[0]) ? trim($aryRow[0]) : '';
                if ($userLogin === $currentUserLogin) {
                    return true;
                }
            }
        }

        return false;
    }
}
