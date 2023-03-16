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
            return new \WP_Error('wp_debug_log', __("Invalid value of the WP_DEBUG_LOG constant. WP_DEBUG_LOG must have the following value: ABSPATH . 'wp-content/log/rrze-log/log/' . date('Y-m-d') . '.log'", 'rrze-log'));
        }
        return true;
    }

    /**
     * Log errors by writing to the debug.log file.
     */
    public static function debug($input, string $level = 'i')
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        if (in_array(strtolower((string) WP_DEBUG_LOG), ['true', '1'], true)) {
            $logPath = WP_CONTENT_DIR . '/debug.log';
        } elseif (is_string(WP_DEBUG_LOG)) {
            $logPath = WP_DEBUG_LOG;
        } else {
            return;
        }
        if (is_array($input) || is_object($input)) {
            $input = print_r($input, true);
        }
        switch (strtolower($level)) {
            case 'e':
            case 'error':
                $level = 'Error';
                break;
            case 'i':
            case 'info':
                $level = 'Info';
                break;
            case 'd':
            case 'debug':
                $level = 'Debug';
                break;
            default:
                $level = 'Info';
        }
        error_log(
            date("[d-M-Y H:i:s \U\T\C]")
                . " WP $level: "
                . basename(__FILE__) . ' '
                . $input
                . PHP_EOL,
            3,
            $logPath
        );
    }
}
