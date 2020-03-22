<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\File\Flock;
use RRZE\Log\File\FlockException;

class Logger
{
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
    protected $siteUrl;

    /**
     * [protected description]
     * @var integer
     */
    protected $filePermissions = 0644;

    /**
     * [LOG_DIR description]
     * @var string
     */
    const LOG_DIR = WP_CONTENT_DIR . '/log/rrze-log';

    /**
     * [protected description]
     * @var array
     */
    const LEVELS = ['ERROR', 'WARNING', 'NOTICE', 'INFO'];

    /**
     * [__construct description]
     */
    public function __construct()
    {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * [onLoaded description]
     */
    public function onLoaded()
    {
        $this->logPath = static::LOG_DIR . DIRECTORY_SEPARATOR;

        isset($this->funcOverload) || $this->funcOverload = (extension_loaded('mbstring') && ini_get('mbstring.func_overload'));

        $this->siteUrl = get_site_url();
    }

    /**
     * [error description]
     * @param  string $message [description]
     * @param  array  $context [description]
     */
    public function error(string $message, array $context)
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * [warning description]
     * @param  string $message [description]
     * @param  array  $context [description]
     */
    public function warning(string $message, array $context)
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * [notice description]
     * @param  string $message [description]
     * @param  array  $context [description]
     */
    public function notice(string $message, array $context)
    {
        $this->log('NOTICE', $message, $context);
    }

    /**
     * [info description]
     * @param  string $message [description]
     * @param  array  $context [description]
     */
    public function info(string $message, array $context)
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * [log description]
     * @param  string $level   [description]
     * @param  string $message [description]
     * @param  array  $context [description]
     */
    protected function log(string $level, string $message, array $context)
    {
        $this->logFile = sprintf('%1$s%2$s.log', $this->logPath, date('Y-m-d'));

        $data = [
            'datetime' => $this->getDateTime(),
            'siteurl' => $this->siteUrl,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $this->write($data, $level);
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

        $this->unlinkOldLogFiles();

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

    /**
     * [unlinkOldLogFiles description]
     */
    protected function unlinkOldLogFiles()
    {
        foreach (new \DirectoryIterator($this->logPath) as $file) {
            if ($file->isFile() && (time() - $file->getMTime() > $this->options->logTTL * DAY_IN_SECONDS)) {
                @unlink($this->logPath . $file->getFilename());
            }
        }
    }

    /**
     * [getDateTime description]
     * @return string [description]
     */
    protected function getDateTime()
    {
        $currentTime = microtime(true);
        $microTime = sprintf("%06d", ($currentTime - floor($currentTime)) * 1000000);
        $dateTime = new \DateTime(date('Y-m-d H:i:s.' . $microTime, $currentTime));
        return $dateTime->format('Y-m-d G:i:s.u');
    }
}
