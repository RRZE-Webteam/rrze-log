<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_Error;

class LogParser
{
    protected $error = null;

    protected $file = null;

    protected $search;

    protected $offset;

    protected $count;

    protected $totalLines = 0;

    public function __construct($filename, $search = [], $offset = 0, $count = -1)
    {
        $this->offset = $offset;
        $this->count = $count;
        $this->search = $search;

        if (!file_exists($filename)) {
            $this->error = new WP_Error('rrze_log_file', __('Log file not found.', 'rrze-log'));
        } else {
            $this->file = new \SplFileObject($filename);
            $this->file->setFlags(
                \SplFileObject::READ_AHEAD |
                \SplFileObject::SKIP_EMPTY
            );
        }
    }

    protected function iterateFile()
    {
        while (!$this->file->eof()) {
            $line = $this->file->fgets();
            if (!empty($line) && (!$this->search || $this->search($line))) {
                yield $line;
                $this->totalLines++;
            }
        }
    }

    protected function search($haystack) {
        $find = true;
        foreach ($this->search as $str) {
            if (is_array($str) && !empty($str)) {
                foreach ($str as $v) {
                    if(strpos($haystack, $v) === false) {
                        $find = $find && false;
                    } else {
                        $find = $find && true;
                    }
                }
            } else {
                if(strpos($haystack, $str) === false) {
                    $find = $find && false;
                }
            }
        }
        return $find;
    }

    public function iterate()
    {
        return new \NoRewindIterator($this->iterateFile());
    }

    public function getItems($key = '', $search = '')
    {
        if (is_wp_error($this->error)) {
            return $this->error;
        }
        $lines = [];
        foreach ($this->iterateFile() as $line) {
            $lines[] = $line;
        }
        if (count($lines) >= $this->offset) {
            krsort($lines);
            $limitIterator = new \LimitIterator(new \ArrayIterator($lines), $this->offset, $this->count);
        } else {
            $limitIterator = new \LimitIterator(new \ArrayIterator([]));
        }
        return $limitIterator;
    }

    public function getTotalLines()
    {
        return $this->totalLines;
    }
}
