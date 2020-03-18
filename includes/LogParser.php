<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_Error;

class LogParser
{
    protected $error = false;

    protected $file;
 
    public function __construct($file, $mode = 'r')
    {
        if (!file_exists($file)) {
            $this->error = new WP_Error('rrze_log_file', __('Log file not found.', 'rrze-log'));
        } else {
            $this->file = new \SplFileObject($file, $mode);
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

        return new \NoRewindIterator($this->iterateFile());
    }
}
