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

}
