<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * AdminAudit
 *
 * Protokolliert sicherheits- und administrationsrelevante Handlungen
 * von Administratoren und Superadministratoren in einer separaten Audit-Logdatei.
 */
class AdminAudit {

    /**
     * Logger instance used for audit logging.
     */
    private Logger $logger;

    /**
     * Whitelist of audit actions that are logged.
     * Can be filtered via rrze_log/audit_actions.
     */
    private const ACTIONS = [
        'posts.create' => true,
        'posts.update' => true,
        'posts.delete' => true,

        'users.create' => true,
        'users.update' => true,

        'media.create' => true,
        'media.update' => true,
        'media.delete' => true,

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
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Registers all WordPress hooks required for audit logging.
     */
    public function register(): void {
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
    }

    /**
     * Handles creation and updates of posts, pages and site editor entities.
     */
    public function onSavePost(int $postId, \WP_Post $post, bool $update): void {
        if (!$this->isAllowedActor()) {
            return;
        }

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
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
        if (!$this->isAllowedActor()) {
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        if ($post->post_type !== 'post' && $post->post_type !== 'page') {
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
        if (!$this->isAllowedActor()) {
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
        if (!$this->isAllowedActor()) {
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
        if (!$this->isAllowedActor()) {
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
        if (!$this->isAllowedActor()) {
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
        if (!$this->isAllowedActor()) {
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
        if (!$this->isAllowedActor()) {
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
        if (!$this->isAllowedActor()) {
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
        if (!$this->isAllowedActor()) {
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
     * Checks whether the current user is allowed to trigger audit logging.
     */
    private function isAllowedActor(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        if (is_multisite() && is_super_admin()) {
            return true;
        }

        return current_user_can('manage_options');
    }

    /**
     * Builds a structured context describing the acting user.
     */
    private function buildActorContext(): array {
        $user = wp_get_current_user();

        return [
            'id' => (int) $user->ID,
            'login' => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
            'roles' => array_values((array) $user->roles),
            'is_super_admin' => is_multisite() ? (bool) is_super_admin() : false,
            'ip' => $this->getRemoteIp(),
            'user_agent' => $this->getUserAgent(),
        ];
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
}
