<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

final class Constants {

    /**
     * Full log path.
     * @var string
     */
    public const LOG_DIR = WP_CONTENT_DIR . '/log';

    /**
     * Log file name.
     */
    public const LOG_FILE = self::LOG_DIR . '/rrze-log.log';

    /**
     * Admin audit log file name (non-superadmin actors).
     */
    public const AUDIT_LOG_FILE = self::LOG_DIR . '/rrze-admin-audit.log';

    /**
     * Superadmin audit log file name (multisite superadmin actors).
     */
    public const SUPERADMIN_AUDIT_LOG_FILE = self::LOG_DIR . '/rrze-superadmin-audit.log';

    /**
     * Debug log file name.
     */
    public const DEBUG_LOG_FILE = self::LOG_DIR . '/wp-debug.log';

    /*
     * WP-Cron hook name for the truncation task
     */
    public const CRON_HOOK = 'rrze_log_truncate';

    /**
     * Log error levels.
     * @var array
     */
    public const LEVELS = [
        'ERROR',
        'WARNING',
        'NOTICE',
        'INFO'
    ];

    /**
     * Debug error levels.
     * @var array
     */
    public const DEBUG_LEVELS = [
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
