<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

/**
 * DebugLogParser (hybrid: fast tail-chunk by default, full reverse-streaming on demand)
 *
 * Modes:
 * - Tail-chunk (default): read only the last $tailBytes of the file and parse via a single
 *   preg_split on timestamp lines. Very fast thanks to native string ops. Good when you
 *   only care about the latest entries.
 * - Full reverse-streaming ($full = true): read the whole file from end to start using
 *   fseek() + fixed-size chunks. Reconstruct entries (including stack traces) and group
 *   them. Early-exit once we have enough groups to satisfy offset+count (when count>=0).
 *
 * Public API:
 * - getItems(): returns an iterator over grouped/normalized entries (newest → oldest)
 * - getTotalLines(): number of items after filtering (for the last getItems() call)
 */
class DebugLogParser
{
    /** @var \WP_Error|null Constructor error holder */
    protected $error = null;

    /** @var \SplFileObject|null File handle */
    protected $file = null;

    /** @var array Lowercased search terms (AND semantics; nested arrays also AND) */
    protected $search;

    /** @var int Pagination offset */
    protected $offset;

    /** @var int Pagination count (-1 = unlimited) */
    protected $count;

    /** @var int Total number of items after filtering, for the last getItems() */
    protected $totalLines = 0;

    /** @var string Regex matching timestamp lines like "[... UTC] ..." */
    protected string $tsRegex = '/^\[(.+?UTC)\]\s?(.*)$/';

    /** @var int Chunk size (bytes) for reverse reading */
    protected int $chunkSize = 8192;

    /** @var bool Use fast tail-chunk mode by default */
    protected bool $useTailChunk = true;

    /** @var int Bytes to read from the file tail in fast mode (default 10 MB) */
    protected int $tailBytes = 104857600; // 10 * 1024 * 1024

    /**
     * Constructor.
     *
     * @param string   $filename Absolute path to the debug log.
     * @param array    $search   Search terms (case-insensitive). AND semantics; nested arrays also AND.
     * @param int      $offset   Pagination offset.
     * @param int      $count    Pagination count (-1 = unlimited).
     * @param bool     $useTailChunk Whether to use tail-chunk mode (default: true).
     * @param int|null $tailBytes    Custom tail size in bytes (null = default 10 MB).
     */
    public function __construct($filename, $search = [], $offset = 0, $count = -1, bool $useTailChunk = false, ?int $tailBytes = null)
    {
        $this->offset = max(0, (int) $offset);
        $this->count  = (int) $count;
        $this->useTailChunk  = $useTailChunk;

        if ($tailBytes !== null && $tailBytes > 0) {
            $this->tailBytes = $tailBytes;
        }

        $search = array_map('mb_strtolower', (array) $search);
        $this->search = array_filter($search, static fn($v) => $v !== '' && $v !== null);

        if (!file_exists($filename)) {
            $this->error = new \WP_Error('rrze_log_file', __('Log file not found.', 'rrze-log'));
            return;
        }

        try {
            $this->file = new \SplFileObject($filename, 'rb');
        } catch (\Throwable $e) {
            $this->error = new \WP_Error(
                'rrze_log_file',
                sprintf(
                    /* translators: %s: error message */
                    __('Cannot open log: %s', 'rrze-log'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Public API: returns an iterator with grouped/normalized log entries (newest → oldest).
     *
     * Item shape:
     * [
     *   'level'       => 'FATAL'|'WARNING'|'NOTICE'|'DEPRECATED'|'PARSE'|'EXCEPTION'|'DATABASE'|'JAVASCRIPT'|'OTHER',
     *   'message'     => string      // first segment before "@@@"
     *   'datetime'    => string      // newest occurrence timestamp
     *   'details'     => string[]    // message split by '@@@' markers
     *   'occurrences' => int         // number of timestamps grouped under the same 'details'
     * ]
     *
     * @return \Iterator|\WP_Error
     */
    public function getItems()
    {
        if (is_wp_error($this->error)) {
            return $this->error;
        }

        $groupsNewestFirst = $this->useTailChunk
            ? $this->parseAndGroupReverseWithEarlyExit()
            : $this->parseTailChunk();

        // Build rows and apply search filter
        $rows = [];
        foreach ($groupsNewestFirst as $entry) {
            $detailsArr = explode('@@@', $entry['details']);
            $row = [
                'level'       => $entry['level'],
                'message'     => $detailsArr[0],
                'datetime'    => $entry['occurrences'][0], // newest
                'details'     => $detailsArr,
                'occurrences' => count($entry['occurrences']),
            ];

            $searchStr = json_encode($row);
            if (!$this->search || $this->matchesSearch($searchStr)) {
                $rows[] = $row;
            }
        }

        $this->totalLines = count($rows);

        // Pagination
        if ($this->totalLines >= $this->offset) {
            $slice = ($this->count >= 0)
                ? array_slice($rows, $this->offset, $this->count)
                : array_slice($rows, $this->offset);
            return new \LimitIterator(new \ArrayIterator($slice), 0, -1);
        }

        return new \LimitIterator(new \ArrayIterator([]));
    }

    /**
     * Returns the total number of filtered items (for the last getItems()).
     */
    public function getTotalLines()
    {
        return (int) $this->totalLines;
    }

    /* =========================== FAST MODE (TAIL-CHUNK) =========================== */

    /**
     * Fast path: read only the last $tailBytes of the file and parse with preg_split.
     * Newest entries come last in the file; we reverse groups to get newest → oldest.
     *
     * @return array Grouped entries (newest → oldest)
     */
    protected function parseTailChunk(): array
    {
        $fh = $this->file;
        if (!is_object($fh)) {
            return [];
        }

        $size = (int) ($fh->fstat()['size'] ?? 0);
        if ($size <= 0) {
            return [];
        }

        // Read only the tail (or the whole file if smaller than tailBytes)
        if ($size > $this->tailBytes) {
            $fh->fseek($size - $this->tailBytes);
            $content = $fh->fread($this->tailBytes);

            // Align to next timestamp to avoid starting mid-entry
            if (preg_match('/^\[(.+?UTC)\]\s/m', $content, $m, PREG_OFFSET_CAPTURE)) {
                $offset = $m[0][1];
                if ($offset > 0) {
                    $content = substr($content, $offset);
                }
            }
        } else {
            $fh->fseek(0);
            $content = $fh->fread($size);
        }

        if ($content === '' || $content === false) {
            return [];
        }

        // Split by timestamp. Capturing group retains timestamps.
        $pattern = '/^\[(.*UTC)\]\s/mi';
        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        // Pair [timestamp, message] sequentially
        $groups = []; // details => group
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $timestamp = $parts[$i];
            $message   = $parts[$i + 1];
            if ($timestamp === '' || $message === '' || $timestamp === null || $message === null) {
                continue;
            }

            $normalized = $this->normalizeEntryMessage($message);
            [$level, $details] = $this->classifyAndExtractDetails($normalized);
            $details = trim(preg_replace('/([\r\n\t])/', '', wp_kses_post($details)));

            if (!isset($groups[$details])) {
                $groups[$details] = [
                    'level'       => $level,
                    'details'     => $details,
                    'occurrences' => [$timestamp], // chronological as we parse forward in this chunk
                ];
            } else {
                $groups[$details]['occurrences'][] = $timestamp;
            }
        }

        // Latest entries are at the end of the chunk, so reverse to newest → oldest
        $groups = array_reverse($groups, true);

        return array_values($groups);
    }

    /* =========================== FULL MODE (REVERSE STREAMING) =========================== */

    /**
     * Full-file reverse streaming with early-exit.
     * Walks the file from the end, reconstructs entries, normalizes, classifies, and groups.
     * If count>=0, stops as soon as we have (offset+count) groups (newest-first), to minimize I/O.
     *
     * @return array Grouped entries (newest → oldest)
     */
    protected function parseAndGroupReverseWithEarlyExit(): array
    {
        $fh = $this->file;
        if (!is_object($fh)) {
            return [];
        }

        $size = (int) ($fh->fstat()['size'] ?? 0);
        if ($size <= 0) {
            return [];
        }

        $target = ($this->count >= 0) ? ($this->offset + $this->count) : PHP_INT_MAX;

        $pos = $size;
        $buffer = '';
        $currentEntryLines = []; // newest→oldest lines between timestamp headers

        $groups = []; // details => group
        $order  = []; // newest-first order of details keys

        while ($pos > 0) {
            // If we already have enough newest groups to satisfy pagination, stop early
            if (count($order) >= $target) {
                break;
            }

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
                    $currentEntryLines[] = $line;
                    continue;
                }

                if (preg_match($this->tsRegex, $line, $m)) {
                    $timestamp = $m[1] ?? '';
                    $inlineMsg = $m[2] ?? '';
                    $body = implode("\n", array_reverse($currentEntryLines));
                    $fullMessage = ($inlineMsg !== '')
                        ? $inlineMsg . ($body !== '' ? "\n" . $body : '')
                        : $body;

                    $this->finalizeGroupEntry($timestamp, $fullMessage, $groups, $order);

                    $currentEntryLines = [];
                } else {
                    $currentEntryLines[] = $line;
                }
            }
        }

        // Beginning of file
        if ($buffer !== '' && count($order) < $target) {
            $line = rtrim($buffer, "\r");
            if (preg_match($this->tsRegex, $line, $m)) {
                $timestamp = $m[1] ?? '';
                $inlineMsg = $m[2] ?? '';
                $body = implode("\n", array_reverse($currentEntryLines));
                $fullMessage = ($inlineMsg !== '')
                    ? $inlineMsg . ($body !== '' ? "\n" . $body : '')
                    : $body;

                $this->finalizeGroupEntry($timestamp, $fullMessage, $groups, $order);
            }
        }

        // Build newest → oldest group list from $order
        $result = [];
        foreach ($order as $detailsKey) {
            $result[] = $groups[$detailsKey];
        }
        return $result;
    }

    /**
     * Normalize, classify and group a single entry (used by full reverse mode).
     * Keeps newest-first order of groups via $order (array of details keys).
     */
    protected function finalizeGroupEntry(string $timestamp, string $fullMessage, array &$groups, array &$order): void
    {
        $normalized = $this->normalizeEntryMessage($fullMessage);
        [$level, $details] = $this->classifyAndExtractDetails($normalized);
        $details = trim(preg_replace('/([\r\n\t])/', '', wp_kses_post($details)));

        if (!isset($groups[$details])) {
            $groups[$details] = [
                'level'       => $level,
                'details'     => $details,
                'occurrences' => [$timestamp], // newest first
            ];
            array_unshift($order, $details);
        } else {
            $groups[$details]['occurrences'][] = $timestamp; // older appended at the end
        }
    }

    /* =========================== SHARED UTILS =========================== */

    /**
     * Case-insensitive AND-search (nested arrays also AND).
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
     * Normalize a raw message: hide absolute paths and add "@@@" markers for UI splitting.
     */
    protected function normalizeEntryMessage(string $message): string
    {
        if (defined('ABSPATH')) {
            $message = str_replace(ABSPATH, ".../", $message);
        }
        $message = str_replace("Stack trace:", "@@@Stack trace:", $message);
        if (strpos($message, 'PHP Fatal') !== false) {
            $message = str_replace("#", "@@@#", $message);
        }
        $message = str_replace("Argument @@@#", "Argument #", $message);
        $message = str_replace("parameter @@@#", "parameter #", $message);
        $message = str_replace("the @@@#", "the #", $message);
        return $message;
    }

    /**
     * Severity classification + detail extraction by stripping prefixes.
     *
     * @return array [ level, details ]
     */
    protected function classifyAndExtractDetails(string $error): array
    {
        if ((false !== strpos($error, 'PHP Fatal')) || (false !== strpos($error, 'FATAL')) || (false !== strpos($error, 'E_ERROR'))) {
            return ['FATAL', str_replace(["PHP Fatal error: ", "PHP Fatal: ", "FATAL ", "E_ERROR: "], "", $error)];
        } elseif ((false !== strpos($error, 'PHP Warning')) || (false !== strpos($error, 'E_WARNING'))) {
            return ['WARNING', str_replace(["PHP Warning: ", "E_WARNING: "], "", $error)];
        } elseif ((false !== strpos($error, 'PHP Notice')) || (false !== strpos($error, 'E_NOTICE'))) {
            return ['NOTICE', str_replace(["PHP Notice: ", "E_NOTICE: "], "", $error)];
        } elseif (false !== strpos($error, 'PHP Deprecated')) {
            return ['DEPRECATED', str_replace(["PHP Deprecated: "], "", $error)];
        } elseif ((false !== strpos($error, 'PHP Parse')) || (false !== strpos($error, 'E_PARSE'))) {
            return ['PARSE', str_replace(["PHP Parse error: ", "E_PARSE: "], "", $error)];
        } elseif (false !== strpos($error, 'EXCEPTION:')) {
            return ['EXCEPTION', str_replace(["EXCEPTION: "], "", $error)];
        } elseif (false !== strpos($error, 'WordPress database error')) {
            return ['DATABASE', str_replace(["WordPress database error "], "", $error)];
        } elseif (false !== strpos($error, 'JavaScript Error')) {
            return ['JAVASCRIPT', str_replace(["JavaScript Error: "], "", $error)];
        } else {
            // Pretty-print JSON if Utils::isJson exists
            $details = $error;
            $utils = __NAMESPACE__ . '\Utils';
            if (class_exists($utils) && method_exists($utils, 'isJson') && call_user_func([$utils, 'isJson'], $details)) {
                $details = print_r(json_decode($details, true), true);
            }
            return ['OTHER', $details];
        }
    }
}
