<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Efficient LogParser for large log files.
 *
 * Modes:
 * - Tail-chunk (default): read only the last $tailBytes of the file, then split/filter/page.
 *   Very fast thanks to native string ops; great when you only care about recent lines.
 * - Reverse streaming: read from the end with fseek() in chunks; memory-stable for huge files.
 *
 * Features:
 * - Offset + count (pagination)
 * - Case-insensitive search terms (AND semantics; nested arrays also AND)
 * - Optional JSON key/value filter (per-line)
 * - Memory efficient
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

    /** @var bool Use fast tail-chunk mode by default */
    protected bool $useTailChunk = true;

    /** @var int Bytes to read from the end in tail-chunk mode (default 10 MB) */
    protected int $tailBytes = 10485760; // 10 * 1024 * 1024

    /**
     * Constructor.
     *
     * @param string     $filename     Path to log file.
     * @param array      $search       Array of search terms (case-insensitive).
     * @param int        $offset       Offset for pagination.
     * @param int        $count        Number of lines to return (-1 = unlimited).
     * @param bool       $useTailChunk Whether to use tail-chunk mode (default: true).
     * @param int|null   $tailBytes    Custom tail size in bytes (null = default 10 MB).
     */
    public function __construct($filename, $search = [], $offset = 0, $count = -1, bool $useTailChunk = true, ?int $tailBytes = null)
    {
        $this->offset = max(0, (int) $offset);
        $this->count  = (int) $count; // -1 = unlimited
        $this->useTailChunk = $useTailChunk;
        if ($tailBytes !== null && $tailBytes > 0) {
            $this->tailBytes = $tailBytes;
        }

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
     * Used when no count limit is set (count = -1) and tail-chunk mode is disabled.
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
     * FAST PATH: read only the last $tailBytes and paginate there.
     * Returns lines in NEW → OLD order.
     * 
     * @param int         $limit      Number of lines to return (-1 = unlimited).
     * @param int         $skip       Number of lines to skip (offset).
     * @param string|null $key        Optional JSON key to filter by.
     * @param string|null $searchExact Optional exact value for the JSON key.
     * @return array
     */
    protected function tailChunkSlice(int $limit, int $skip = 0, ?string $key = null, ?string $searchExact = null): array
    {
        $fh = $this->file;
        $stat = $fh->fstat();
        $size = (int) ($stat['size'] ?? 0);
        if ($size <= 0) {
            $this->totalLines = 0;
            return [];
        }

        // Read tail (or whole file if smaller)
        $readBytes = min($this->tailBytes, $size);
        $start = $size - $readBytes;
        $fh->fseek($start);
        $content = $fh->fread($readBytes);
        if ($content === '' || $content === false) {
            $this->totalLines = 0;
            return [];
        }

        // Drop partial first line if we didn't start at 0
        if ($start > 0) {
            $pos = strpos($content, "\n");
            if ($pos !== false) {
                $content = substr($content, $pos + 1);
            }
        }

        // Split into lines (file order: old → new)
        $lines = explode("\n", rtrim($content, "\n"));

        $useKeyFilter = ($key && $searchExact !== null && $searchExact !== '');
        $searchExact  = $useKeyFilter ? untrailingslashit(mb_strtolower($searchExact)) : null;

        // Filter (text + optional JSON key/value)
        $filtered = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if ($this->search && !$this->matchesSearch($line)) {
                continue;
            }
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
            $filtered[] = $line;
        }

        // Total within the tail window
        $this->totalLines = count($filtered);

        // Reverse to NEW → OLD before paginating
        $filtered = array_reverse($filtered, false);

        // Apply pagination (offset from newest)
        if ($skip > 0) {
            $filtered = array_slice($filtered, $skip);
        }
        if ($limit >= 0) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        return $filtered; // new → old
    }

    /**
     * Reverse reader from end of file.
     * Returns lines in NEW → OLD order.
     * 
     * @param int         $limit      Number of lines to return (-1 = unlimited).
     * @param int         $skip       Number of lines to skip (offset).
     * @param string|null $key        Optional JSON key to filter by.
     * @param string|null $searchExact Optional exact value for the JSON key.
     * @return array
     */
    protected function tailSlice(int $limit, int $skip = 0, ?string $key = null, ?string $searchExact = null): array
    {
        $fh = $this->file;
        $meta = $fh->fstat();
        $size = (int) ($meta['size'] ?? 0);
        if ($size <= 0) {
            $this->totalLines = 0;
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

                if ($this->search && !$this->matchesSearch($line)) {
                    continue;
                }

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

                $collected[] = $line; // newest → oldest
                if (count($collected) >= $need) {
                    break;
                }
            }
        }

        // Remaining buffer (start of file)
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

        // 👉 Keep NEW → OLD (no reverse)
        // Lower-bound total for UX
        $this->totalLines = $this->offset + min(($limit >= 0 ? $limit : count($collected)), count($collected));

        // Apply pagination (offset from newest)
        if ($skip > 0) {
            $collected = array_slice($collected, $skip);
        }
        if ($limit >= 0) {
            $collected = array_slice($collected, 0, $limit);
        }

        return $collected; // new → old
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

        // FAST: tail-chunk mode (default). Pages/filtering happen within the tail window.
        if ($this->useTailChunk) {
            $slice = $this->tailChunkSlice($this->count, $this->offset, $key ?: null, $search ?: null);
            return new \LimitIterator(new \ArrayIterator($slice), 0, -1);
        }

        // ORIGINAL streaming behavior
        if ($this->count >= 0) {
            $slice = $this->tailSlice($this->count, $this->offset, $key ?: null, $search ?: null);
            return new \LimitIterator(new \ArrayIterator($slice), 0, -1);
        }

        // Fallback: read all (count = -1) from the beginning (can be heavy)
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

        // Reverse for "newest first" then apply offset (to mirror previous UI)
        $buffer = array_reverse($buffer, false);

        if (count($buffer) >= $this->offset) {
            $buffer = array_slice($buffer, $this->offset);
            return new \LimitIterator(new \ArrayIterator($buffer), 0, -1);
        }

        return new \LimitIterator(new \ArrayIterator([]));
    }

    /**
     * Returns the total number of lines found (within the current mode/window).
     */
    public function getTotalLines()
    {
        return (int) $this->totalLines;
    }
}
