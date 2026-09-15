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
     * WP-Cron hook name for per-level Action Log rotation.
     */
    public const ROTATE_EVENT_HOOK = 'rrze_log_rotate_event';

    /**
     * Default interval slug.
     */
    protected const DEFAULT_INTERVAL_SLUG = 'hourly';

    /**
     * Monthly interval slug for Action Log rotation.
     */
    protected const MONTHLY_INTERVAL_SLUG = 'rrze_log_monthly';

    /**
     * Network/site option name for per-level rotation state.
     */
    protected const ROTATION_STATE_OPTION = 'rrze_log_rotation_state';

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
        self::registerScheduleFilter();
        add_action('init', [self::class, 'ensureScheduled']);
        add_action(self::EVENT_HOOK, [self::class, 'handle']);
        add_action(self::ROTATE_EVENT_HOOK, [self::class, 'handleRotation'], 10, 1);
    }

    /**
     * Register cron schedule filters once.
     */
    protected static function registerScheduleFilter(): void {
        if (has_filter('cron_schedules', [self::class, 'registerCustomInterval'])) {
            return;
        }

        add_filter('cron_schedules', [self::class, 'registerCustomInterval']);
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

        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Weekly', 'rrze-log'),
            ];
        }

        $monthSeconds = defined('MONTH_IN_SECONDS') ? MONTH_IN_SECONDS : 30 * DAY_IN_SECONDS;
        $schedules[self::MONTHLY_INTERVAL_SLUG] = [
            'interval' => $monthSeconds,
            'display' => __('Monthly', 'rrze-log'),
        ];

        return $schedules;
    }

    /**
     * Ensure the cron event is scheduled (idempotent).
     */
    public static function ensureScheduled(): void {
        if (!self::isCronSite()) {
            self::unschedule();
            return;
        }

        $options = Options::getOptions();

        self::syncRotationEvents($options);

        $need = false;

        if (self::hasActionLogTruncationMaintenance($options)) {
            $need = true;
        }

        if (self::hasDebugLogTruncationMaintenance($options)) {
            $need = true;
        }

        if (!empty($options->auditEnabled) && !empty($options->auditMaxLines) && (int) $options->auditMaxLines > 0) {
            $need = true;
        }

        if (
            is_multisite()
            && !empty($options->auditEnabled)
            && !empty($options->superadminAuditMaxLines)
            && (int) $options->superadminAuditMaxLines > 0
        ) {
            $need = true;
        }

        if (
            is_multisite()
            && !empty($options->auditEnabled)
            && !empty($options->websupportAuditMaxLines)
            && (int) $options->websupportAuditMaxLines > 0
        ) {
            $need = true;
        }

        if (!$need) {
            self::unscheduleTruncation();
            return;
        }

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
        if (!self::isCronSite()) {
            return;
        }

        $options = Options::getOptions();

        $targets = self::getActionLogTruncateTargets($options);

        $debugLines = self::hasDebugLogTruncationMaintenance($options)
            ? (int) $options->debugMaxLines
            : 0;
        if ($debugLines > 0) {
            $targets[] = [
                'file' => Constants::DEBUG_LOG_FILE,
                'lines' => $debugLines,
            ];
        }

        if (!empty($options->auditEnabled)) {
            $targets[] = [
                'file' => Constants::AUDIT_LOG_FILE,
                'lines' => $options->auditMaxLines ?? 1000,
            ];

            if (is_multisite()) {
                $targets[] = [
                    'file' => Constants::SUPERADMIN_AUDIT_LOG_FILE,
                    'lines' => $options->superadminAuditMaxLines ?? 1000,
                ];

                $targets[] = [
                    'file' => Constants::WEBSUPPORT_AUDIT_LOG_FILE,
                    'lines' => $options->websupportAuditMaxLines ?? 1000,
                ];
            }
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

            try {
                $ok = $trunc->truncate($file, $lines);
            } catch (\Throwable $e) {
                error_log(sprintf('[RRZE-Log] Truncate failed for %s: %s', $file, $e->getMessage()));
                continue;
            }

            if (!$ok) {
                error_log(sprintf('[RRZE-Log] Truncate returned false for %s', $file));
            }
        }
    }

    /**
     * Handler: rotate a single Action Log level if still configured.
     */
    public static function handleRotation(string $level = ''): void {
        if (!self::isCronSite()) {
            return;
        }

        $level = Constants::normalizeLogLevel($level);
        $options = Options::getOptions();

        if (empty($options->enabled)) {
            return;
        }

        $rotations = isset($options->levelRotation) && is_array($options->levelRotation)
            ? $options->levelRotation
            : [];
        $rotation = isset($rotations[$level]) ? (string) $rotations[$level] : 'none';

        if ($rotation === 'none') {
            return;
        }

        if (!in_array($rotation, Constants::LOG_ROTATION_INTERVALS, true)) {
            return;
        }

        self::rotateActionLogFile($level, $rotation);
    }

    /**
     * Rotates an Action Log level before writing when the configured period changed.
     */
    public static function rotateBeforeWrite(string $level): void {
        $level = Constants::normalizeLogLevel($level);
        $options = Options::getOptions();

        if (empty($options->enabled)) {
            return;
        }

        $rotations = isset($options->levelRotation) && is_array($options->levelRotation)
            ? $options->levelRotation
            : [];
        $rotation = isset($rotations[$level]) ? (string) $rotations[$level] : 'none';

        if ($rotation === 'none') {
            return;
        }

        if (!in_array($rotation, Constants::LOG_ROTATION_INTERVALS, true)) {
            return;
        }

        self::rotateActionLogFile($level, $rotation);
    }

    /**
     * Checks whether Action Log truncation is configured.
     */
    protected static function hasActionLogTruncationMaintenance(object $options): bool {
        if (empty($options->enabled)) {
            return false;
        }

        $limits = isset($options->levelMaxLines) && is_array($options->levelMaxLines)
            ? $options->levelMaxLines
            : [];
        $rotations = isset($options->levelRotation) && is_array($options->levelRotation)
            ? $options->levelRotation
            : [];

        foreach (Constants::LEVELS as $level) {
            $rotation = isset($rotations[$level]) ? (string) $rotations[$level] : 'none';
            if ($rotation !== 'none') {
                continue;
            }

            $lines = isset($limits[$level]) ? (int) $limits[$level] : (int) ($options->maxLines ?? 0);
            if ($lines > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether Debug Log truncation is configured and applicable.
     */
    protected static function hasDebugLogTruncationMaintenance(object $options): bool {
        if (empty($options->debugMaxLines) || (int) $options->debugMaxLines <= 0) {
            return false;
        }

        $debug = Utils::isDebugLog();

        return $debug === true;
    }

    /**
     * Returns Action Log truncation targets for levels without rotation.
     */
    protected static function getActionLogTruncateTargets(object $options): array {
        $limits = isset($options->levelMaxLines) && is_array($options->levelMaxLines)
            ? $options->levelMaxLines
            : [];
        $rotations = isset($options->levelRotation) && is_array($options->levelRotation)
            ? $options->levelRotation
            : [];

        $targets = [];

        foreach (Constants::LEVELS as $level) {
            $rotation = isset($rotations[$level]) ? (string) $rotations[$level] : 'none';
            if ($rotation !== 'none') {
                continue;
            }

            $lines = isset($limits[$level]) ? (int) $limits[$level] : (int) ($options->maxLines ?? 1000);
            if ($lines <= 0) {
                continue;
            }

            $targets[] = [
                'file' => Constants::getLogFileForLevel($level),
                'lines' => $lines,
            ];
        }

        return $targets;
    }

    /**
     * Rotates one Action Log level if its selected rotation period has elapsed.
     */
    protected static function rotateActionLogFile(string $level, string $rotation): void {
        $file = Constants::getLogFileForLevel($level);
        $timezone = wp_timezone();
        $nowDate = new \DateTimeImmutable('now', $timezone);
        $currentPeriod = self::getRotationPeriodKey($nowDate, $rotation);
        $statePeriod = self::getRotationStatePeriod($level, $rotation, $file);

        if ($statePeriod === '' || $statePeriod === $currentPeriod) {
            return;
        }

        if (!file_exists($file) || !is_file($file)) {
            self::updateRotationStatePeriod($level, $rotation, $currentPeriod);
            return;
        }

        $target = self::getRotationTargetForPeriod($file, $rotation, $statePeriod);
        if ($target === '') {
            self::updateRotationStatePeriod($level, $rotation, $currentPeriod);
            return;
        }

        if (!self::rotateFile($file, $target)) {
            error_log(sprintf('[RRZE-Log] Rotate failed for %s', $file));
            return;
        }

        self::updateRotationStatePeriod($level, $rotation, $currentPeriod);
    }

    /**
     * Returns the stored or initial rotation period for a level.
     */
    protected static function getRotationStatePeriod(string $level, string $rotation, string $file): string {
        $state = self::getRotationState();

        if (
            isset($state[$level])
            && is_array($state[$level])
            && isset($state[$level]['rotation'], $state[$level]['period'])
            && (string) $state[$level]['rotation'] === $rotation
        ) {
            return (string) $state[$level]['period'];
        }

        $period = self::getInitialRotationPeriod($file, $rotation);
        self::updateRotationStatePeriod($level, $rotation, $period);

        return $period;
    }

    /**
     * Returns the initial period for newly configured rotation.
     */
    protected static function getInitialRotationPeriod(string $file, string $rotation): string {
        $timezone = wp_timezone();
        $mtime = file_exists($file) && is_file($file) ? @filemtime($file) : false;

        if ($mtime) {
            $date = (new \DateTimeImmutable('@' . $mtime))->setTimezone($timezone);
            return self::getRotationPeriodKey($date, $rotation);
        }

        $date = new \DateTimeImmutable('now', $timezone);
        return self::getRotationPeriodKey($date, $rotation);
    }

    /**
     * Returns the rotation archive target for a period.
     */
    protected static function getRotationTargetForPeriod(string $file, string $rotation, string $period): string {
        $timezone = wp_timezone();
        $dir = dirname($file);
        $name = pathinfo($file, PATHINFO_FILENAME);

        switch ($rotation) {
            case 'daily':
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $period, $timezone);
                if (!$date) {
                    return '';
                }
                $suffix = 'daily-' . $date->format('N');
                break;
            case 'weekly':
                $parts = explode('-', $period);
                $weekInMonth = end($parts);
                if ($weekInMonth === false || !ctype_digit((string) $weekInMonth)) {
                    return '';
                }
                $suffix = 'weekly-' . $weekInMonth;
                break;
            case 'monthly':
                $date = \DateTimeImmutable::createFromFormat('!Y-m', $period, $timezone);
                if (!$date) {
                    return '';
                }
                $suffix = 'monthly-' . $date->format('n');
                break;
            default:
                return '';
        }

        return $dir . '/' . $name . '-' . $suffix . '.log';
    }

    /**
     * Returns a stable period key for rotation comparisons.
     */
    protected static function getRotationPeriodKey(\DateTimeImmutable $date, string $rotation): string {
        switch ($rotation) {
            case 'daily':
                return $date->format('Y-m-d');
            case 'weekly':
                return $date->format('Y-m-') . self::getWeekInMonth($date);
            case 'monthly':
                return $date->format('Y-m');
            default:
                return '';
        }
    }

    /**
     * Returns the week number within the month.
     */
    protected static function getWeekInMonth(\DateTimeImmutable $date): int {
        return (int) ceil(((int) $date->format('j')) / 7);
    }

    /**
     * Returns rotation state from network/site storage.
     */
    protected static function getRotationState(): array {
        $state = is_multisite()
            ? get_site_option(self::ROTATION_STATE_OPTION, [])
            : get_option(self::ROTATION_STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    /**
     * Stores rotation state in network/site storage.
     */
    protected static function updateRotationState(array $state): void {
        if (is_multisite()) {
            update_site_option(self::ROTATION_STATE_OPTION, $state);
            return;
        }

        update_option(self::ROTATION_STATE_OPTION, $state, false);
    }

    /**
     * Updates the stored rotation period for a level.
     */
    protected static function updateRotationStatePeriod(string $level, string $rotation, string $period): void {
        if ($period === '') {
            return;
        }

        $state = self::getRotationState();
        $state[$level] = [
            'rotation' => $rotation,
            'period' => $period,
        ];

        self::updateRotationState($state);
    }

    /**
     * Clears the stored rotation period for a level.
     */
    protected static function clearRotationStatePeriod(string $level): void {
        $state = self::getRotationState();

        if (!isset($state[$level])) {
            return;
        }

        unset($state[$level]);
        self::updateRotationState($state);
    }

    /**
     * Deletes all stored rotation state.
     */
    protected static function deleteRotationState(): void {
        if (is_multisite()) {
            delete_site_option(self::ROTATION_STATE_OPTION);
            return;
        }

        delete_option(self::ROTATION_STATE_OPTION);
    }

    /**
     * Rotates a file by renaming it over the target archive.
     */
    protected static function rotateFile(string $file, string $target): bool {
        $lockPath = $file . '.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if (!$lockHandle) {
            return false;
        }

        if (!@flock($lockHandle, LOCK_EX)) {
            @fclose($lockHandle);
            return false;
        }

        clearstatcache(true, $file);
        if (!file_exists($file) || !is_file($file)) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
            return true;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@wp_mkdir_p($dir)) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
            return false;
        }

        if (file_exists($target) && !@unlink($target)) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
            return false;
        }

        $ok = @rename($file, $target);

        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);

        return $ok;
    }

    /**
     * Synchronizes per-level rotation events with the current settings.
     */
    protected static function syncRotationEvents(object $options): void {
        if (empty($options->enabled)) {
            self::unscheduleRotations();
            self::deleteRotationState();
            return;
        }

        $rotations = isset($options->levelRotation) && is_array($options->levelRotation)
            ? $options->levelRotation
            : [];

        foreach (Constants::LEVELS as $level) {
            $rotation = isset($rotations[$level]) ? (string) $rotations[$level] : 'none';
            $schedule = self::getRotationScheduleSlug($rotation);

            if ($schedule === '') {
                self::unscheduleRotationForLevel($level);
                self::clearRotationStatePeriod($level);
                continue;
            }

            self::getRotationStatePeriod($level, $rotation, Constants::getLogFileForLevel($level));
            self::ensureRotationScheduled($level, $schedule);
        }
    }

    /**
     * Ensures one rotation event exists for the given level and interval.
     */
    protected static function ensureRotationScheduled(string $level, string $schedule): void {
        $args = [$level];
        $event = function_exists('wp_get_scheduled_event')
            ? wp_get_scheduled_event(self::ROTATE_EVENT_HOOK, $args)
            : null;

        if (is_object($event)) {
            if (isset($event->schedule) && (string) $event->schedule === $schedule) {
                return;
            }

            self::unscheduleRotationForLevel($level);
        } elseif (wp_next_scheduled(self::ROTATE_EVENT_HOOK, $args)) {
            return;
        }

        wp_schedule_event(time() + 60, $schedule, self::ROTATE_EVENT_HOOK, $args);
    }

    /**
     * Returns the WP-Cron schedule slug for a rotation setting.
     */
    protected static function getRotationScheduleSlug(string $rotation): string {
        switch ($rotation) {
            case 'daily':
                return 'daily';
            case 'weekly':
                return 'weekly';
            case 'monthly':
                return self::MONTHLY_INTERVAL_SLUG;
            default:
                return '';
        }
    }

    /**
     * Returns the blog ID that owns network-wide RRZE Log cron events.
     */
    protected static function getCronSiteId(): int {
        if (!is_multisite()) {
            return get_current_blog_id();
        }

        if (function_exists('get_main_site_id')) {
            return (int) get_main_site_id();
        }

        $network = get_network();
        return isset($network->site_id) ? (int) $network->site_id : 1;
    }

    /**
     * Checks whether the current blog owns the network-wide cron events.
     */
    protected static function isCronSite(): bool {
        if (!is_multisite()) {
            return true;
        }

        return get_current_blog_id() === self::getCronSiteId();
    }

    /**
     * Force re-scheduling: unschedule any existing instance and schedule a fresh one.
     */
    public static function reschedule(): void {
        self::registerScheduleFilter();

        if (is_multisite() && !self::isCronSite()) {
            switch_to_blog(self::getCronSiteId());
            self::unschedule();
            self::ensureScheduled();
            restore_current_blog();
            return;
        }

        self::unschedule();
        self::ensureScheduled();
    }

    /**
     * Unschedule the cron event (remove future occurrences).
     */
    public static function unschedule(): void {
        self::unscheduleTruncation();
        self::unscheduleRotations();
    }

    /**
     * Unschedule cron events, optionally across the entire network.
     */
    public static function unscheduleNetwork(bool $networkWide = false): void {
        if (!is_multisite() || !$networkWide) {
            self::unschedule();
            self::deleteRotationState();
            return;
        }

        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);

        foreach ($siteIds as $siteId) {
            switch_to_blog((int) $siteId);
            self::unschedule();
            restore_current_blog();
        }

        self::deleteRotationState();
    }

    /**
     * Unschedule the truncation event.
     */
    protected static function unscheduleTruncation(): void {
        $ts = wp_next_scheduled(self::EVENT_HOOK);

        while ($ts) {
            wp_unschedule_event($ts, self::EVENT_HOOK);
            $ts = wp_next_scheduled(self::EVENT_HOOK);
        }
    }

    /**
     * Unschedule all per-level rotation events.
     */
    protected static function unscheduleRotations(): void {
        foreach (Constants::LEVELS as $level) {
            self::unscheduleRotationForLevel($level);
        }

        wp_clear_scheduled_hook(self::ROTATE_EVENT_HOOK);
    }

    /**
     * Unschedule the rotation event for one Action Log level.
     */
    protected static function unscheduleRotationForLevel(string $level): void {
        $args = [$level];
        $ts = wp_next_scheduled(self::ROTATE_EVENT_HOOK, $args);

        while ($ts) {
            wp_unschedule_event($ts, self::ROTATE_EVENT_HOOK, $args);
            $ts = wp_next_scheduled(self::ROTATE_EVENT_HOOK, $args);
        }
    }
}
