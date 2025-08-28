<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * Cron
 *
 * Encapsulates all logic to register, schedule, and handle the log-truncation cron event.
 * - Schedules itself on `init` (no activation hook required).
 * - Provides a custom interval (filterable) or uses a built-in one (e.g., 'hourly').
 * - Executes truncation via LogTruncatorAtomic over a filterable target list.
 * - Offers helper methods to reschedule/unschedule.
 */
class Cron
{
    /** WP-Cron hook name for the truncation task */
    public const EVENT_HOOK = 'rrze_log_truncate_event';

    /** Default interval slug (use 'hourly' unless filters say otherwise) */
    protected const DEFAULT_INTERVAL_SLUG = 'hourly';

    /** Custom interval slug we add to cron_schedules (only if needed) */
    protected const CUSTOM_INTERVAL_SLUG  = 'rrze_log_every_5_minutes';

    /** Default custom interval seconds (only used if CUSTOM_INTERVAL_SLUG is selected) */
    protected const CUSTOM_INTERVAL_SECS  = 300; // 5 minutes

    /**
     * Bootstrap: call this once from your plugin bootstrap.
     *
     * Example (plugin main file):
     *   \RRZE\Log\Cron::init();
     */
    public static function init(): void
    {
        // 1) Provide (optional) custom interval
        add_filter('cron_schedules', [self::class, 'registerCustomInterval']);

        // 2) Ensure the event is scheduled (idempotent)
        add_action('init', [self::class, 'ensureScheduled']);

        // 3) Hook the handler for our event
        add_action(self::EVENT_HOOK, [self::class, 'handle']);
    }

    /**
     * Register a custom schedule if chosen by filter.
     *
     * Filter: rrze_log/cron/interval_slug
     *   - return 'hourly' (default), 'twicedaily', 'daily', or self::CUSTOM_INTERVAL_SLUG
     * Filter: rrze_log/cron/custom_interval_seconds
     *   - when using self::CUSTOM_INTERVAL_SLUG, allows overriding the 300s default
     */
    public static function registerCustomInterval(array $schedules): array
    {
        $intervalSlug = apply_filters('rrze_log/cron/interval_slug', self::DEFAULT_INTERVAL_SLUG);

        if ($intervalSlug === self::CUSTOM_INTERVAL_SLUG) {
            $seconds = (int) apply_filters('rrze_log/cron/custom_interval_seconds', self::CUSTOM_INTERVAL_SECS);
            if ($seconds < 60) {
                $seconds = 60; // sane floor
            }

            $schedules[self::CUSTOM_INTERVAL_SLUG] = [
                'interval' => $seconds,
                'display'  => sprintf(
                    /* translators: 1: number of seconds */
                    __('RRZE Log – every %d seconds', 'rrze-log'),
                    $seconds
                ),
            ];
        }

        return $schedules;
    }

    /**
     * Ensure the cron event is scheduled. Runs on every request (cheap & idempotent).
     * You can change start time via 'rrze_log/cron/start_timestamp' filter.
     */
    public static function ensureScheduled(): void
    {
        $interval = apply_filters('rrze_log/cron/interval_slug', self::DEFAULT_INTERVAL_SLUG);
        if (!wp_get_schedules() || !isset(wp_get_schedules()[$interval])) {
            // If a custom slug is requested but not present yet, `cron_schedules` will add it
            // before we get here; this is a safeguard in case of plugin load order.
        }

        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            $start = (int) apply_filters('rrze_log/cron/start_timestamp', time() + 60);
            wp_schedule_event($start, $interval, self::EVENT_HOOK);
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
     *
     * Notes:
     * - The truncator is atomic (lock + same-dir temp file + rename()).
     * - Failures are reported via error_log(). Adjust to your own logging if needed.
     */
    public static function handle(): void
    {
        $options = Options::getOptions();
        $targets = apply_filters('rrze_log/truncate_targets', [
            [
                'file'  => Constants::LOG_FILE,
                'lines' => $options->maxLines ?? 5000, // fallback if option missing
            ],
            [
                'file'  => Constants::DEBUG_LOG_FILE,
                'lines' => $options->debugMaxLines ?? 5000, // fallback if option missing
            ],
        ]);

        if (!is_array($targets)) {
            return;
        }

        $trunc = new Truncator();

        foreach ($targets as $t) {
            $file  = isset($t['file'])  ? (string) $t['file']  : '';
            $lines = isset($t['lines']) ? (int) $t['lines']     : 0;

            if (!$file || $lines <= 0) {
                continue;
            }
            if (!file_exists($file)) {
                // Optional: create empty file to normalize behavior
                // @touch($file);
                continue;
            }

            $ok = false;
            try {
                $ok = $trunc->truncate($file, $lines);
            } catch (\Throwable $e) {
                $ok = false;
                // You can route this to your own logger
                error_log(sprintf('[RRZE-Log] Truncate failed for %s: %s', $file, $e->getMessage()));
            }

            if (!$ok) {
                error_log(sprintf('[RRZE-Log] Truncate returned false for %s', $file));
            }
        }
    }

    /**
     * Force re-scheduling: unschedule any existing instance and schedule a fresh one.
     * Useful when admin changes interval settings.
     */
    public static function reschedule(): void
    {
        self::unschedule();
        self::ensureScheduled();
    }

    /**
     * Unschedule the cron event (remove future occurrences).
     */
    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::EVENT_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::EVENT_HOOK);
            $ts = wp_next_scheduled(self::EVENT_HOOK);
        }
    }
}
