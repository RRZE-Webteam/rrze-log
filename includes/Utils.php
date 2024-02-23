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
}
