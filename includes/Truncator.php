<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * Truncator
 *
 * Atomically truncates a log file to keep only the last N lines.
 *
 * Concurrency model:
 * - Serialize truncations via a separate .lock file and flock(LOCK_EX).
 * - Writers are *not* locked (typical error_log() appenders don't use flock).
 * - Swap file contents atomically using a same-directory temp file + rename().
 *   On POSIX, rename() is atomic within the same filesystem.
 *   This mimics standard log rotation behavior.
 */
class Truncator
{
    /** @var int Chunk size (bytes) used when scanning backwards from the end of the file */
    protected int $chunkSize = 8192;

    /** @var string Suffix for the lock file */
    protected string $lockSuffix = '.lock';

    /** @var string Suffix for the temp file used before atomic rename */
    protected string $tempSuffix = '.trunc.tmp';

    /**
     * Truncate the log file to the last $maxLines lines (newest lines kept).
     * Operation is atomic with respect to readers/shell and serialized across truncators.
     *
     * @param string $filename  Absolute path to the log file.
     * @param int    $maxLines  Max number of lines to keep (must be > 0).
     * @return bool  True on success, false on failure.
     */
    public function truncate(string $filename, int $maxLines): bool
    {
        if ($maxLines <= 0) {
            return false;
        }

        // Basic checks
        if (!file_exists($filename)) {
            return false;
        }
        if (!is_file($filename)) {
            return false;
        }
        if (!is_readable($filename) || !is_writable($filename)) {
            return false;
        }

        $dir  = dirname($filename);
        $base = basename($filename);

        // 1) Acquire a process-level exclusive lock to serialize truncations
        $lockPath = $filename . $this->lockSuffix;
        $lockHandle = @fopen($lockPath, 'c'); // create if not exists
        if (!$lockHandle) {
            return false;
        }
        $locked = @flock($lockHandle, LOCK_EX);
        if (!$locked) {
            @fclose($lockHandle);
            return false;
        }

        // Re-check file after acquiring the lock (race conditions, rotation, etc.)
        clearstatcache(true, $filename);
        $origStat = @stat($filename);
        if ($origStat === false) {
            $this->releaseLock($lockHandle, $lockPath);
            return false;
        }
        $origPerm = $origStat['mode'] & 0777; // POSIX perms
        $origUid  = $origStat['uid'] ?? null;
        $origGid  = $origStat['gid'] ?? null;

        $size = $origStat['size'] ?? 0;
        if ($size <= 0) {
            // Nothing to truncate; still ensure a trailing newline exists.
            $ok = $this->ensureTrailingNewline($filename);
            $this->releaseLock($lockHandle, $lockPath);
            return $ok;
        }

        // 2) Read last N lines (streaming backwards)
        $lastLines = $this->readLastLines($filename, $maxLines);

        // If we couldn't read, abort gracefully
        if ($lastLines === null) {
            $this->releaseLock($lockHandle, $lockPath);
            return false;
        }

        // 3) Write them to a temp file in the same directory
        $tmpPath = $dir . DIRECTORY_SEPARATOR . $base . $this->tempSuffix . '.' . getmypid();
        $tmpHandle = @fopen($tmpPath, 'wb');
        if (!$tmpHandle) {
            $this->releaseLock($lockHandle, $lockPath);
            return false;
        }

        // Join with \n and ensure exactly one trailing newline
        $payload = implode("\n", $lastLines);
        $payload = rtrim($payload, "\r\n") . "\n";

        $written = @fwrite($tmpHandle, $payload);
        if ($written === false) {
            @fclose($tmpHandle);
            @unlink($tmpPath);
            $this->releaseLock($lockHandle, $lockPath);
            return false;
        }

        // Flush and fsync to make sure data hits the disk before renaming
        @fflush($tmpHandle);
        $meta = stream_get_meta_data($tmpHandle);
        if (isset($meta['stream_type']) && function_exists('fsync')) {
            // fsync may not be available on all PHP builds; guard just in case
            @fsync($tmpHandle);
        }
        @fclose($tmpHandle);

        // Try to preserve perms/ownership of original file on the tmp file
        @chmod($tmpPath, $origPerm);
        if (function_exists('chown') && $origUid !== null) {
            @chown($tmpPath, $origUid);
        }
        if (function_exists('chgrp') && $origGid !== null) {
            @chgrp($tmpPath, $origGid);
        }

        // 4) Atomic swap: rename temp over original
        //    On POSIX, rename() is atomic when source and destination are on the same filesystem.
        $renamed = @rename($tmpPath, $filename);
        if (!$renamed) {
            // On Windows, rename over existing may fail; try unlink+rename (non-atomic on Windows)
            @unlink($filename);
            $renamed = @rename($tmpPath, $filename);
        }

        if (!$renamed) {
            // Cleanup tmp and release lock
            @unlink($tmpPath);
            $this->releaseLock($lockHandle, $lockPath);
            return false;
        }

        // 5) Release lock
        $this->releaseLock($lockHandle, $lockPath);

        return true;
    }

    /**
     * Read the last $maxLines lines of a text file by scanning backwards.
     * Returns an array of lines in chronological order (oldest → newest).
     *
     * Memory is bounded by the total size of those last $maxLines lines.
     *
     * @param string $filename
     * @param int    $maxLines
     * @return array<string>|null  null on failure
     */
    protected function readLastLines(string $filename, int $maxLines): ?array
    {
        $fh = @fopen($filename, 'rb');
        if (!$fh) {
            return null;
        }

        $filesize = @filesize($filename);
        if ($filesize === false) {
            @fclose($fh);
            return null;
        }

        $pos = $filesize;
        $buffer = '';
        $lines  = [];
        $lineCount = 0;

        // Walk backwards until we collected enough lines
        while ($pos > 0 && $lineCount < $maxLines) {
            $read = min($this->chunkSize, $pos);
            $pos -= $read;

            if (@fseek($fh, $pos, SEEK_SET) !== 0) {
                break;
            }
            $chunk = @fread($fh, $read);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;

            // Split into lines
            $parts = explode("\n", $buffer);
            $buffer = array_shift($parts); // possibly incomplete start

            // Process complete lines from the end
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $line = rtrim($parts[$i], "\r");
                array_unshift($lines, $line);
                $lineCount++;
                if ($lineCount >= $maxLines) {
                    break 2;
                }
            }
        }

        // Include any remaining (very beginning of file) partial line
        if ($lineCount < $maxLines && $buffer !== '') {
            array_unshift($lines, rtrim($buffer, "\r"));
        }

        @fclose($fh);

        // Keep only the last $maxLines if we overshot
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return $lines;
    }

    /**
     * Ensure the file ends with a newline (useful for tools that assume POSIX text).
     */
    protected function ensureTrailingNewline(string $filename): bool
    {
        $fh = @fopen($filename, 'c+b');
        if (!$fh) {
            return false;
        }
        $size = @filesize($filename);
        if ($size === false || $size === 0) {
            // write a single newline if empty
            $ok = @fwrite($fh, "\n") !== false;
            @fclose($fh);
            return $ok;
        }

        if (@fseek($fh, -1, SEEK_END) === 0) {
            $last = @fread($fh, 1);
            if ($last !== "\n") {
                @fseek($fh, 0, SEEK_END);
                @fwrite($fh, "\n");
            }
        }
        @fclose($fh);
        return true;
        }

    /**
     * Release and cleanup the lock file.
     */
    protected function releaseLock($handle, string $lockPath): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        // Keeping the empty .lock file is harmless; remove if you prefer:
        // @unlink($lockPath);
    }
}