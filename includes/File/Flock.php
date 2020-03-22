<?php

namespace RRZE\Log\File;

use RRZE\Log\File\FlockException;

class Flock
{
    /**
     * Whether or not we currently have a locked file.
     * @var boolean
     */
    protected $locked;

    /**
     * The resource being wrapped by this lock.
     * @var resource
     */
    public $fp;

    /**
     * The path to the file to lock.
     * @var string
     */
    protected $filePath;

    /**
     * [__construct description]
     * @param string $filePath Path to file
     */
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
        $this->locked = false;
        $this->fp = null;
    }

    /**
     * Release the lock on the file (if it is still locked).
     * @return void
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * Acquire a lock on the file.
     * @return object $this, for chaining
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
     * Release the lock on the file.
     * @return object $this for chaining
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
