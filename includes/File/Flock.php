<?php

namespace RRZE\Log\File;

defined('ABSPATH') || exit;

use RRZE\Log\File\FlockException;

class Flock
{
    /**
     * [protected description]
     * @var boolean
     */
    protected $locked;

    /**
     * [public description]
     * @var resource
     */
    public $fp;

    /**
     * [protected description]
     * @var string
     */
    protected $filePath;

    /**
     * [__construct description]
     * @param string $filePath [description]
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
        $this->locked = false;
        $this->fp = null;
    }

    /**
     * [__destruct description]
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * [acquire description]
     * @return object $this
     */
    public function acquire()
    {
        if (!$this->locked) {
            $this->fp = @fopen($this->filePath, 'a');

            if (!$this->fp || !flock($this->fp, LOCK_EX | LOCK_NB)) {
                throw new FlockException(sprintf(__('Could not get lock on %s', 'rrze-log'), $this->filePath));
            } else {
                $this->locked = true;
            }
        }

        return $this;
    }

    /**
     * [release description]
     * @return object $this
     */
    public function release()
    {
        if ($this->locked) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);

            $this->fp = null;
            $this->locked = false;
        }

        return $this;
    }
}
