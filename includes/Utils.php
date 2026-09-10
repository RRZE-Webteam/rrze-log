<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * Utility functions
 */
class Utils
{
    /**
     * Role slugs treated as websupport actors.
     */
    public const WEBSUPPORT_ROLES = [
        'websupport',
        'web_support',
        'rrze-websupport',
        'rrze_websupport',
        'rrze-web-support',
        'rrze_web_support',
    ];

    /**
     * Capability slugs treated as websupport actor markers.
     */
    public const WEBSUPPORT_CAPABILITIES = [
        'websupport',
        'web_support',
        'rrze-websupport',
        'rrze_websupport',
        'rrze-web-support',
        'rrze_web_support',
    ];

    /**
     * Check if a string is valid JSON.
     *
     * @param string $string
     * @return boolean
     */
    public static function isDebugLog() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }

        if (!defined('WP_DEBUG_LOG')) {
            return new \WP_Error(
                'wp_debug_log_missing',
                __('WP_DEBUG_LOG ist nicht definiert.', 'rrze-log')
            );
        }

        $value = WP_DEBUG_LOG;

        if (!is_string($value) || $value != Constants::DEBUG_LOG_FILE) {
            return new \WP_Error(
                'wp_debug_log',
                sprintf(
                    /* translators: %s: Current WP_DEBUG_LOG value. */
                    __('Ungültiger Wert für WP_DEBUG_LOG. Aktueller Wert: %s — Erwartet: %s', 'rrze-log'),
                    var_export($value, true),
                    Constants::DEBUG_LOG_FILE
                )
            );
        }

        return $value;
    }

    /**
     * Get log items.
     * @param  array $args
     * @return array
     */
    public static function getLogs($args = [])
    {
        $logFile = $args['logfile'] ?? '';
        $search = $args['search'] ?? [];
        $limit = $args['limit'] ?? -1;
        $offset = $args['offset'] ?? 0;

        return self::getLog((string) $logFile, $search, $offset, $limit)['items'] ?? [];
    }

    /**
     * Get the log items.
     * @param  string $logFile
     * @param  array $search
     * @param  integer $offset
     * @param  integer $count
     * @return array
     */
    public static function getLog($logFile = '', $search = [], $offset = 0, $count = -1)
    {
        $logFiles = self::getLogFilesForRequest((string) $logFile);

        $search = is_array($search) && self::isNotMultidimensional($search) ?
            array_map('trim', $search) :
            [];
        $search = array_filter($search);
        $offset = absint($offset);
        $count = $count < 0 ? -1 : absint($count);

        $limit = $count < 0 ? -1 : ($offset + $count);
        $items = [];
        $totalItems = 0;

        foreach ($logFiles as $logFile) {
            $logParser = new LogParser($logFile, $search, 0, $limit, false);

            if (!is_network_admin()) {
                $logItems = $logParser->getItems('siteurl', untrailingslashit(site_url()));
            } else {
                $logItems = $logParser->getItems();
            }

            if (!is_wp_error($logItems)) {
                foreach ($logItems as $item) {
                    $decoded = json_decode((string) $item, true);
                    if (is_array($decoded)) {
                        $items[] = $decoded;
                    }
                }
            }

            $totalItems += $logParser->getTotalLines();
        }

        usort($items, [self::class, 'compareLogItemsByDatetimeDesc']);

        if ($offset > 0 || $count >= 0) {
            $items = array_slice($items, $offset, $count >= 0 ? $count : null);
        }

        return [
            'items' => $items,
            'total_items' => $totalItems
        ];
    }

    /**
     * Normalize publicly supplied log file paths to known log files.
     */
    protected static function normalizeLogFile(string $logFile): string {
        $allowed = [
            Constants::AUDIT_LOG_FILE,
            Constants::SUPERADMIN_AUDIT_LOG_FILE,
            Constants::WEBSUPPORT_AUDIT_LOG_FILE,
        ];

        if ($logFile === '') {
            return Constants::LOG_FILE;
        }

        foreach ($allowed as $allowedFile) {
            if ($logFile === $allowedFile || basename($logFile) === basename($allowedFile)) {
                return $allowedFile;
            }
        }

        return Constants::LOG_FILE;
    }

    /**
     * Normalize publicly supplied log file paths to known log files.
     */
    protected static function getLogFilesForRequest(string $logFile): array {
        $actionLogFiles = Constants::getActionLogFiles();

        if ($logFile === '') {
            return array_values($actionLogFiles);
        }

        foreach ($actionLogFiles as $allowedFile) {
            if ($logFile === $allowedFile || basename($logFile) === basename($allowedFile)) {
                return [$allowedFile];
            }
        }

        return [self::normalizeLogFile($logFile)];
    }

    /**
     * Sort log items newest first.
     */
    public static function compareLogItemsByDatetimeDesc(array $a, array $b): int {
        $aTime = strtotime((string) ($a['datetime'] ?? ''));
        $bTime = strtotime((string) ($b['datetime'] ?? ''));
        $aTime = $aTime === false ? 0 : $aTime;
        $bTime = $bTime === false ? 0 : $bTime;

        if ($aTime === $bTime) {
            return 0;
        }

        return $aTime < $bTime ? 1 : -1;
    }

    /**
     * Check if the array is multidimensional.
     * @param  array $array
     * @return boolean
     */
    public static function isNotMultidimensional($array)  {
        foreach ($array as $value) {
            if (is_array($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks whether a role set contains a configured websupport role.
     */
    public static function hasWebsupportRole(array $roles, string $role = ''): bool {
        if ($role !== '') {
            $roles[] = $role;
        }

        $roles = array_map('strtolower', array_map('strval', $roles));
        $websupportRoles = apply_filters('rrze_log/websupport_roles', self::WEBSUPPORT_ROLES);
        $websupportRoles = array_map('strtolower', array_map('strval', (array) $websupportRoles));

        foreach ($roles as $candidate) {
            if (in_array($candidate, $websupportRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a user has a configured websupport capability marker.
     */
    public static function userHasWebsupportCapability(\WP_User $user): bool {
        $websupportCaps = apply_filters('rrze_log/websupport_capabilities', self::WEBSUPPORT_CAPABILITIES);

        foreach ((array) $websupportCaps as $cap) {
            $cap = (string) $cap;
            if ($cap === '') {
                continue;
            }

            if ($user->has_cap($cap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether the user is a configured websupport user.
     */
    public static function userIsWebsupport(\WP_User $user): bool {
        return self::rrzeSettingsUserIsWebsupport($user)
            || self::hasWebsupportRole(array_values((array) $user->roles))
            || self::userHasWebsupportCapability($user);
    }

    /**
     * Checks RRZE-Settings directly when available.
     */
    protected static function rrzeSettingsUserIsWebsupport(\WP_User $user): bool {
        if (empty($user->ID)) {
            return false;
        }

        $helper = 'RRZE\Settings\Helper';
        if (!class_exists($helper) || !method_exists($helper, 'isWebsupportUser')) {
            return false;
        }

        try {
            return (bool) $helper::isWebsupportUser((int) $user->ID);
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /*
     * Display form for log times
     */
    public static function formatDatetimeWithUtcTooltip(string $raw, string $localFormat = 'Y/m/d G:i:s', string $utcFormat = 'Y/m/d G:i:s \U\T\C'): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '—';
        }

        $ts = strtotime($raw);
        if (!$ts) {
            return '—';
        }

        $utc = gmdate($utcFormat, $ts);

        $dt = new \DateTimeImmutable('@' . $ts);
        $dt = $dt->setTimezone(wp_timezone());
        $local = $dt->format($localFormat);

        return sprintf(
            '<span title="%s">%s</span>',
            esc_attr($utc),
            esc_html($local)
        );
    }

    /*
     * Schwere-Level Für Fehlermeldungen sortierbar machen und global festlegen
     */
    public static function levelWeight(string $level): int {
        $map = [
            'FATAL' => 0,
            'PARSE' => 1,
            'EXCEPTION' => 2,
            'DATABASE' => 3,
            'WARNING' => 4,
            'NOTICE' => 5,
            'DEPRECATED' => 6,
            'JAVASCRIPT' => 7,
            'OTHER' => 99,
        ];

        $level = strtoupper($level);

        return $map[$level] ?? 999;
    }
    /*
     * Erstelle Darstellung für tiefe Arrays
     */
    public static function renderContextTree($context): string {
        if ($context === null || $context === '' || $context === []) {
            return '';
        }

        if (is_object($context)) {
            $context = self::objectToArrayForLog($context);
        }

        if (is_array($context)) {
            return self::renderTreeNode($context, 'context', 0);
        }

        return '<pre>' . esc_html((string) $context) . '</pre>';
    }

    protected static function renderTreeNode(array $data, string $label, int $depth): string {
        $count = count($data);

        // Ebene 3+ (0-based: depth>=2) initial zu
        $openAttr = ($depth < 2) ? ' open' : '';

        $html  = '<details class="rrze-log-tree"' . $openAttr . '>';
        $html .= '<summary>';
        $html .= '<span class="rrze-log-tree-key">' . esc_html($label) . '</span>';
        $html .= ' <span class="rrze-log-tree-meta">array(' . (int) $count . ')</span>';
        $html .= '</summary>';
        $html .= '<div class="rrze-log-tree-body">';

        foreach ($data as $k => $v) {
            $key = is_int($k) ? (string) $k : (string) $k;

            if (is_object($v)) {
                $v = self::objectToArrayForLog($v);
            }

            if (is_array($v)) {
                $html .= self::renderTreeNode($v, $key, $depth + 1);
                continue;
            }

            $html .= '<div class="rrze-log-tree-leaf">';
            $html .= '<span class="rrze-log-tree-leaf-key">' . esc_html($key) . '</span>: ';
            $html .= '<code class="rrze-log-tree-leaf-val">' . esc_html(self::scalarToString($v)) . '</code>';
            $html .= '</div>';
        }

        $html .= '</div></details>';

        return $html;
    }

    protected static function objectToArrayForLog(object $obj): array {
        if ($obj instanceof \JsonSerializable) {
            $data = $obj->jsonSerialize();
            return is_array($data) ? $data : ['value' => $data];
        }

        if (method_exists($obj, 'toArray')) {
            $data = $obj->toArray();
            return is_array($data) ? $data : ['value' => $data];
        }

        if (method_exists($obj, '__toString')) {
            return ['value' => (string) $obj];
        }

        return get_object_vars($obj);
    }

    protected static function scalarToString($v): string {
        if ($v === null) {
            return 'null';
        }

        if ($v === true) {
            return 'true';
        }

        if ($v === false) {
            return 'false';
        }

        if (is_scalar($v)) {
            return (string) $v;
        }

        return wp_json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
    
}
