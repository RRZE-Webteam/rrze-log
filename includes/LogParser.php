<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_Error;

class LogParser {
    /** @var WP_Error|null */
    protected $error = null;

    /** @var \SplFileObject|null */
    protected $file = null;

    /** @var array Lowercased search terms */
    protected array $search;

    /** @var int Offset for pagination */
    protected int $offset;

    /** @var int Number of lines to return (-1 = unlimited) */
    protected int $count;

    /** @var int Cached number of matching lines */
    protected int $totalLines = 0;

    /** @var int Chunk size (bytes) for reverse reading */
    protected int $chunkSize = 8192;

    /** @var bool Use fast tail-chunk mode by default */
    protected bool $useTailChunk = true;

    /** @var int Bytes to read from the end in tail-chunk mode (default 10 MB) */
    protected int $tailBytes = 10485760;

    public function __construct($filename, $search = [], $offset = 0, $count = -1, bool $useTailChunk = true, ?int $tailBytes = null) {
        $this->offset = max(0, (int) $offset);
        $this->count = (int) $count;
        $this->useTailChunk = $useTailChunk;

        if ($tailBytes !== null && $tailBytes > 0) {
            $this->tailBytes = $tailBytes;
        }

        $search = array_map('mb_strtolower', (array) $search);
        $this->search = array_values(array_filter($search, [$this, 'isNonEmptySearchTerm']));

        if (!file_exists($filename)) {
            $this->error = new WP_Error('rrze_log_file', __('Log file not found.', 'rrze-log'));
            return;
        }

        try {
            $this->file = new \SplFileObject($filename, 'rb');
            $this->file->setFlags(
                \SplFileObject::READ_AHEAD
                | \SplFileObject::SKIP_EMPTY
                | \SplFileObject::DROP_NEW_LINE
            );
        } catch (\Throwable $e) {
            $this->error = new WP_Error('rrze_log_file', $e->getMessage());
        }
    }

    public function getTotalLines(): int {
        return (int) $this->totalLines;
    }

    public function getItems(string $key = '', string $search = '') {
        if (is_wp_error($this->error)) {
            return $this->error;
        }

        $key = (string) $key;
        $search = mb_strtolower((string) $search);

        if ($this->useTailChunk) {
            $slice = $this->tailChunkSlice($this->count, $this->offset, $key !== '' ? $key : null, $search !== '' ? $search : null);
            return new \LimitIterator(new \ArrayIterator($slice), 0, -1);
        }

        if ($this->count >= 0) {
            $slice = $this->tailSlice($this->count, $this->offset, $key !== '' ? $key : null, $search !== '' ? $search : null);
            return new \LimitIterator(new \ArrayIterator($slice), 0, -1);
        }

        $buffer = [];
        foreach ($this->iterateFile() as $line) {
            if ($key !== '' && $search !== '') {
                $lineObj = json_decode($line);
                $value = isset($lineObj->$key) ? mb_strtolower(untrailingslashit((string) $lineObj->$key)) : '';
                if ($value === '' || $value !== untrailingslashit($search)) {
                    continue;
                }
            }
            $buffer[] = $line;
        }

        $this->totalLines = count($buffer);
        $buffer = array_reverse($buffer, false);

        if (count($buffer) >= $this->offset) {
            $buffer = array_slice($buffer, $this->offset);
            return new \LimitIterator(new \ArrayIterator($buffer), 0, -1);
        }

        return new \LimitIterator(new \ArrayIterator([]));
    }

    /**
     * Returns decoded JSON items (arrays). Invalid JSON lines are skipped.
     *
     * @param string $key Optional JSON key filter
     * @param string $search Optional exact value for JSON key filter
     * @return \Iterator|WP_Error
     */
    public function getItemsDecoded(string $key = '', string $search = '') {
        $it = $this->getItems($key, $search);
        if (is_wp_error($it)) {
            return $it;
        }

        $items = [];
        foreach ($it as $line) {
            $decoded = json_decode((string) $line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $items[] = $decoded;
        }

        return new \LimitIterator(new \ArrayIterator($items), 0, -1);
    }

    protected function isNonEmptySearchTerm($v): bool {
        if ($v === '' || $v === null) {
            return false;
        }
        return true;
    }

    protected function iterateFile() {
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

    protected function matchesSearch(string $haystack): bool {
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

    protected function tailChunkSlice(int $limit, int $skip = 0, ?string $key = null, ?string $searchExact = null): array {
        $fh = $this->file;
        $stat = $fh->fstat();
        $size = (int) ($stat['size'] ?? 0);
        if ($size <= 0) {
            $this->totalLines = 0;
            return [];
        }

        $readBytes = min($this->tailBytes, $size);
        $start = $size - $readBytes;

        $fh->fseek($start);
        $content = $fh->fread($readBytes);
        if ($content === '' || $content === false) {
            $this->totalLines = 0;
            return [];
        }

        if ($start > 0) {
            $pos = strpos($content, "\n");
            if ($pos !== false) {
                $content = substr($content, $pos + 1);
            }
        }

        $lines = explode("\n", rtrim($content, "\n"));

        $useKeyFilter = ($key && $searchExact !== null && $searchExact !== '');
        $searchExact = $useKeyFilter ? untrailingslashit(mb_strtolower($searchExact)) : null;

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

        $this->totalLines = count($filtered);
        $filtered = array_reverse($filtered, false);

        if ($skip > 0) {
            $filtered = array_slice($filtered, $skip);
        }
        if ($limit >= 0) {
            $filtered = array_slice($filtered, 0, $limit);
        }

        return $filtered;
    }

    protected function tailSlice(int $limit, int $skip = 0, ?string $key = null, ?string $searchExact = null): array {
        $fh = $this->file;
        $meta = $fh->fstat();
        $size = (int) ($meta['size'] ?? 0);
        if ($size <= 0) {
            $this->totalLines = 0;
            return [];
        }

        $useKeyFilter = ($key && $searchExact !== null && $searchExact !== '');
        $searchExact = $useKeyFilter ? untrailingslashit(mb_strtolower($searchExact)) : null;

        $pos = $size;
        $buffer = '';
        $collected = [];
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

                $collected[] = $line;
                if (count($collected) >= $need) {
                    break;
                }
            }
        }

        if ($buffer !== '' && count($collected) < $need) {
            $line = rtrim($buffer, "\r");
            if ($line !== '') {
                if (!$this->search || $this->matchesSearch($line)) {
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

        $this->totalLines = $this->offset + min(($limit >= 0 ? $limit : count($collected)), count($collected));

        if ($skip > 0) {
            $collected = array_slice($collected, $skip);
        }
        if ($limit >= 0) {
            $collected = array_slice($collected, 0, $limit);
        }

        return $collected;
    }
}