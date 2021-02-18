<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_Error;

class LogParser
{
    /**
     * [protected description]
     * @var [type]
     */
    protected $error = null;

    /**
     * [protected description]
     * @var [type]
     */
    protected $file = null;

    /**
     * [protected description]
     * @var array
     */
    protected $search;

    /**
     * [protected description]
     * @var integer
     */
    protected $offset;

    /**
     * [protected description]
     * @var integer
     */
    protected $count;

    /**
     * [protected description]
     * @var integer
     */
    protected $totalLines = 0;

    /**
     * [__construct description]
     * @param string  $filename [description]
     * @param array   $search   [description]
     * @param integer $offset   [description]
     * @param integer $count    [description]
     */
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

    /**
     * [iterateFile description]
     */
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

    /**
     * [search description]
     * @param  string $haystack [description]
     * @return boolean           [description]
     */
    protected function search($haystack)
    {
        $find = true;
        foreach ($this->search as $needle) {
            if (is_array($needle) && !empty($needle)) {
                foreach ($needle as $str) {
                    if (strpos($haystack, $str) === false) {
                        $find = $find && false;
                    } else {
                        $find = $find && true;
                    }
                }
            } else {
                if (strpos($haystack, $needle) === false) {
                    $find = $find && false;
                }
            }
        }
        return $find;
    }

    /**
     * [iterate description]
     * @return object \NoRewindIterator()
     */
    protected function iterate()
    {
        return new \NoRewindIterator($this->iterateFile());
    }

    /**
     * [getItems description]
     * @param  string $key    [description]
     * @param  string $search [description]
     * @return object         \LimitIterator()
     */
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

    /**
     * [getTotalLines description]
     * @return integer [description]
     */
    public function getTotalLines()
    {
        return $this->totalLines;
    }
}
