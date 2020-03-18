<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_Error;

class LogParser
{
    protected $error = null;

    protected $file = null;

    protected $offset;

    protected $count;

    protected $totalLines = 0;
 
    public function __construct($filename, $offset = 0, $count = -1)
    {
        $this->offset = $offset;
        $this->count = $count;

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
            yield $line;
            $this->totalLines++;
        }
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
        $lines = new \ArrayIterator($lines);
        return new \LimitIterator($lines, $this->offset, $this->count);
    }

    public function getTotalLines()
    {
        return $this->totalLines;
    }
}
