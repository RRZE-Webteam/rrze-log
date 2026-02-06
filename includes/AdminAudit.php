<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * AdminAudit
 *
 * Protokolliert sicherheits- und administrationsrelevante Handlungen
 * in einer separaten Audit-Logdatei.
 */
class AdminAudit {

    /**
     * User meta key for last login timestamp.
     */
    private const META_LAST_LOGIN = 'rrze_log_last_login';

    /**
     * Roles for which last login should be included in the actor context.
     */
    private const LAST_LOGIN_ROLES = [
        'superadmin' => true,
        'administrator' => true,
        'editor' => true,
    ];

    /**
     * Logger instance used for audit logging.
     */
    private Logger $logger;

    /**
     * Whitelist of audit actions that are logged.
     * Can be filtered via rrze_log/audit_actions.
     */
    private const ACTIONS = [
        'auth.login' => true,
        
        'posts.create' => true,
        'posts.update' => true,
        'posts.delete' => true,

        'users.create' => true,
        'users.update' => true,

        'media.create' => true,
        'media.update' => true,
        'media.delete' => true,

        'plugins.activate' => true,
        'plugins.deactivate' => true,
        'plugins.delete' => true,

        'themes.install' => true,
        'themes.activate' => true,
        'themes.deactivate' => true,
        'themes.delete' => true,

        'theme.customizer_save' => true,
        'theme.site_editor_change' => true,

        'settings.updated_option' => true,
        'settings.updated_site_option' => true,
    ];

    /**
     * Allowlist of option names that may be logged.
     */
    private const SETTINGS_ALLOWLIST = [
        'blogname',
        'blogdescription',
        'admin_email',
        'home',
        'siteurl',
        'permalink_structure',
        'users_can_register',
        'default_role',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
    ];

    /**
     * Post types used by the Site Editor.
     */
    private const SITE_EDITOR_POST_TYPES = [
        'wp_global_styles',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
    ];

    /**
     * Constructor.
     *
     * @param Logger $logger Logger instance used for writing audit entries.
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Registers all WordPress hooks required for audit logging.
     */
    public function register(): void {
        add_action('wp_login', [$this, 'onWpLogin'], 10, 2);

        add_action('save_post', [$this, 'onSavePost'], 10, 3);
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 1);

        add_action('user_register', [$this, 'onUserRegister'], 10, 1);
        add_action('profile_update', [$this, 'onProfileUpdate'], 10, 2);

        add_action('add_attachment', [$this, 'onAddAttachment'], 10, 1);
        add_action('edit_attachment', [$this, 'onEditAttachment'], 10, 1);
        add_action('delete_attachment', [$this, 'onDeleteAttachment'], 10, 1);

        add_action('customize_save_after', [$this, 'onCustomizeSaveAfter'], 10, 1);

        add_action('updated_option', [$this, 'onUpdatedOption'], 10, 3);
        add_action('updated_site_option', [$this, 'onUpdatedSiteOption'], 10, 3);

        add_action('activated_plugin', [$this, 'onActivatedPlugin'], 10, 2);
        add_action('deactivated_plugin', [$this, 'onDeactivatedPlugin'], 10, 2);

        add_action('delete_plugin', [$this, 'onDeletePlugin'], 10, 1);
        add_action('deleted_plugin', [$this, 'onDeletedPlugin'], 10, 2);

        add_action('switch_theme', [$this, 'onSwitchTheme'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'onUpgraderProcessComplete'], 10, 2);

        add_action('delete_theme', [$this, 'onDeleteTheme'], 10, 1);
        add_action('deleted_theme', [$this, 'onDeletedTheme'], 10, 2);
    }

    /**
    * Logs login events for superadmin/administrator/editor.
    */
   public function onWpLogin(string $userLogin, \WP_User $user): void {
       if (!$user || empty($user->ID)) {
           return;
       }

       $role = $this->getPrimaryRoleForUser($user);

       if (!$this->shouldLogLoginForRole($role)) {
           return;
       }

       $this->logIfEnabled('auth.login', 'User logged in', [
           'object' => [
               'type' => 'auth',
               'event' => 'login',
           ],
           'login' => (string) $userLogin,
           'user_id' => (int) $user->ID,
       ]);
   }


    /**
     * Handles creation and updates of posts, pages and site editor entities.
     */
    public function onSavePost(int $postId, \WP_Post $post, bool $update): void {
        if (!$this->isUserLoggedIn()) {
            return;
        }

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if (!$this->canEditPost($postId, $post)) {
            return;
        }

        if ($post->post_type === 'post' || $post->post_type === 'page') {
            if ($update) {
                $this->logIfEnabled('posts.update', 'Post/Page updated', [
                    'object' => $this->buildPostObject($post),
                ]);
            } else {
                $this->logIfEnabled('posts.create', 'Post/Page created', [
                    'object' => $this->buildPostObject($post),
                ]);
            }
            return;
        }

        if (in_array($post->post_type, self::SITE_EDITOR_POST_TYPES, true)) {
            $this->logIfEnabled('theme.site_editor_change', 'Site Editor content changed', [
                'object' => $this->buildPostObject($post),
            ]);
        }
    }

    /**
     * Handles deletion of posts and pages.
     */
    public function onBeforeDeletePost(int $postId): void {
        if (!$this->isUserLoggedIn()) {
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        if ($post->post_type !== 'post' && $post->post_type !== 'page') {
            return;
        }

        if (!$this->canDeletePost($postId)) {
            return;
        }

        $this->logIfEnabled('posts.delete', 'Post/Page deleted', [
            'object' => $this->buildPostObject($post),
        ]);
    }

    /**
     * Handles creation of new users.
     */
    public function onUserRegister(int $userId): void {
        if (!$this->isAllowedActorForUsers()) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user) {
            return;
        }

        $this->logIfEnabled('users.create', 'User created', [
            'object' => $this->buildUserObject($user),
        ]);
    }

    /**
     * Handles updates to existing user accounts.
     */
    public function onProfileUpdate(int $userId, \WP_User $oldUserData): void {
        if (!$this->isAllowedActorForUsers()) {
            return;
        }

        $newUser = get_userdata($userId);
        if (!$newUser) {
            return;
        }

        $changes = [];

        if ($oldUserData->user_email !== $newUser->user_email) {
            $changes['user_email'] = ['old' => $oldUserData->user_email, 'new' => $newUser->user_email];
        }

        if ($oldUserData->display_name !== $newUser->display_name) {
            $changes['display_name'] = ['old' => $oldUserData->display_name, 'new' => $newUser->display_name];
        }

        if ((array) $oldUserData->roles !== (array) $newUser->roles) {
            $changes['roles'] = [
                'old' => array_values((array) $oldUserData->roles),
                'new' => array_values((array) $newUser->roles),
            ];
        }

        $this->logIfEnabled('users.update', 'User updated', [
            'object' => $this->buildUserObject($newUser),
            'changes' => $changes,
        ]);
    }

    /**
     * Handles upload of new media files.
     */
    public function onAddAttachment(int $postId): void {
        if (!$this->isAllowedActorForMedia()) {
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $this->logIfEnabled('media.create', 'Media uploaded', [
            'object' => $this->buildMediaObject($post),
        ]);
    }

    /**
     * Handles updates to media files.
     */
    public function onEditAttachment(int $postId): void {
        if (!$this->isAllowedActorForMedia()) {
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $this->logIfEnabled('media.update', 'Media updated', [
            'object' => $this->buildMediaObject($post),
        ]);
    }

    /**
     * Handles deletion of media files.
     */
    public function onDeleteAttachment(int $postId): void {
        if (!$this->isAllowedActorForMedia()) {
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        $this->logIfEnabled('media.delete', 'Media deleted', [
            'object' => $this->buildMediaObject($post),
        ]);
    }

    /**
     * Handles saving of Customizer settings.
     */
    public function onCustomizeSaveAfter($wpCustomize): void {
        if (!$this->isAllowedActorForThemes()) {
            return;
        }

        $settingIds = [];

        if (is_object($wpCustomize) && method_exists($wpCustomize, 'unsanitized_post_values')) {
            $values = (array) $wpCustomize->unsanitized_post_values();
            $settingIds = array_keys($values);
        }

        $this->logIfEnabled('theme.customizer_save', 'Customizer settings saved', [
            'customizer_setting_ids' => $settingIds,
        ]);
    }

    /**
     * Handles updates to regular WordPress options.
     */
    public function onUpdatedOption(string $option, $oldValue, $newValue): void {
        if (!$this->isAllowedActorForSettings()) {
            return;
        }

        if (!$this->isAllowedSetting($option)) {
            return;
        }

        $this->logIfEnabled('settings.updated_option', 'Option updated', [
            'object' => [
                'type' => 'option',
                'name' => $option,
            ],
            'changes' => $this->buildOptionChange($option, $oldValue, $newValue),
        ]);
    }

    /**
     * Handles updates to multisite options.
     */
    public function onUpdatedSiteOption(string $option, $oldValue, $newValue): void {
        if (!$this->isAllowedActorForSettings()) {
            return;
        }

        if (!$this->isAllowedSetting($option)) {
            return;
        }

        $this->logIfEnabled('settings.updated_site_option', 'Site option updated', [
            'object' => [
                'type' => 'site_option',
                'name' => $option,
            ],
            'changes' => $this->buildOptionChange($option, $oldValue, $newValue),
        ]);
    }

    /**
     * Handles activation of plugins.
     */
    public function onActivatedPlugin(string $plugin, bool $networkWide): void {
        if (!$this->isAllowedActorForPlugins()) {
            return;
        }

        $this->logIfEnabled('plugins.activate', 'Plugin activated', [
            'object' => $this->buildPluginObject($plugin, $networkWide),
        ]);
    }

    /**
     * Handles deactivation of plugins.
     */
    public function onDeactivatedPlugin(string $plugin, bool $networkWide): void {
        if (!$this->isAllowedActorForPlugins()) {
            return;
        }

        $this->logIfEnabled('plugins.deactivate', 'Plugin deactivated', [
            'object' => $this->buildPluginObject($plugin, $networkWide),
        ]);
    }

    /**
     * Handles plugin deletion intent (pre-delete).
     */
    public function onDeletePlugin(string $plugin): void {
        if (!$this->isAllowedActorForPlugins()) {
            return;
        }

        $this->logIfEnabled('plugins.delete', 'Plugin deletion requested', [
            'object' => $this->buildPluginObject($plugin, null),
            'phase' => 'pre',
        ]);
    }

    /**
     * Handles plugin deletion result (post-delete).
     */
    public function onDeletedPlugin(string $plugin, bool $deleted): void {
        if (!$this->isAllowedActorForPlugins()) {
            return;
        }

        $this->logIfEnabled('plugins.delete', 'Plugin deleted', [
            'object' => $this->buildPluginObject($plugin, null),
            'phase' => 'post',
            'deleted' => $deleted ? 1 : 0,
        ]);
    }

    /**
     * Handles theme switching: logs activation of new theme and deactivation of old theme.
     */
    public function onSwitchTheme(string $newName, \WP_Theme $newTheme, \WP_Theme $oldTheme): void {
        if (!$this->isAllowedActorForThemes()) {
            return;
        }

        $this->logIfEnabled('themes.activate', 'Theme activated', [
            'object' => $this->buildThemeObject($newTheme),
        ]);

        $this->logIfEnabled('themes.deactivate', 'Theme deactivated', [
            'object' => $this->buildThemeObject($oldTheme),
        ]);
    }

    /**
     * Handles theme installation (and updates) via upgrader.
     * We only log installations here; updates can be added later if desired.
     */
    public function onUpgraderProcessComplete($upgrader, array $hookExtra): void {
        if (!$this->isAllowedActorForThemes()) {
            return;
        }

        $type = isset($hookExtra['type']) ? (string) $hookExtra['type'] : '';
        $action = isset($hookExtra['action']) ? (string) $hookExtra['action'] : '';

        if ($type !== 'theme' || $action !== 'install') {
            return;
        }

        $slug = '';

        if (isset($hookExtra['theme'])) {
            $slug = (string) $hookExtra['theme'];
        } elseif (isset($hookExtra['themes']) && is_array($hookExtra['themes']) && !empty($hookExtra['themes'][0])) {
            $slug = (string) $hookExtra['themes'][0];
        }

        if ($slug === '') {
            return;
        }

        $theme = wp_get_theme($slug);

        $this->logIfEnabled('themes.install', 'Theme installed', [
            'object' => $this->buildThemeObject($theme),
        ]);
    }

    /**
     * Handles theme deletion intent (pre-delete).
     */
    public function onDeleteTheme(string $stylesheet): void {
        if (!$this->isAllowedActorForThemes()) {
            return;
        }

        $theme = wp_get_theme($stylesheet);

        $this->logIfEnabled('themes.delete', 'Theme deletion requested', [
            'object' => $this->buildThemeObject($theme),
            'phase' => 'pre',
        ]);
    }

    /**
     * Handles theme deletion result (post-delete).
     */
    public function onDeletedTheme(string $stylesheet, bool $deleted): void {
        if (!$this->isAllowedActorForThemes()) {
            return;
        }

        $theme = wp_get_theme($stylesheet);

        $this->logIfEnabled('themes.delete', 'Theme deleted', [
            'object' => $this->buildThemeObject($theme),
            'phase' => 'post',
            'deleted' => $deleted ? 1 : 0,
        ]);
    }

    /**
     * Writes an audit log entry if the given action is enabled.
     */
    private function logIfEnabled(string $action, string $message, array $context): void {
        $actions = apply_filters('rrze_log/audit_actions', self::ACTIONS);

        if (!isset($actions[$action]) || !$actions[$action]) {
            return;
        }

        $context['action'] = $action;
        $context['actor'] = $this->buildActorContext();

        $this->logger->audit($message, $context);
    }

    /**
     * Checks whether the current request has a logged-in user.
     */
    private function isUserLoggedIn(): bool {
        return is_user_logged_in();
    }

    /**
     * Checks whether current user can edit the given post.
     */
    private function canEditPost(int $postId, \WP_Post $post): bool {
        if (current_user_can('edit_post', $postId)) {
            return true;
        }

        if ($post->post_type === 'post' && current_user_can('edit_posts')) {
            return true;
        }

        if ($post->post_type === 'page' && current_user_can('edit_pages')) {
            return true;
        }

        return false;
    }

    /**
     * Checks whether current user can delete the given post.
     */
    private function canDeletePost(int $postId): bool {
        return current_user_can('delete_post', $postId);
    }

    /**
     * Checks whether the current user is allowed to log user changes.
     */
    private function isAllowedActorForUsers(): bool {
        if (!$this->isUserLoggedIn()) {
            return false;
        }

        if (is_multisite() && is_super_admin()) {
            return true;
        }

        return current_user_can('create_users') || current_user_can('edit_users');
    }

    /**
     * Checks whether the current user is allowed to log media changes.
     */
    private function isAllowedActorForMedia(): bool {
        if (!$this->isUserLoggedIn()) {
            return false;
        }

        if (is_multisite() && is_super_admin()) {
            return true;
        }

        return current_user_can('upload_files');
    }

    /**
     * Checks whether the current user is allowed to log plugin changes.
     */
    private function isAllowedActorForPlugins(): bool {
        if (!$this->isUserLoggedIn()) {
            return false;
        }

        if (is_multisite() && is_super_admin()) {
            return true;
        }

        return current_user_can('activate_plugins') || current_user_can('delete_plugins') || current_user_can('install_plugins');
    }

    /**
     * Checks whether the current user is allowed to log theme changes.
     */
    private function isAllowedActorForThemes(): bool {
        if (!$this->isUserLoggedIn()) {
            return false;
        }

        if (is_multisite() && is_super_admin()) {
            return true;
        }

        return current_user_can('switch_themes') || current_user_can('delete_themes') || current_user_can('install_themes');
    }

    /**
     * Checks whether the current user is allowed to log settings changes.
     */
    private function isAllowedActorForSettings(): bool {
        if (!$this->isUserLoggedIn()) {
            return false;
        }

        if (is_multisite() && is_super_admin()) {
            return true;
        }

        return current_user_can('manage_options');
    }

    /**
     * Builds a structured context describing the acting user.
     * Adds last_login for superadmin/administrator/editor.
     */
    private function buildActorContext(): array {
        $user = wp_get_current_user();
        $roles = array_values((array) $user->roles);

        $role = '';
        if (is_multisite() && is_super_admin()) {
            $role = 'superadmin';
        } elseif (!empty($roles[0])) {
            $role = (string) $roles[0];
        }

        return [
            'id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
            'role' => $role,
            'roles' => $roles,
            'ip' => $this->getRemoteIp(),
            'user_agent' => $this->getUserAgent(),
        ];
    }


    /**
     * Returns true if last login should be included for a given role.
     */
    private function shouldIncludeLastLogin(string $role): bool {
        $role = strtolower($role);
        return isset(self::LAST_LOGIN_ROLES[$role]) && self::LAST_LOGIN_ROLES[$role];
    }

    /**
     * Reads last login timestamp from user meta.
     */
    private function getLastLoginTimestamp(int $userId): int {
        $val = get_user_meta($userId, self::META_LAST_LOGIN, true);

        if (is_numeric($val)) {
            $ts = (int) $val;
            return $ts > 0 ? $ts : 0;
        }

        return 0;
    }

    /**
     * Builds a structured representation of a post or page.
     */
    private function buildPostObject(\WP_Post $post): array {
        return [
            'type' => 'post',
            'id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'title' => (string) get_the_title($post),
            'status' => (string) $post->post_status,
        ];
    }

    /**
     * Builds a structured representation of a user.
     */
    private function buildUserObject(\WP_User $user): array {
        return [
            'type' => 'user',
            'id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'email' => (string) $user->user_email,
            'display_name' => (string) $user->display_name,
            'roles' => array_values((array) $user->roles),
        ];
    }

    /**
     * Builds a structured representation of a media attachment.
     */
    private function buildMediaObject(\WP_Post $post): array {
        $file = get_attached_file($post->ID);

        return [
            'type' => 'attachment',
            'id' => (int) $post->ID,
            'title' => (string) get_the_title($post),
            'mime' => (string) get_post_mime_type($post),
            'file' => is_string($file) ? $file : '',
        ];
    }

    /**
     * Builds a structured representation of a plugin.
     *
     * @param string    $plugin       Plugin main file, e.g. hello-dolly/hello.php
     * @param bool|null $networkWide  true/false when provided, null if unknown
     */
    private function buildPluginObject(string $plugin, $networkWide): array {
        $data = $this->getPluginDataSafe($plugin);

        $obj = [
            'type' => 'plugin',
            'plugin' => $plugin,
            'title' => $data['name'],
            'version' => $data['version'],
        ];

        if (!is_null($networkWide)) {
            $obj['network_wide'] = $networkWide ? 1 : 0;
        }

        return $obj;
    }

    /**
     * Builds a structured representation of a theme.
     */
    private function buildThemeObject(\WP_Theme $theme): array {
        $stylesheet = (string) $theme->get_stylesheet();

        return [
            'type' => 'theme',
            'stylesheet' => $stylesheet,
            'name' => (string) $theme->get('Name'),
            'version' => (string) $theme->get('Version'),
            'template' => (string) $theme->get_template(),
        ];
    }

    /**
     * Checks whether an option name is allowed to be logged.
     */
    private function isAllowedSetting(string $option): bool {
        $allowlist = apply_filters('rrze_log/audit_settings_allowlist', self::SETTINGS_ALLOWLIST);
        return in_array($option, (array) $allowlist, true);
    }

    /**
     * Builds a change set for option updates, with redaction if necessary.
     */
    private function buildOptionChange(string $option, $oldValue, $newValue): array {
        if ($this->isSensitiveOptionName($option)) {
            return [
                'old' => '(redacted)',
                'new' => '(redacted)',
            ];
        }

        return [
            'old' => $this->stringifyOptionValue($oldValue),
            'new' => $this->stringifyOptionValue($newValue),
        ];
    }

    /**
     * Determines whether an option name is considered sensitive.
     */
    private function isSensitiveOptionName(string $option): bool {
        $option = strtolower($option);

        if (strpos($option, 'password') !== false) {
            return true;
        }
        if (strpos($option, 'secret') !== false) {
            return true;
        }
        if (strpos($option, 'token') !== false) {
            return true;
        }
        if (strpos($option, 'key') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Converts an option value to a safe string representation.
     */
    private function stringifyOptionValue($value): string {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = wp_json_encode($value);
        if (!is_string($json)) {
            return '';
        }

        if (strlen($json) > 2000) {
            return substr($json, 0, 2000) . '...';
        }

        return $json;
    }

    /**
     * Reads plugin header data defensively without fatal errors.
     *
     * @param string $plugin
     * @return array{name:string,version:string}
     */
    private function getPluginDataSafe(string $plugin): array {
        $name = '';
        $version = '';

        if (!function_exists('get_plugin_data')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }

        $pluginFile = WP_PLUGIN_DIR . '/' . ltrim($plugin, '/');

        if (function_exists('get_plugin_data') && is_readable($pluginFile)) {
            $data = get_plugin_data($pluginFile, false, false);

            if (is_array($data)) {
                if (!empty($data['Name'])) {
                    $name = (string) $data['Name'];
                }
                if (!empty($data['Version'])) {
                    $version = (string) $data['Version'];
                }
            }
        }

        return [
            'name' => $name,
            'version' => $version,
        ];
    }

    /**
     * Returns the remote IP address of the current request.
     */
    private function getRemoteIp(): string {
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return '';
        }

        $ip = (string) $_SERVER['REMOTE_ADDR'];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return $ip;
    }

    /**
     * Returns the user agent of the current request.
     */
    private function getUserAgent(): string {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return '';
        }

        $ua = (string) $_SERVER['HTTP_USER_AGENT'];
        if (strlen($ua) > 512) {
            $ua = substr($ua, 0, 512);
        }

        return $ua;
    }
    
    /**
    * Returns the primary role for a user; superadmin is handled separately.
    */
   private function getPrimaryRoleForUser(\WP_User $user): string {
       if (is_multisite() && is_super_admin($user->ID)) {
           return 'superadmin';
       }

       $roles = array_values((array) $user->roles);
       return !empty($roles[0]) ? (string) $roles[0] : '';
   }

   /**
    * Returns true if login events should be logged for this role.
    */
   private function shouldLogLoginForRole(string $role): bool {
       $role = strtolower($role);

       if ($role === 'superadmin') {
           return true;
       }
       if ($role === 'administrator') {
           return true;
       }
       if ($role === 'editor') {
           return true;
       }

       return false;
   }

}
