<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Constants
{
    /**
     * Full log path.
     * @var string
     */
    const LOG_PATH = WP_CONTENT_DIR . '/log/';

    /**
     * Log file name.
     * @var string
     */
    const LOG_FILE = WP_CONTENT_DIR . '/log/rrze-log.log';

    /**
     * Log error levels.
     * @var array
     */
    const LEVELS = [
        'ERROR',
        'WARNING',
        'NOTICE',
        'INFO'
    ];

    /**
     * Debug log file name.
     * @var string
     */
    const DEBUG_LOG_FILE = WP_CONTENT_DIR . '/log/wp-debug.log';

    /**
     * Debug error levels.
     * @var array
     */
    const DEBUG_LEVELS = [
        'FATAL',
        'WARNING',
        'NOTICE',
        'DEPRECATED',
        'PARSE',
        'EXCEPTION',
        'DATABASE',
        'JAVASCRIPT',
        'OTHER'
    ];
}
