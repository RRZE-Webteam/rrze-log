<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

final class Settings {
    private const SITE_LOG_ACCESS_CAP = 'rrze_log_view_site_logs';


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
    * WP_List_Table object for superadmin audit log.
    * @var object
    */
    protected $superadminAuditListTable;

    /**
    * WP_List_Table object for websupport audit log.
    * @var object
    */
    protected $websupportAuditListTable;
    
    
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
        add_filter('user_has_cap', [$this, 'grantSiteLogAccessCap'], 10, 4);
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

        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'networkAdminMenu']);
            if ($this->canAccessSettings()) {
                add_action('network_admin_menu', [$this, 'settingsSection']);
                add_action('network_admin_menu', [$this, 'settingsUpdate']);
            }
            
           // Site: Menü für Action Log + Debug (keine Settings!)
            add_action('admin_menu', [$this, 'singleSiteMenu']);
            return;
        }

        // Single site
        add_action('admin_menu', [$this, 'singleSiteMenu']);
        if ($this->canAccessSettings()) {
            add_action('admin_menu', [$this, 'settingsSection']);
            add_action('admin_menu', [$this, 'settingsUpdate']);
        }
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

        $cap = 'manage_network_options';
        
        $logPage = add_menu_page(
            __('Protokoll', 'rrze-log'),
            __('Protokoll', 'rrze-log'),
            $cap,
            'rrze-log',
            [$this, 'logPage'],
            'dashicons-list-view'
        );
        add_action("load-$logPage", [$this, 'screenOptions']);

        add_submenu_page(
            'rrze-log',
            __('Action Log', 'rrze-log'),
            __('Action Log', 'rrze-log'),
            $cap,
            'rrze-log',
            [$this, 'logPage']
        );
        
        if ($this->isDebugLog) {
            $debugLogPage = add_submenu_page(
                'rrze-log',
                __('Debug', 'rrze-log'),
                __('Debug', 'rrze-log'),
                $cap,
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
               $cap,
                'rrze-log-audit',
                [$this, 'auditLogPage']
            );
            add_action("load-$auditPage", [$this, 'auditScreenOptions']);
        }
        if (is_multisite() && is_super_admin() && !empty($this->options->auditEnabled)) {
            $superAuditPage = add_submenu_page(
                'rrze-log',
                __('Superadmin Audit', 'rrze-log'),
                __('Superadmin Audit', 'rrze-log'),
                $cap,
                'rrze-log-superadmin-audit',
                [$this, 'superadminAuditLogPage']
            );
            add_action("load-$superAuditPage", [$this, 'superadminAuditScreenOptions']);

            $websupportAuditPage = add_submenu_page(
                'rrze-log',
                __('Websupport Audit', 'rrze-log'),
                __('Websupport Audit', 'rrze-log'),
                $cap,
                'rrze-log-websupport-audit',
                [$this, 'websupportAuditLogPage']
            );
            add_action("load-$websupportAuditPage", [$this, 'websupportAuditScreenOptions']);
        }

        if ($this->canAccessSettings()) {
            add_submenu_page(
                'rrze-log',
                __('Settings', 'rrze-log'),
                __('Settings', 'rrze-log'),
                $cap,
                'rrze-log-settings',
                [$this, 'settingsPage']
            );
        }
    }


    /**
     * Add admin menu (Tools) if enabled.
     */
    public function singleSiteMenu(): void {
        $this->options = Options::getOptions();

        if (!$this->canAdminSeeSiteLogs()) {
            return;
        }

        $logPage = add_menu_page(
            __('Protokoll', 'rrze-log'),
            __('Protokoll', 'rrze-log'),
            self::SITE_LOG_ACCESS_CAP,
            'rrze-log',
            [$this, 'logPage'],
            'dashicons-list-view'
        );
        add_action("load-$logPage", [$this, 'screenOptions']);

        add_submenu_page(
            'rrze-log',
            __('Action Log', 'rrze-log'),
            __('Action Log', 'rrze-log'),
            self::SITE_LOG_ACCESS_CAP,
            'rrze-log',
            [$this, 'logPage']
        );
       
        if ($this->isDebugLog && $this->isUserInDebugLogAccess()) {
            $debugLogPage = add_submenu_page(
                'rrze-log',
                __('Debug', 'rrze-log'),
                __('Debug', 'rrze-log'),
                self::SITE_LOG_ACCESS_CAP,
                'rrze-log-debug',
                [$this, 'debugLogPage']
            );
            add_action("load-$debugLogPage", [$this, 'debugScreenOptions']);
        }
         // Audit nur für Superadmins und nur wenn Audit aktiviert ist
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

    }




    /**
     * Display settings page.
     */
    public function settingsPage(): void {
        if (!$this->canAccessSettings()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        global $title;

        $activeTab = $this->getCurrentSettingsTab();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <?php $this->renderSettingsTabs($activeTab); ?>
            <form method="post">
                <?php do_settings_sections($this->getSettingsPageForTab($activeTab)); ?>
                <input type="hidden" name="rrze-log-settings-tab" value="<?php echo esc_attr($activeTab); ?>">
                <?php settings_fields('rrze-log-settings'); ?>
                <?php submit_button(__('Save Changes', 'rrze-settings'), 'primary', 'rrze-log-settings-submit-primary'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Returns available settings tabs.
     */
    protected function getSettingsTabs(): array {
        return [
            'general' => __('Allgemein', 'rrze-log'),
            'action' => __('Action Logs', 'rrze-log'),
            'debug' => __('Debug Logs', 'rrze-log'),
            'audit' => __('Audit Logs', 'rrze-log'),
        ];
    }

    /**
     * Returns the current settings tab.
     */
    protected function getCurrentSettingsTab(): string {
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'general';

        return $this->normalizeSettingsTab($tab);
    }

    /**
     * Normalizes a settings tab.
     */
    protected function normalizeSettingsTab(string $tab): string {
        $tabs = $this->getSettingsTabs();

        if (!isset($tabs[$tab])) {
            return 'general';
        }

        if ($tab === 'audit' && !is_super_admin()) {
            return 'general';
        }

        return $tab;
    }

    /**
     * Returns the Settings API page name for a tab.
     */
    protected function getSettingsPageForTab(string $tab): string {
        return 'rrze-log-settings-' . $tab;
    }

    /**
     * Renders settings tabs.
     */
    protected function renderSettingsTabs(string $activeTab): void {
        $tabs = $this->getSettingsTabs();
        $baseUrl = add_query_arg(
            [
                'page' => 'rrze-log-settings',
            ],
            is_network_admin() ? network_admin_url('admin.php') : admin_url('admin.php')
        );
        ?>
        <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('Settings tabs', 'rrze-log'); ?>">
            <?php foreach ($tabs as $tab => $label) { ?>
                <?php
                if ($tab === 'audit' && !is_super_admin()) {
                    continue;
                }

                $url = add_query_arg('tab', $tab, $baseUrl);
                $class = $tab === $activeTab ? 'nav-tab nav-tab-active' : 'nav-tab';
                ?>
                <a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php } ?>
        </nav>
        <?php
    }

    /**
     * Add settings sections and fields.
     */
    public function settingsSection(): void {
        add_settings_section(
            'rrze-log-adminmenu-settings',
            __('Admin Menu', 'rrze-log'),
            '__return_false',
            'rrze-log-settings-general'
        );

        add_settings_field(
            'rrze-log-adminMenu',
            __('Enable administration menus', 'rrze-log'),
            [$this, 'adminMenuField'],
            'rrze-log-settings-general',
            'rrze-log-adminmenu-settings'
        );

        add_settings_field(
            'rrze-log-logAccess',
            __('Admin log access (allowlist)', 'rrze-log'),
            [$this, 'logAccessField'],
            'rrze-log-settings-general',
            'rrze-log-adminmenu-settings'
        );

        add_settings_section(
            'rrze-log-action-log-settings',
            __('Action Logs', 'rrze-log'),
            '__return_false',
            'rrze-log-settings-action'
        );

        add_settings_field(
            'rrze-log-enabled',
            __('Enable Log', 'rrze-log'),
            [$this, 'enabledField'],
            'rrze-log-settings-action',
            'rrze-log-action-log-settings'
        );

        foreach (Constants::LEVELS as $level) {
            $sectionId = 'rrze-log-action-log-' . strtolower($level);

            add_settings_section(
                $sectionId,
                sprintf(
                    /* translators: %s: error level */
                    __('%s Log', 'rrze-log'),
                    $level
                ),
                [$this, 'actionLogLevelSectionDescription'],
                'rrze-log-settings-action',
                [
                    'level' => $level,
                ]
            );

            add_settings_field(
                'rrze-log-action-log-retention-' . strtolower($level),
                __('Retention', 'rrze-log'),
                [$this, 'actionLogLevelField'],
                'rrze-log-settings-action',
                $sectionId,
                [
                    'level' => $level,
                ]
            );
        }

        add_settings_section(
            'rrze-log-wp-debug-settings',
            __('WP Debug Log', 'rrze-log'),
            '__return_false',
            'rrze-log-settings-debug'
        );

        if ($this->isDebugLog) {
            add_settings_field(
                'rrze-log-debugMaxLines',
                __('Truncate log file to last N lines', 'rrze-log'),
                [$this, 'debugMaxLinesField'],
                'rrze-log-settings-debug',
                'rrze-log-wp-debug-settings'
            );
        } else {
            add_settings_field(
                'rrze-log-debugLogDisabled',
                __('Status', 'rrze-log'),
                [$this, 'debugLogDisabledField'],
                'rrze-log-settings-debug',
                'rrze-log-wp-debug-settings'
            );
        }

        if (is_super_admin()) {
            add_settings_section(
                'rrze-log-audit-settings',
                __('Admin Audit Log', 'rrze-log'),
                '__return_false',
                'rrze-log-settings-audit'
            );

            add_settings_field(
                'rrze-log-auditEnabled',
                __('Enable Admin Audit Log', 'rrze-log'),
                [$this, 'auditEnabledField'],
                'rrze-log-settings-audit',
                'rrze-log-audit-settings'
            );

            add_settings_field(
                'rrze-log-auditTypes',
                __('Audit Types', 'rrze-log'),
                [$this, 'auditTypesField'],
                'rrze-log-settings-audit',
                'rrze-log-audit-settings'
            );

            add_settings_field(
                'rrze-log-auditMaxLines',
                __('Truncate audit log file to last N lines', 'rrze-log'),
                [$this, 'auditMaxLinesField'],
                'rrze-log-settings-audit',
                'rrze-log-audit-settings'
            );
            
            add_settings_field(
                'rrze-log-superadminAuditMaxLines',
                __('Truncate superadmin audit log file to last N lines', 'rrze-log'),
                [$this, 'superadminAuditMaxLinesField'],
                'rrze-log-settings-audit',
                'rrze-log-audit-settings'
            );

            add_settings_field(
                'rrze-log-websupportAuditMaxLines',
                __('Truncate websupport audit log file to last N lines', 'rrze-log'),
                [$this, 'websupportAuditMaxLinesField'],
                'rrze-log-settings-audit',
                'rrze-log-audit-settings'
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
     * Display message when WP Debug Log is not configured for this plugin.
     */
    public function debugLogDisabledField(): void {
        echo '<p class="description">';
        echo esc_html__('WP Debug Log is currently not configured for RRZE Log.', 'rrze-log');
        echo '</p>';
    }

    /**
     * Display Action Log level section description.
     */
    public function actionLogLevelSectionDescription(array $section): void {
        $level = $this->getLevelFromSettingsArgs($section);
        $file = Constants::getLogFileForLevel($level);

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: 1: error level, 2: log file path */
                __('Settings for %1$s entries written to %2$s.', 'rrze-log'),
                $level,
                $file
            ))
        );
    }

    /**
     * Display Action Log level retention controls.
     */
    public function actionLogLevelField(array $args): void {
        $level = $this->getLevelFromSettingsArgs($args);
        $limits = isset($this->options->levelMaxLines) && is_array($this->options->levelMaxLines)
            ? $this->options->levelMaxLines
            : [];
        $rotations = isset($this->options->levelRotation) && is_array($this->options->levelRotation)
            ? $this->options->levelRotation
            : [];

        $limit = isset($limits[$level]) ? (int) $limits[$level] : (int) $this->options->maxLines;
        $rotation = isset($rotations[$level]) ? (string) $rotations[$level] : 'none';
        $limitId = 'rrze-log-level-max-lines-' . strtolower($level);
        $rotationId = 'rrze-log-level-rotation-' . strtolower($level);
        $statusId = 'rrze-log-level-rotation-status-' . strtolower($level);
        $hasRotation = $rotation !== 'none';
        ?>
        <fieldset class="rrze-log-action-level-settings<?php echo $hasRotation ? ' rrze-log-has-rotation' : ''; ?>">
            <p>
                <label for="<?php echo esc_attr($limitId); ?>">
                    <?php esc_html_e('Maximum lines', 'rrze-log'); ?>
                </label><br>
                <input
                    type="number"
                    min="1000"
                    max="50000"
                    step="1"
                    id="<?php echo esc_attr($limitId); ?>"
                    name="<?php echo esc_attr(sprintf('%s[levelMaxLines][%s]', $this->optionName, $level)); ?>"
                    value="<?php echo esc_attr((string) $limit); ?>"
                    class="small-text"
                    data-rrze-log-max-lines="1"
                    aria-describedby="<?php echo esc_attr($statusId); ?>"
                >
            </p>

            <p>
                <label for="<?php echo esc_attr($rotationId); ?>">
                    <?php esc_html_e('Rotation', 'rrze-log'); ?>
                </label><br>
                <select
                    id="<?php echo esc_attr($rotationId); ?>"
                    name="<?php echo esc_attr(sprintf('%s[levelRotation][%s]', $this->optionName, $level)); ?>"
                    data-rrze-log-rotation="1"
                    aria-describedby="<?php echo esc_attr($statusId); ?>"
                >
                    <?php foreach (Constants::LOG_ROTATION_INTERVALS as $interval) { ?>
                        <option value="<?php echo esc_attr($interval); ?>"<?php selected($rotation, $interval); ?>>
                            <?php echo esc_html($this->getRotationLabel($interval)); ?>
                        </option>
                    <?php } ?>
                </select>
            </p>
            <p id="<?php echo esc_attr($statusId); ?>" class="rrze-log-rotation-status">
                <span class="rrze-log-rotation-status-active">
                    <?php esc_html_e('Rotation is active. Line-based truncation for this level is disabled.', 'rrze-log'); ?>
                </span>
                <span class="rrze-log-rotation-status-inactive">
                    <?php esc_html_e('Rotation is inactive. This level is truncated to the configured maximum number of lines.', 'rrze-log'); ?>
                </span>
            </p>
        </fieldset>
        <?php
    }

    /**
     * Extract and normalize an Action Log level from Settings API callback arguments.
     */
    protected function getLevelFromSettingsArgs(array $args): string {
        $level = '';

        if (isset($args['level'])) {
            $level = (string) $args['level'];
        } elseif (isset($args['args']) && is_array($args['args']) && isset($args['args']['level'])) {
            $level = (string) $args['args']['level'];
        }

        return Constants::normalizeLogLevel($level);
    }

    /**
     * Returns the label for a rotation interval.
     */
    protected function getRotationLabel(string $interval): string {
        switch ($interval) {
            case 'daily':
                return __('Daily', 'rrze-log');
            case 'weekly':
                return __('Weekly', 'rrze-log');
            case 'monthly':
                return __('Monthly', 'rrze-log');
            case 'none':
            default:
                return __('No rotation', 'rrze-log');
        }
    }

    /**
     * Sanitize per-level Action Log line limits.
     */
    protected function sanitizeLevelMaxLines(array $input): array {
        $current = isset($this->options->levelMaxLines) && is_array($this->options->levelMaxLines)
            ? $this->options->levelMaxLines
            : [];

        $fallback = isset($this->options->maxLines) ? (int) $this->options->maxLines : 1000;
        if ($fallback <= 0) {
            $fallback = 1000;
        }

        $output = [];

        foreach (Constants::LEVELS as $level) {
            $currentValue = isset($current[$level]) ? (int) $current[$level] : $fallback;
            $value = isset($input[$level]) ? absint($input[$level]) : $currentValue;
            $output[$level] = $value > 0 ? min($value, 50000) : $currentValue;
        }

        return $output;
    }

    /**
     * Sanitize per-level Action Log rotation settings.
     */
    protected function sanitizeLevelRotation(array $input): array {
        $current = isset($this->options->levelRotation) && is_array($this->options->levelRotation)
            ? $this->options->levelRotation
            : [];

        $output = [];

        foreach (Constants::LEVELS as $level) {
            $value = isset($input[$level]) ? sanitize_key((string) $input[$level]) : ($current[$level] ?? 'none');
            $output[$level] = in_array($value, Constants::LOG_ROTATION_INTERVALS, true) ? $value : 'none';
        }

        return $output;
    }
    
    /**
    * Display logAccess field.
    *
    * Admins and Websupport users can see Action Log + Debug on site level.
    * Listed users get additional access.
    */
    public function logAccessField(): void {
        $val = '';
        if (isset($this->options->logAccess)) {
            $val = $this->getTextarea($this->options->logAccess);
        }

        echo '<textarea id="rrze-log-logAccess" cols="50" rows="5" name="';
        printf('%s[logAccess]', $this->optionName);
        echo '">';
        echo esc_textarea($val);
        echo '</textarea>';

        echo '<p class="description">';
        echo esc_html__(
            'Optional allowlist for additional users who may view admin logs (Action Log + Debug) and their menus on site level. One username per line. Administrators and Websupport users do not need to be listed.',
            'rrze-log'
        );
        echo '</p>';
    }



    /**
     * Validate options input.
     *
     * @param array $input
     * @param string $tab
     * @return array
     */
    public function optionsValidate(array $input, string $tab = 'general'): array {
        $tab = $this->normalizeSettingsTab($tab);
        $output = (array) $this->options;

        if ($tab === 'general') {
            $output['adminMenu'] = !empty($input['adminMenu']) ? 1 : 0;

            if (!is_multisite() || is_super_admin()) {
                $rawLogAccess = isset($input['logAccess']) ? (string) $input['logAccess'] : '';
                $logAccess = $this->sanitizeTextarea($rawLogAccess);
                if (!empty($logAccess) && is_array($logAccess)) {
                    $logAccess = $this->sanitizeWpLogAccess($logAccess);
                }
                $output['logAccess'] = !empty($logAccess) ? $logAccess : '';
            }
        } elseif ($tab === 'action') {
            $output['enabled'] = !empty($input['enabled']) ? 1 : 0;
            $output['levelMaxLines'] = $this->sanitizeLevelMaxLines(
                isset($input['levelMaxLines']) && is_array($input['levelMaxLines']) ? $input['levelMaxLines'] : []
            );
            $output['levelRotation'] = $this->sanitizeLevelRotation(
                isset($input['levelRotation']) && is_array($input['levelRotation']) ? $input['levelRotation'] : []
            );
        } elseif ($tab === 'debug') {
            if ($this->isDebugLog) {
                $output['debugMaxLines'] = !empty($input['debugMaxLines']) && absint($input['debugMaxLines'])
                    ? min(absint($input['debugMaxLines']), 50000)
                    : $this->options->debugMaxLines;
            }
        } elseif ($tab === 'audit' && is_super_admin()) {
            $auditEnabled = !empty($input['auditEnabled']) ? 1 : 0;
            $output['auditEnabled'] = $auditEnabled;

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

                $output['auditTypes'] = $normalized;
            } else {
                $output['auditTypes'] = isset($this->options->auditTypes) && is_array($this->options->auditTypes)
                    ? $this->options->auditTypes
                    : [
                        'cms' => 1,
                        'site' => 1,
                        'editorial' => 0,
                    ];
            }

            $currentAuditMaxLines = isset($this->options->auditMaxLines) ? (int) $this->options->auditMaxLines : 1000;
            $output['auditMaxLines'] = !empty($input['auditMaxLines']) && absint($input['auditMaxLines'])
                ? min(absint($input['auditMaxLines']), 50000)
                : $currentAuditMaxLines;
            
            $currentSuperMax = isset($this->options->superadminAuditMaxLines)
                ? (int) $this->options->superadminAuditMaxLines
                : 1000;

            $output['superadminAuditMaxLines'] =
                !empty($input['superadminAuditMaxLines']) && absint($input['superadminAuditMaxLines'])
                    ? min(absint($input['superadminAuditMaxLines']), 500000)
                    : $currentSuperMax;

            $currentWebsupportMax = isset($this->options->websupportAuditMaxLines)
                ? (int) $this->options->websupportAuditMaxLines
                : 1000;

            $output['websupportAuditMaxLines'] =
                !empty($input['websupportAuditMaxLines']) && absint($input['websupportAuditMaxLines'])
                    ? min(absint($input['websupportAuditMaxLines']), 500000)
                    : $currentWebsupportMax;
        }

        $this->options = (object) wp_parse_args($output, (array) $this->options);
        return (array) $this->options;
    }

    /**
     * Update network admin options.
     */
    public function settingsUpdate(): void {
        if (!$this->canAccessSettings()) {
            return;
        }


        if (!isset($_POST['rrze-log-settings-submit-primary'])) {
            return;
        }

        check_admin_referer('rrze-log-settings-options');

        $input = isset($_POST[$this->optionName]) && is_array($_POST[$this->optionName]) ? $_POST[$this->optionName] : [];
        $tab = isset($_POST['rrze-log-settings-tab']) ? sanitize_key((string) $_POST['rrze-log-settings-tab']) : 'general';
        $tab = $this->normalizeSettingsTab($tab);

        if (is_multisite()) {
            update_site_option($this->optionName, $this->optionsValidate($input, $tab));
            $this->options = Options::getOptions();
            Cron::reschedule();
            add_action('network_admin_notices', [$this, 'settingsUpdateNotice']);
            return;
        }

        update_option($this->optionName, $this->optionsValidate($input, $tab));
        $this->options = Options::getOptions();
        Cron::reschedule();
        add_action('admin_notices', [$this, 'settingsUpdateNotice']);
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
        if (!$this->canAdminSeeSiteLogs()) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        $this->listTable->prepare_items();

        $action = isset($_GET['action']) ? (string) $_GET['action'] : 'index';

        $s = isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '';
        $level = isset($_REQUEST['level']) ? strtoupper(trim((string) $_REQUEST['level'])) : '';
        $level = in_array($level, Constants::LEVELS, true) ? $level : 'ERROR';
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
        if (!$this->canAdminSeeSiteLogs()) {
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

            $user = get_user_by('login', $userLogin);
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
        return $this->currentUserCanAccessSiteLogs();
    }

    /*
     * Check, ob der aktuelle User Logs sehen darf.
     * Admins/Websupport brauchen keinen logAccess-Eintrag.
     * logAccess erweitert den Zugriff für normale User.
     */
    protected function canAdminSeeSiteLogs(): bool {
        return $this->currentUserCanAccessSiteLogs();
    }

    /**
     * Grants the internal site log view capability to allowed site log users.
     *
     * @param array $allCaps
     * @param array $caps
     * @param array $args
     * @param \WP_User $user
     * @return array
     */
    public function grantSiteLogAccessCap(array $allCaps, array $caps, array $args, \WP_User $user): array {
        if (empty($args[0]) || $args[0] !== self::SITE_LOG_ACCESS_CAP) {
            return $allCaps;
        }

        if ($this->userCanAccessSiteLogs($user)) {
            $allCaps[self::SITE_LOG_ACCESS_CAP] = true;
        }

        return $allCaps;
    }

    /**
     * Checks whether the current user may access site logs and their menus.
     */
    protected function currentUserCanAccessSiteLogs(): bool {
        return $this->userCanAccessSiteLogs(wp_get_current_user());
    }

    /**
     * Checks whether a user may access site logs and their menus.
     */
    protected function userCanAccessSiteLogs(\WP_User $user): bool {
        if (empty($user->ID)) {
            return false;
        }

        if (is_multisite() && is_super_admin((int) $user->ID)) {
            return true;
        }

        $this->options = Options::getOptions();

        if (empty($this->options->adminMenu)) {
            return false;
        }

        if ($this->userCanSeeSiteLogs($user)) {
            return true;
        }

        $list = $this->options->logAccess ?? '';

        if (empty($list)) {
            return false;
        }

        if (!is_array($list)) {
            return false;
        }

        return $this->userIsInLogAccessList($user, $list);
    }

    /**
     * Checks whether the current user has the base site log access role/capability.
     */
    protected function currentUserCanSeeSiteLogs(): bool {
        return $this->userCanSeeSiteLogs(wp_get_current_user());
    }

    /**
     * Checks whether a user is a local admin or Websupport user.
     */
    protected function userCanSeeSiteLogs(\WP_User $user): bool {
        if (empty($user->ID)) {
            return false;
        }

        if ($user->has_cap('manage_options')) {
            return true;
        }

        return Utils::userIsWebsupport($user);
    }

    /**
     * Checks whether a user is explicitly listed in the log access allowlist.
     */
    protected function userIsInLogAccessList(\WP_User $user, array $list): bool {
        $login = (string) $user->user_login;

        foreach ($list as $row) {
            $ary = explode(' - ', (string) $row);
            $allowedLogin = isset($ary[0]) ? trim((string) $ary[0]) : '';
            if ($allowedLogin !== '' && $allowedLogin === $login) {
                return true;
            }
        }

        return false;
    }
    
    
    /**
     * Add screen options for superadmin audit log.
     */
    public function superadminAuditScreenOptions(): void {
        add_screen_option('per_page', [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page',
        ]);

        $this->superadminAuditListTable = new SuperadminAuditListTable();
    }

    /**
     * Add screen options for websupport audit log.
     */
    public function websupportAuditScreenOptions(): void {
        add_screen_option('per_page', [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page',
        ]);

        $this->websupportAuditListTable = new WebsupportAuditListTable();
    }
    
 
    /**
     * Display superadmin audit log list table page (network only).
     */
    public function superadminAuditLogPage(): void {
        $this->options = Options::getOptions();

        if (!is_multisite() || !is_super_admin() || empty($this->options->auditEnabled)) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        if (!$this->superadminAuditListTable instanceof SuperadminAuditListTable) {
            $this->superadminAuditListTable = new SuperadminAuditListTable();
        }

        $this->superadminAuditListTable->prepare_items();

        $data = [
            'action' => 'superadmin-audit',
            's' => isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '',
            'logfile' => date('Y-m-d'),
            'listTable' => $this->superadminAuditListTable,
            'title' => __('Superadmin Audit', 'rrze-log'),
        ];

        $this->show('list-table', $data);
    }

    /**
     * Display websupport audit log list table page (network only).
     */
    public function websupportAuditLogPage(): void {
        $this->options = Options::getOptions();

        if (!is_multisite() || !is_super_admin() || empty($this->options->auditEnabled)) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        wp_enqueue_style('rrze-log-list-table');
        wp_enqueue_script('rrze-log-list-table');

        if (!$this->websupportAuditListTable instanceof WebsupportAuditListTable) {
            $this->websupportAuditListTable = new WebsupportAuditListTable();
        }

        $this->websupportAuditListTable->prepare_items();

        $data = [
            'action' => 'websupport-audit',
            's' => isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '',
            'logfile' => date('Y-m-d'),
            'listTable' => $this->websupportAuditListTable,
            'title' => __('Websupport Audit', 'rrze-log'),
        ];

        $this->show('list-table', $data);
    }
    
    /**
    * Display superadminAuditMaxLines field (superadmin audit log file).
    */
   public function superadminAuditMaxLinesField(): void {
       $value = isset($this->options->superadminAuditMaxLines)
           ? (int) $this->options->superadminAuditMaxLines
           : 1000;
       ?>
       <label for="rrze-log-superadminAuditMaxLines">
           <input
               type="number"
               min="1000"
               max="500000"
               step="1"
               id="rrze-log-superadminAuditMaxLines"
               name="<?php printf('%s[superadminAuditMaxLines]', $this->optionName); ?>"
               value="<?php echo esc_attr((string) $value); ?>"
               class="small-text"
           >
       </label>
       <p class="description">
           <?php _e('Keep only the newest lines in the superadmin audit log file. Applies to multisite superadmin actions only.', 'rrze-log'); ?>
       </p>
       <?php
   }

   /*
    * Display websupportAuditMaxLines field (websupport audit log file).
    */
    public function websupportAuditMaxLinesField(): void {
        $value = isset($this->options->websupportAuditMaxLines)
            ? (int) $this->options->websupportAuditMaxLines
            : 1000;
        ?>
        <label for="rrze-log-websupportAuditMaxLines">
            <input
                type="number"
                min="1000"
                max="500000"
                step="1"
                id="rrze-log-websupportAuditMaxLines"
                name="<?php printf('%s[websupportAuditMaxLines]', $this->optionName); ?>"
                value="<?php echo esc_attr((string) $value); ?>"
                class="small-text"
            >
        </label>
        <p class="description">
            <?php _e('Keep only the newest lines in the websupport audit log file. Applies to multisite websupport role actions only.', 'rrze-log'); ?>
        </p>
        <?php
    }

   /*
    * Check wr die Settings sehen kann
    */
    protected function canAccessSettings(): bool {
        if (is_multisite()) {
            return is_super_admin() && is_network_admin() && current_user_can('manage_network_options');
        }

        return current_user_can('manage_options');
    }
}
