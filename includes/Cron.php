<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * Cron
 *
 * Registers, schedules, and handles log truncation via WP-Cron.
 */
class Cron {

    /**
     * WP-Cron hook name for the truncation task.
     */
    public const EVENT_HOOK = 'rrze_log_truncate_event';

    /**
     * Default interval slug.
     */
    protected const DEFAULT_INTERVAL_SLUG = 'hourly';

    /**
     * Custom interval slug we add to cron_schedules (only if needed).
     */
    protected const CUSTOM_INTERVAL_SLUG = 'rrze_log_every_5_minutes';

    /**
     * Default custom interval seconds (only used if CUSTOM_INTERVAL_SLUG is selected).
     */
    protected const CUSTOM_INTERVAL_SECS = 300;

    /**
     * Bootstrap: call this once from your plugin bootstrap.
     */
    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'registerCustomInterval']);
        add_action('init', [self::class, 'ensureScheduled']);
        add_action(self::EVENT_HOOK, [self::class, 'handle']);
    }

    /**
     * Register a custom schedule if chosen by filter.
     */
    public static function registerCustomInterval(array $schedules): array {
        $intervalSlug = apply_filters('rrze_log/cron/interval_slug', self::DEFAULT_INTERVAL_SLUG);

        if ($intervalSlug === self::CUSTOM_INTERVAL_SLUG) {
            $seconds = (int) apply_filters('rrze_log/cron/custom_interval_seconds', self::CUSTOM_INTERVAL_SECS);
            if ($seconds < 60) {
                $seconds = 60;
            }

            $schedules[self::CUSTOM_INTERVAL_SLUG] = [
                'interval' => $seconds,
                'display' => sprintf(
                    /* translators: %d: interval seconds */
                    __('Every %d seconds', 'rrze-log'),
                    $seconds
                ),
            ];
        }

        return $schedules;
    }

    /**
     * Ensure the cron event is scheduled (idempotent).
     */
    public static function ensureScheduled(): void {
        $intervalSlug = apply_filters('rrze_log/cron/interval_slug', self::DEFAULT_INTERVAL_SLUG);

        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event(time() + 60, $intervalSlug, self::EVENT_HOOK);
        }
    }

    /**
     * Handler: perform truncation for all configured targets.
     *
     * Filter: rrze_log/truncate_targets
     *   Return an array of items:
     *     [
     *       [ 'file' => '/abs/path/to/debug.log', 'lines' => 50000 ],
     *       [ 'file' => '/abs/path/to/another.log', 'lines' => 100000 ],
     *     ]
     */
    public static function handle(): void {
        $options = Options::getOptions();

        $targets = [
            [
                'file' => Constants::LOG_FILE,
                'lines' => $options->maxLines ?? 1000,
            ],
            [
                'file' => Constants::DEBUG_LOG_FILE,
                'lines' => $options->debugMaxLines ?? 1000,
            ],
        ];

        if (!empty($options->auditEnabled)) {
            $targets[] = [
                'file' => Constants::AUDIT_LOG_FILE,
                'lines' => $options->maxLines ?? 1000,
            ];
        }

        $targets = apply_filters('rrze_log/truncate_targets', $targets);

        if (!is_array($targets)) {
            return;
        }

        $trunc = new Truncator();

        foreach ($targets as $t) {
            $file = isset($t['file']) ? (string) $t['file'] : '';
            $lines = isset($t['lines']) ? (int) $t['lines'] : 0;

            if (!$file || $lines <= 0) {
                continue;
            }

            if (!file_exists($file)) {
                continue;
            }

            $ok = false;

            try {
                $ok = $trunc->truncate($file, $lines);
            } catch (\Throwable $e) {
                $ok = false;
                error_log(sprintf('[RRZE-Log] Truncate failed for %s: %s', $file, $e->getMessage()));
            }

            if (!$ok) {
                error_log(sprintf('[RRZE-Log] Truncate returned false for %s', $file));
            }
        }
    }

    /**
     * Force re-scheduling: unschedule any existing instance and schedule a fresh one.
     */
    public static function reschedule(): void {
        self::unschedule();
        self::ensureScheduled();
    }

    /**
     * Unschedule the cron event (remove future occurrences).
     */
    public static function unschedule(): void {
        $ts = wp_next_scheduled(self::EVENT_HOOK);

        while ($ts) {
            wp_unschedule_event($ts, self::EVENT_HOOK);
            $ts = wp_next_scheduled(self::EVENT_HOOK);
        }
    }
}
