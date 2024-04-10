<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * [Utils description]
 */
class Utils
{
    /**
     * Check if a string is valid JSON.
     *
     * @param string $string
     * @return boolean
     */
    public static function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Check if the WP_DEBUG_LOG constant is defined and has the correct value.
     * @return boolean|\WP_Error
     */
    public static function isDebugLog()
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }
        if (!is_string(WP_DEBUG_LOG) || WP_DEBUG_LOG != Constants::DEBUG_LOG_PATH . date('Y-m-d') . '.log') {
            return new \WP_Error(
                'wp_debug_log',
                sprintf(
                    /* translators: %s: WP_DEBUG_LOG value. */
                    __('Invalid value of the WP_DEBUG_LOG constant. WP_DEBUG_LOG must have the following value: %s', 'rrze-log'),
                    "ABSPATH . 'wp-content/log/rrze-log/debug/' . date('Y-m-d') . '.log'"
                )
            );
        }
        return true;
    }

    /**
     * Verify if the string has the correct 'yyyy-mm-dd' format.
     * @param  string $string 'yyyy-mm-dd' format
     * @return boolean
     */
    public static function verifyLogfileFormat($string)
    {
        return preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/", $string);
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
        $logPath = Constants::LOG_PATH;
        if (!self::verifyLogfileFormat($logFile)) {
            $logFile = date('Y-m-d');
        }
        $logFile = sprintf('%1$s%2$s.log', $logPath, $logFile);
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
    public static function isNotMultidimensional($array)
    {
        foreach ($array as $value) {
            if (is_array($value)) {
                return false;
            }
        }
        return true;
    }
}
