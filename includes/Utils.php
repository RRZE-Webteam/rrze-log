<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * Utility functions
 */
class Utils
{
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
        $search = $args['search'] ?? [];
        $limit = $args['limit'] ?? -1;
        $offset = $args['offset'] ?? 0;

        return self::getLog('', $search, $offset, $limit)['items'] ?? [];
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
        $logFile = Constants::LOG_FILE;

        $search = is_array($search) && self::isNotMultidimensional($search) ?
            array_map('trim', $search) :
            [];
        $search = array_filter($search);
        $offset = absint($offset);
        $count = $count < 0 ? -1 : absint($count);

        $logParser = new LogParser($logFile, $search, $offset, $count);

        if (!is_network_admin()) {
            $logItems = $logParser->getItems('siteurl', site_url());
        } else {
            $logItems = $logParser->getItems();
        }

        $items = [];
        if (!is_wp_error($logItems)) {
            foreach ($logItems as $item) {
                $items[] = json_decode($item, true);
            }
        }

        $totalItems = $logParser->getTotalLines();

        return [
            'items' => $items,
            'total_items' => $totalItems
        ];
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
