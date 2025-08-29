<?php

namespace RRZE\Log\File;

defined('ABSPATH') || exit;

use RuntimeException;

class FlockException extends RuntimeException {}

/**
 * Flock
 *
 * File-based mutex + append writer for logs.
 * - Exclusive advisory lock with flock()
 * - Binary append, unbuffered writes
 * - Retriable, bounded lock acquisition
 */
class Flock
{
    /** Whether we currently hold the lock */
    protected bool $locked = false;

    /** Underlying file handle (kept private) */
    private $fp = null; // resource|null

    /** Absolute file path to write to */
    protected string $filePath;

    /** Backoff base (µs) and cap (µs) for lock retries */
    protected int $retryBaseUs = 2000; // 2 ms
    protected int $retryCapUs  = 8000; // 8 ms

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Acquire an exclusive lock. Optionally wait up to $timeoutMs milliseconds.
     *
     * @throws FlockException
     */
    public function acquire(int $timeoutMs = 0): self
    {
        if ($this->locked) {
            return $this;
        }

        $dir = \dirname($this->filePath);
        if (!is_dir($dir)) {
            if (!@wp_mkdir_p($dir)) {
                throw new FlockException(
                    sprintf(
                        /* translators: %s: directory path. */
                        __('Cannot create directory %s.', 'rrze-log'),
                        $dir
                    )
                );
            }
        }
        if (!is_writable($dir)) {
            throw new FlockException(
                sprintf(
                    /* translators: %s: directory path. */
                    __('Directory is not writable: %s', 'rrze-log'),
                    $dir
                )
            );
        }

        $this->fp = @fopen($this->filePath, 'ab'); // binary append
        if (!$this->fp) {
            throw new FlockException(
                sprintf(
                    /* translators: %s: file path. */
                    __('Cannot open log file for append: %s', 'rrze-log'),
                    $this->filePath
                )
            );
        }

        @stream_set_write_buffer($this->fp, 0);

        $deadline = $timeoutMs > 0 ? (microtime(true) + ($timeoutMs / 1000)) : 0.0;
        $attempt  = 0;

        while (!@flock($this->fp, LOCK_EX | LOCK_NB)) {
            if ($timeoutMs <= 0) {
                $this->cleanupOpen();
                throw new FlockException(
                    sprintf(
                        /* translators: %s: file path. */
                        __('Could not get lock on %s (busy).', 'rrze-log'),
                        $this->filePath
                    )
                );
            }
            if (microtime(true) >= $deadline) {
                $this->cleanupOpen();
                throw new FlockException(
                    sprintf(
                        /* translators: %s: file path. */
                        __('Timed out acquiring lock on %s.', 'rrze-log'),
                        $this->filePath
                    )
                );
            }
            $sleepUs = min($this->retryBaseUs * max(1, ++$attempt), $this->retryCapUs);
            usleep($sleepUs);
        }

        $this->locked = true;
        return $this;
    }

    /**
     * Write raw bytes (no newline). Requires the lock.
     * Loops until the full buffer is written.
     *
     * @return int total bytes written
     * @throws FlockException
     */
    public function write(string $bytes, bool $flush = true, bool $fsync = false): int
    {
        if (!$this->locked || !is_resource($this->fp)) {
            throw new FlockException(__('Write attempted without lock.', 'rrze-log'));
        }

        $len = strlen($bytes);
        $off = 0;
        while ($off < $len) {
            $n = @fwrite($this->fp, substr($bytes, $off));
            if ($n === false) {
                throw new FlockException(__('Write failed.', 'rrze-log'));
            }
            $off += $n;
        }

        if ($flush) {
            @fflush($this->fp);
            if ($fsync && function_exists('fsync')) {
                // fsync availability varies; guard it
                @fsync($this->fp);
            }
        }

        return $off;
    }

    /**
     * Write a line ensuring exactly one trailing newline.
     */
    public function writeln(string $line, bool $flush = true, bool $fsync = false): int
    {
        $line = rtrim($line, "\r\n") . "\n";
        return $this->write($line, $flush, $fsync);
    }

    /**
     * Utility: acquire, call the callback, always release.
     *
     * @param callable(self $flock):void $callback
     */
    public function withLock(callable $callback, int $timeoutMs = 0): void
    {
        $this->acquire($timeoutMs);
        try {
            $callback($this);
        } finally {
            $this->release();
        }
    }

    /**
     * Release the lock and close the file.
     */
    public function release(): self
    {
        if ($this->locked && is_resource($this->fp)) {
            @flock($this->fp, LOCK_UN);
            @fclose($this->fp);
        }
        $this->fp = null;
        $this->locked = false;
        return $this;
    }

    /**
     * Check if we currently hold the lock.
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Get the file path (for diagnostics).
     */
    public function getPath(): string
    {
        return $this->filePath;
    }

    /**
     * Cleanup the open file handle without releasing the lock.
     */
    protected function cleanupOpen(): void
    {
        if (is_resource($this->fp)) {
            @fclose($this->fp);
        }
        $this->fp = null;
        $this->locked = false;
    }
}
