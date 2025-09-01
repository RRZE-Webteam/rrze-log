<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\File\Flock;
use RRZE\Log\File\FlockException;
use RRZE\Log\REST\StrayOutputSniffer;

class Logger
{
    /** @var object Plugin options container */
    protected object $options;

    /** @var string Absolute path to the target log file */
    protected string $logFile = '';

    /** @var string The current site URL (for multi-site context in entries) */
    protected string $siteUrl = '';

    /** @var int File mode to set on first creation (subject to process umask) */
    protected int $filePermissions = 0644;

    public function __construct()
    {
        $this->options = Options::getOptions(); // your existing options source
    }

    /**
     * Call once when WP is loaded (e.g., on plugins_loaded).
     */
    public function loaded(): void
    {
        $this->siteUrl = site_url();
    }

    /** Convenience helpers */
    public function error(string $message, array $context = []): bool
    {
        return $this->log('ERROR',   $message, $context);
    }
    public function warning(string $message, array $context = []): bool
    {
        return $this->log('WARNING', $message, $context);
    }
    public function notice(string $message, array $context = []): bool
    {
        return $this->log('NOTICE',  $message, $context);
    }
    public function info(string $message, array $context = []): bool
    {
        return $this->log('INFO',    $message, $context);
    }

    /**
     * Core logger.
     */
    protected function log(string $level, string $message, array $context = []): bool
    {
        $this->logFile = Constants::LOG_FILE; // ensure absolute path
        if (!$this->ensureLogDirWritable()) {
            return false;
        }

        $entry = [
            'datetime' => $this->nowMicro(),
            'siteurl'  => $this->siteUrl,
            'level'    => $level,
            'message'  => $message,
            'context'  => $context,
        ];

        return $this->writeJsonLine($entry);
    }

    /**
     * Write one JSON line under an exclusive lock using Flock.
     */
    protected function writeJsonLine(array $data): bool
    {
        $isNew = !file_exists($this->logFile);

        $json = $this->jsonEncodeSafe($data) . "\n";
        $flock = new Flock($this->logFile);

        try {
            // Acquire lock, write the full line, auto-release in finally.
            $flock->withLock(function (Flock $l) use ($json) {
                // writeln() would also work, but we already have \n appended
                $l->write($json);
            }, 200); // wait up to 200ms if busy
        } catch (FlockException $e) {
            return false;
        }

        if ($isNew) {
            @chmod($this->logFile, $this->filePermissions);
        }

        return true;
    }

    /**
     * Ensure the log directory exists and is writable.
     */
    protected function ensureLogDirWritable(): bool
    {
        $dir = Constants::LOG_PATH;
        if (!is_dir($dir)) {
            if (!function_exists('wp_mkdir_p') || !@wp_mkdir_p($dir)) {
                return false;
            }
        }
        return is_writable($dir);
    }

    /**
     * JSON encode with safe flags and context normalization.
     */
    protected function jsonEncodeSafe(array $data): string
    {
        if (isset($data['context'])) {
            $data['context'] = $this->normalizeContext($data['context']);
        }

        try {
            return json_encode(
                $data,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PARTIAL_OUTPUT_ON_ERROR
                    | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '{}';
        } catch (\Throwable $e) {
            return json_encode([
                'datetime' => $data['datetime'] ?? $this->nowMicro(),
                'siteurl'  => $this->siteUrl,
                'level'    => $data['level'] ?? 'OTHER',
                'message'  => $data['message'] ?? '',
                'context'  => '(context not serializable)',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }
    }

    /**
     * Make context JSON-serializable.
     */
    protected function normalizeContext(array $ctx): array
    {
        foreach ($ctx as $k => $v) {
            if (is_resource($v)) {
                $ctx[$k] = 'RESOURCE(' . get_resource_type($v) . ')';
            } elseif ($v instanceof \Throwable) {
                $ctx[$k] = [
                    'exception' => get_class($v),
                    'message'   => $v->getMessage(),
                    'code'      => $v->getCode(),
                    'file'      => $v->getFile(),
                    'line'      => $v->getLine(),
                    'trace'     => $v->getTraceAsString(),
                ];
            } elseif (is_object($v) && !method_exists($v, '__toString')) {
                $ctx[$k] = ['object' => get_class($v)];
            }
        }
        return $ctx;
    }

    /**
     * Current time with microseconds.
     * Example: 2025-08-29 10:12:13.123456+02:00
     */
    protected function nowMicro(): string
    {
        $t  = microtime(true);
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $t)) ?: new \DateTimeImmutable('now');
        // Optionally align with WP timezone:
        // $dt = $dt->setTimezone( wp_timezone() );
        return $dt->format('Y-m-d H:i:s.uP');
    }

    /**
     * Attach the StrayOutputSniffer to REST requests.
     *
     * @param int  $site    Limit to specific blog_id (0 = all sites)
     * @param bool $guard   If true, stray output is cleaned to avoid broken JSON
     */
    public static function attachRestSniffer(int $site = 0, bool $guard = true): void
    {
        $logger = new self();
        $logger->loaded();

        $sniffer = new StrayOutputSniffer([
            'enabled' => true,
            'guard'   => $guard,
            'site'    => $site,
            'logger'  => function (string $message) use ($logger) {
                $logger->warning('REST stray output detected', [
                    'detail' => $message,
                ]);
            },
        ]);

        $sniffer->boot();
    }    
}
