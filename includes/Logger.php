<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Options;
use RRZE\Log\File\Flock;
use RRZE\Log\File\FlockException;

class Logger
{
    /**
     * [protected description]
     * @var string
     */
    protected $pluginFile;

    /**
     * [protected description]
     * @var object
     */
    protected $options;

    /**
     * [protected description]
     * @var string
     */
    protected $optionName;

    /**
     * [protected description]
     * @var string
     */
    protected $logPath;

    /**
     * [protected description]
     * @var string
     */
    protected $logFile;

    /**
     * [protected description]
     * @var integer
     */
    protected $currentTimeGmt;

    /**
     * [protected description]
     * @var integer|boolean
     */
    protected $enabled;

    /**
     * [protected description]
     * @var boolean
     */
    protected $funcOverload;

    /**
     * [protected description]
     * @var integer
     */
    protected $currentBlogId;

    /**
     * [protected description]
     * @var integer
     */
    protected $filePermissions = 0644;

    /**
     * [protected description]
     * @var array
     */
    protected $levels = ['ERROR' => 1, 'WARNING' => 2, 'NOTICE' => 4, 'INFO' => 8, 'DEBUG' => 16];

    /**
     * [protected description]
     * @var object
     */
    protected $rotate;

    /**
     * [LOG_DIR description]
     * @var string
     */
    const LOG_DIR = '__log';

    public function __construct($pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * [onLoaded description]
     */
    public function onLoaded()
    {
        $this->enabled = $this->options->enabled;
        if (!$this->enabled) {
            return false;
        }

        isset($this->funcOverload) || $this->funcOverload = (extension_loaded('mbstring') && ini_get('mbstring.func_overload'));

        $this->currentBlogId = get_current_blog_id();

        $this->logPath = plugin_dir_path($this->pluginFile) . static::LOG_DIR . DIRECTORY_SEPARATOR;
        $this->logFile = sprintf('%1$s%2$s.log', $this->logPath, date('Y-m-d'));

        $this->currentTimeGmt = current_time('timestamp', 1);

        //$this->parser();
    }
    
    public function error($message = '', $context = [])
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning($message = '', $context = [])
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice($message = '', $context = [])
    {
        $this->log('NOTICE', $message, $context);
    }
    
    public function info($message = '', $context = [])
    {
        $this->log('INFO', $message, $context);
    }
    
    public function debug($message = '', $context = [])
    {
        $this->log('DEBUG', $message, $context);
    }

    protected function log(string $level, string $message, array $context)
    {
        $data = [
            'datetime' => sprintf('%s UTC', date('Y-m-d H:i:s', $this->currentTimeGmt)),
            'blog_id' => $this->currentBlogId,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $this->write($data);
    }

    /**
     * [write description]
     * @param  array $data [description]
     * @return boolean          [description]
     */
    protected function write(array $data)
    {
        if (!$this->isLogPathWritable()) {
            return false;
        }
        
        $this->unlinkMaxLogFiles();

        $newFile = false;

        if (!file_exists($this->logFile)) {
            $newFile = true;
        }

        $flock = new Flock($this->logFile);

        try {
            $fp = $flock->acquire()->fp;
            $bytesWritten = $this->writeLine($fp, $data);
            $flock->release();
        } catch (FlockException $e) {
            return false;
        }

        if ($newFile) {
            chmod($this->logFile, $this->filePermissions);
        }

        return is_int($bytesWritten);
    }

    /**
     * [writeLine description]
     * @param  resource $fp      [description]
     * @param  string $level   [description]
     * @param  array $data [description]
     * @return integer          [description]
     */
    protected function writeLine($fp, $data)
    {
        $logData = json_encode($data) . PHP_EOL;

        $bytesWritten = 0;
        for ($written = 0, $length = $this->strLen($logData); $written < $length; $written += $bytesWritten) {
            if (($bytesWritten = fwrite($fp, $this->subStr($logData, $written))) === false) {
                break;
            }
        }
        
        return $bytesWritten;
    }

    /**
     * [isLogPathWritable description]
     * @return boolean [description]
     */
    protected function isLogPathWritable()
    {
        file_exists($this->logPath) || wp_mkdir_p($this->logPath);

        if (!is_dir($this->logPath) || !$this->isWritable($this->logPath)) {
            return false;
        }

        return true;
    }

    /**
     * [isWritable description]
     * @param  resource  $file [description]
     * @return boolean       [description]
     */
    protected function isWritable($file)
    {
        if (DIRECTORY_SEPARATOR === '/' && !ini_get('safe_mode')) {
            return is_writable($file);
        }

        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === false) {
                return false;
            }

            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return true;
        } elseif (!is_file($file) || ($fp = @fopen($file, 'ab')) === false) {
            return false;
        }

        fclose($fp);
        return true;
    }

    /**
     * [strLen description]
     * @param  string $str [description]
     * @return boolean      [description]
     */
    protected function strLen($str)
    {
        return ($this->funcOverload) ? mb_strlen($str, '8bit') : strlen($str);
    }

    /**
     * [subStr description]
     * @param  string $str    [description]
     * @param  integer $start  [description]
     * @param  integer $length [description]
     * @return string         [description]
     */
    protected function subStr($str, $start, $length = null)
    {
        if ($this->funcOverload) {
            return mb_substr($str, $start, $length, '8bit');
        }

        return isset($length) ? substr($str, $start, $length) : substr($str, $start);
    }

    protected function unlinkMaxLogFiles()
    {
        $logFiles = [];
        foreach (new \DirectoryIterator($this->logPath) as $file) {
            if ($file->isFile()) {
                $logFiles[$this->logPath . $file->getFilename()] = $file->getMTime();
            }
        }

        if (count($logFiles) <= $this->options->maxLogFiles) {
            return;
        }
        arsort($logFiles);
        $count = 1;
        foreach ($logFiles as $file => $time) {
            if (($count > $this->options->maxLogFiles) && ($this->logFile != $file)) {
                @unlink($file);
            }
            $count++;
        }
    }
        
    protected function parser() {
        $logParser = new LogParser($this->logFile);
        return $logParser->iterate();
    }

    protected function getTotalLines()
    {
        if (!file_exists($this->logFile)) {
            return;
        }
        $file = new \SplFileObject($this->logFile);
        $file->seek($file->getSize());
        return $file->key();
    }
}
