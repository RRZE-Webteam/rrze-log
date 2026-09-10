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
    public const ACTION_LOG_DIR = self::LOG_DIR . '/rrze-log';

    /**
     * Default log file name.
     */
    public const LOG_FILE = self::ACTION_LOG_DIR . '/info.log';

    /**
     * Log files by error level.
     */
    public const LOG_LEVEL_FILES = [
        'ERROR' => self::ACTION_LOG_DIR . '/error.log',
        'WARNING' => self::ACTION_LOG_DIR . '/warning.log',
        'NOTICE' => self::ACTION_LOG_DIR . '/notice.log',
        'INFO' => self::ACTION_LOG_DIR . '/info.log',
    ];

    /**
     * Log rotation intervals.
     */
    public const LOG_ROTATION_INTERVALS = [
        'none',
        'daily',
        'weekly',
        'monthly',
    ];

    /**
     * Admin audit log file name (non-superadmin actors).
     */
    public const AUDIT_LOG_FILE = self::LOG_DIR . '/rrze-admin-audit.log';

    /**
     * Superadmin audit log file name (multisite superadmin actors).
     */
    public const SUPERADMIN_AUDIT_LOG_FILE = self::LOG_DIR . '/rrze-superadmin-audit.log';

    /**
     * Websupport audit log file name (multisite websupport actors).
     */
    public const WEBSUPPORT_AUDIT_LOG_FILE = self::LOG_DIR . '/rrze-websupport-audit.log';

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

    /**
     * Normalizes an Action Log level.
     */
    public static function normalizeLogLevel(string $level): string {
        $level = strtoupper(trim($level));

        if ($level === 'WARN') {
            return 'WARNING';
        }

        if (in_array($level, self::LEVELS, true)) {
            return $level;
        }

        return 'INFO';
    }

    /**
     * Returns the Action Log file for a level.
     */
    public static function getLogFileForLevel(string $level): string {
        $level = self::normalizeLogLevel($level);

        return self::LOG_LEVEL_FILES[$level];
    }

    /**
     * Returns all Action Log files.
     */
    public static function getActionLogFiles(): array {
        return self::LOG_LEVEL_FILES;
    }
}
