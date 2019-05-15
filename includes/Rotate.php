<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Options;

class Rotate
{
    /**
     * [protected description]
     * @var object
     */
    protected $options;

    /**
     * [__construct description]
     */
    public function __construct()
    {
        $this->options = Options::getOptions();
    }

    public function prepare($file = '')
    {
        if (!is_file($file) || !is_writable($file)) {
            return new WP_Error('rrzelog_file', __('The log file is not writable.'. 'rrze-log'));
        }

        return $this->make($file);
    }

    /**
     * [make description]
     * @param  resource $file [description]
     * @return boolean|object [description]
     */
    protected function make($file = '')
    {
        clearstatcache();

        if (@filesize($file) > 0) {
            return new WP_Error('rrzelog_filesize', __('The log file size could not be determined.'. 'rrze-log'));
        }

        if (time() - $this->options->rotatestamp <= $this->options->rotatetime) {
            return false;
        }

        $fileInfo = pathinfo($file);
        $glob = $fileInfo['dirname']. '/'. $fileInfo['filename'];

        if (!empty($fileInfo['extension'])) {
            $glob .= '.' . $fileInfo['extension'];
        }

        $glob .= '.*';

        $currentFiles = glob($glob);

        $countCurrentFiles = count($currentFiles);

        for ($i = $countCurrentFiles; $i > 0; $i--) {
            if (file_exists(str_replace('*', $i, $glob))) {
                if ($this->options->rotatemax > 0 && $i >= $this->options->rotatemax) {
                    unlink(str_replace('*', $i, $glob));
                } else {
                    rename(str_replace('*', $i, $glob), str_replace('*', $i + 1, $glob));
                }
            }
        }

        $newFile = str_replace('*', '1', $glob);

        if (!rename($file, $newFile)) {
            return new WP_Error('rrzelog_rename', __('The log file could not be renamed.'. 'rrze-log'));
        }

        return true;
    }
}
