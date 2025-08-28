<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Efficient LogParser for large log files.
 *
 * Features:
 * - Lazy reading using SplFileObject and fseek()
 * - Supports offset + count (pagination)
 * - Filtering by search terms (case-insensitive)
 * - Optional JSON key/value filter
 * - Memory-efficient: only reads the required lines from the end of the file
 */
class LogParser
{
    /** @var WP_Error|null */
    protected $error = null;

    /** @var \SplFileObject|null */
    protected $file = null;

    /** @var array Lowercased search terms */
    protected $search;

    /** @var int Offset for pagination */
    protected $offset;

    /** @var int Number of lines to return (-1 = unlimited) */
    protected $count;

    /** @var int Cached number of matching lines */
    protected $totalLines = 0;

    /** @var int Chunk size (bytes) for reverse reading */
    protected int $chunkSize = 8192;

    /**
     * Constructor.
     *
     * @param string  $filename Path to log file.
     * @param array   $search   Array of search terms (case-insensitive).
     * @param int     $offset   Offset for pagination.
     * @param int     $count    Number of lines to return (-1 = unlimited).
     */
    public function __construct($filename, $search = [], $offset = 0, $count = -1)
    {
        $this->offset = max(0, (int) $offset);
        $this->count  = (int) $count; // -1 = unlimited
        $search = array_map('mb_strtolower', (array) $search);
        $this->search = array_filter($search, static fn($v) => $v !== '' && $v !== null);

        if (!file_exists($filename)) {
            $this->error = new WP_Error('rrze_log_file', __('Log file not found.', 'rrze-log'));
            return;
        }

        $this->file = new \SplFileObject($filename, 'rb');
        $this->file->setFlags(
            \SplFileObject::READ_AHEAD
                | \SplFileObject::SKIP_EMPTY
                | \SplFileObject::DROP_NEW_LINE // avoid trailing "\n"
        );
    }

    /**
     * Forward iterator: yields lines sequentially from the beginning.
     * Used when no count limit is set (count = -1).
     */
    protected function iterateFile()
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            if ($line === '' || $line === false) {
                continue;
            }
            if (!$this->search || $this->matchesSearch($line)) {
                yield $line;
            }
        }
    }

    /**
     * Case-insensitive search filter.
     * Returns true if all search terms are present in the line.
     *
     * @param string $haystack Line to check.
     * @return bool
     */
    protected function matchesSearch(string $haystack): bool
    {
        $haystack = mb_strtolower($haystack);
        foreach ($this->search as $needle) {
            if (is_array($needle) && !empty($needle)) {
                foreach ($needle as $str) {
                    if (mb_stripos($haystack, $str) === false) {
                        return false;
                    }
                }
            } else {
                if (mb_stripos($haystack, (string) $needle) === false) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Reverse reader: efficiently reads from the end of the file.
     *
     * @param int         $limit        Number of lines to return.
     * @param int         $skip         Number of lines to skip.
     * @param string|null $key          Optional JSON key for exact match.
     * @param string|null $searchExact  Optional exact value for the JSON key.
     * @return string[]   Lines in chronological order (old → new).
     */
    protected function tailSlice(int $limit, int $skip = 0, ?string $key = null, ?string $searchExact = null): array
    {
        $fh = $this->file;
        $meta = $fh->fstat();
        $size = (int) ($meta['size'] ?? 0);
        if ($size <= 0) {
            return [];
        }

        $useKeyFilter = ($key && $searchExact !== null && $searchExact !== '');
        $searchExact  = $useKeyFilter ? untrailingslashit(mb_strtolower($searchExact)) : null;

        $pos = $size;
        $buffer = '';
        $collected = []; // newest → oldest
        $need = ($limit < 0) ? PHP_INT_MAX : ($skip + $limit);

        while ($pos > 0 && count($collected) < $need) {
            $read = min($this->chunkSize, $pos);
            $pos -= $read;

            $fh->fseek($pos);
            $chunk = $fh->fread($read);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            $parts = explode("\n", $buffer);
            $buffer = array_shift($parts);

            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $line = rtrim($parts[$i], "\r");
                if ($line === '') {
                    continue;
                }

                // Apply text search
                if ($this->search && !$this->matchesSearch($line)) {
                    continue;
                }

                // Apply JSON key filter
                if ($useKeyFilter) {
                    $obj = json_decode($line);
                    if (!$obj || !isset($obj->{$key})) {
                        continue;
                    }
                    $val = mb_strtolower(untrailingslashit((string) $obj->{$key}));
                    if ($val !== $searchExact) {
                        continue;
                    }
                }

                $collected[] = $line;
                if (count($collected) >= $need) {
                    break;
                }
            }
        }

        // Handle remaining buffer (beginning of file)
        if ($buffer !== '' && count($collected) < $need) {
            $line = rtrim($buffer, "\r");
            if ($line !== '') {
                if ((!$this->search || $this->matchesSearch($line))) {
                    if ($useKeyFilter) {
                        $obj = json_decode($line);
                        if ($obj && isset($obj->{$key})) {
                            $val = mb_strtolower(untrailingslashit((string) $obj->{$key}));
                            if ($val === $searchExact) {
                                $collected[] = $line;
                            }
                        }
                    } else {
                        $collected[] = $line;
                    }
                }
            }
        }

        // Reverse to chronological order (oldest → newest)
        $collected = array_reverse($collected);

        // Apply offset and limit
        if ($skip > 0) {
            $collected = array_slice($collected, $skip);
        }
        if ($limit >= 0) {
            $collected = array_slice($collected, 0, $limit);
        }

        return $collected;
    }

    /**
     * Public API: returns a LimitIterator with log lines.
     *
     * @param string $key    Optional JSON key filter.
     * @param string $search Optional exact value for the JSON key.
     * @return \Iterator|WP_Error
     */
    public function getItems($key = '', $search = '')
    {
        if (is_wp_error($this->error)) {
            return $this->error;
        }

        $key = (string) $key;
        $search = mb_strtolower((string) $search);

        // Optimized path: count >= 0
        if ($this->count >= 0) {
            $slice = $this->tailSlice($this->count, $this->offset, $key ?: null, $search ?: null);
            $this->totalLines = $this->offset + count($slice); // at least this many
            return new \LimitIterator(new \ArrayIterator($slice), 0, -1);
        }

        // Fallback: read all (count = -1)
        $buffer = [];
        foreach ($this->iterateFile() as $line) {
            if ($key && $search) {
                $lineObj = json_decode($line);
                $value = isset($lineObj->$key) ? mb_strtolower(untrailingslashit((string) $lineObj->$key)) : '';
                if ($value === '' || $value !== untrailingslashit($search)) {
                    continue;
                }
            }
            $buffer[] = $line;
        }

        $this->totalLines = count($buffer);

        // Reverse for "newest first" then apply offset
        $buffer = array_reverse($buffer, false);

        if (count($buffer) >= $this->offset) {
            $buffer = array_slice($buffer, $this->offset);
            return new \LimitIterator(new \ArrayIterator($buffer), 0, -1);
        }

        return new \LimitIterator(new \ArrayIterator([]));
    }

    /**
     * Returns the total number of lines found.
     *
     * @return int
     */
    public function getTotalLines()
    {
        return (int) $this->totalLines;
    }
}
