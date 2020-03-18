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
            $this->file->seek($this->file->getSize());
            $this->totalLines = $this->file->key();
        }
    }
 
    protected function iterateFile()
    {
        $count = 0;
        while (!$this->file->eof()) {
            yield $this->file->fgets();
            $count++;
        }
        return $count;
    }
 
    public function iterate()
    {
        if (is_wp_error($this->error)) {
            return $this->error;
        }
        return new \LimitIterator($this->iterateFile(), $this->offset, $this->count);
    }

    public function getTotalLines()
    {
        return $this->totalLines;
    }
}
