<?php

namespace RRZE\Log\File;

defined('ABSPATH') || exit;

/**
 * Flock
 *
 * Simple file-based mutex + append logger.
 * - Acquires an exclusive advisory lock (flock) on a file opened in append mode.
 * - Provides write helpers that are guaranteed to run under the lock.
 * - Designed for logging to a custom file (not using error_log()).
 */
class Flock
{
    /** @var bool Whether we currently hold the lock */
    protected bool $locked = false;

    /** @var resource|null Underlying file handle */
    public $fp = null;

    /** @var string Absolute file path to write to */
    protected string $filePath;

    /** @var int Lock acquisition sleep (microseconds) base for backoff */
    protected int $retryBaseUs = 2000; // 2 ms

    /**
     * @param string $filePath Absolute path to the log file.
     */
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
     * - Ensures the directory exists and is writable.
     * - Opens the file in binary append mode ('ab') for portability.
     * - Disables the stream write buffer (line-buffering is not reliable for logs).
     *
     * @param int $timeoutMs 0 = non-blocking (fail fast). >0 = wait up to that time.
     * @return self
     *
     * @throws FlockException on failure to open or lock the file.
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
                        /* translators: %s: directory path */
                        __('Cannot create directory %s.', 'rrze-log'),
                        $dir
                    )
                );
            }
        }
        if (!is_writable($dir)) {
            throw new FlockException(
                sprintf(
                    /* translators: %s: directory path */
                    __('Directory is not writable: %s', 'rrze-log'),
                    $dir
                )
            );
        }

        $this->fp = @fopen($this->filePath, 'ab'); // binary append
        if (!$this->fp) {
            throw new FlockException(
                sprintf(
                    /* translators: %s: file path */
                    __('Cannot open log file for append: %s', 'rrze-log'),
                    $this->filePath
                )
            );
        }

        // Disable buffering for timely writes
        @stream_set_write_buffer($this->fp, 0);

        $deadline = $timeoutMs > 0 ? (microtime(true) + ($timeoutMs / 1000)) : 0;
        $attempt  = 0;

        // Try non-blocking first; if it fails and timeout>0, retry with small sleeps
        while (!@flock($this->fp, LOCK_EX | LOCK_NB)) {
            if ($timeoutMs <= 0) {
                // Non-blocking mode: fail immediately
                $this->cleanupOpen();
                throw new FlockException(
                    sprintf(
                        /* translators: %s: file path */
                        __('Could not get lock on %s (busy).', 'rrze-log'),
                        $this->filePath
                    )
                );
            }
            if (microtime(true) >= $deadline) {
                $this->cleanupOpen();
                throw new FlockException(
                    sprintf(
                        /* translators: %s: file path */
                        __('Timed out acquiring lock on %s.', 'rrze-log'),
                        $this->filePath
                    )
                );
            }
            // Exponential-ish backoff up to ~8ms
            $sleepUs = min($this->retryBaseUs * max(1, ++$attempt), 8000);
            usleep($sleepUs);
        }

        $this->locked = true;
        return $this;
    }

    /**
     * Write raw bytes to the file (no newline added).
     * Requires the lock to be held.
     *
     * @param string $bytes
     * @return int bytes written
     *
     * @throws FlockException if not locked or write fails.
     */
    public function write(string $bytes): int
    {
        if (!$this->locked || !is_resource($this->fp)) {
            throw new FlockException(__('Write attempted without lock.', 'rrze-log'));
        }
        $n = @fwrite($this->fp, $bytes);
        if ($n === false) {
            throw new FlockException(__('Write failed.', 'rrze-log'));
        }
        // Ensure data is flushed to the OS (fsync is usually overkill for logs)
        @fflush($this->fp);
        return $n;
    }

    /**
     * Write a line and ensure exactly one trailing newline.
     *
     * @param string $line
     * @return int bytes written
     */
    public function writeln(string $line): int
    {
        $line = rtrim($line, "\r\n") . "\n";
        return $this->write($line);
    }

    /**
     * Release the lock and close the file.
     *
     * @return self
     */
    public function release(): self
    {
        if ($this->locked && is_resource($this->fp)) {
            @flock($this->fp, LOCK_UN);
            @fclose($this->fp);
        }
        $this->fp    = null;
        $this->locked = false;
        return $this;
    }

    /**
     * Helper to close the file if open (used when failing to lock).
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
