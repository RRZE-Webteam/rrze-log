<?php

namespace RRZE\Log;

use RRZE\Log\Options;
use RRZE\Log\Rotate;
use RRZE\Log\File\Flock;
use RRZE\Log\File\FlockException;

defined('ABSPATH') || exit;

class Log
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
     * @var integer
     */
    protected $threshold;

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
     * [__construct description]
     */
    public function __construct()
    {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();

        $this->enabled = $this->options->enabled;
        if (!$this->enabled) {
            return false;
        }

        isset($this->funcOverload) || $this->funcOverload = (extension_loaded('mbstring') && ini_get('mbstring.func_overload'));

        $this->currentBlogId = get_current_blog_id();

        $this->logPath = RRZELOG_DIR . DIRECTORY_SEPARATOR;

        $this->threshold = absint($this->options->threshold);

        $this->currentTimeGmt = current_time('timestamp', 1);

        $this->rotate = new Rotate();
    }

    /**
     * [writeError description]
     * @param  array  $content [description]
     */
    public function writeError($content = [])
    {
        $this->write('ERROR', $content);
    }

    /**
     * [writeWarning description]
     * @param  array  $content [description]
     */
    public function writeWarning($content = [])
    {
        $this->write('WARNING', $content);
    }

    /**
     * [writeNotice description]
     * @param  array  $content [description]
     */
    public function writeNotice($content = [])
    {
        $this->write('NOTICE', $content);
    }

    /**
     * [writeInfo description]
     * @param  array  $content [description]
     */
    public function writeInfo($content = [])
    {
        $this->write('INFO', $content);
    }

    /**
     * [writeDebug description]
     * @param  array  $content [description]
     */
    public function writeDebug($content = [])
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->write('DEBUG', $content);
        }
    }

    /**
     * [write description]
     * @param  integer $level   [description]
     * @param  array $content [description]
     * @return boolean          [description]
     */
    protected function write($level, $content)
    {
        if (!$this->isLogPathWritable()) {
            return false;
        }

        if (!isset($this->levels[$level]) || !$this->getThreshold($this->levels[$level])) {
            return false;
        }

        $prefix = in_array($level, ['ERROR', 'WARNING', 'NOTICE']) ? 'error' : strtolower($level);
        $file = sprintf('%1$s%2$s.log', $this->logPath, $prefix);

        $filemtimeOptionName = sprintf('%1$s_%2$s', $this->optionName, $prefix);

        if ($this->currentTimeGmt - absint(get_site_option($filemtimeOptionName)) > $this->options->rotatetime) {
            $this->rotate->prepare($file);
        }

        $line = '';
        $newFile = false;

        if (!file_exists($file)) {
            $newFile = true;
        }

        $flock = new Flock($file);

        try {
            $fp = $flock->acquire()->fp;
            $bytesWritten = $this->writeLine($fp, $level, $content);
            $flock->release();
        } catch (FlockException $e) {
            return false;
        }

        if ($newFile) {
            chmod($file, $this->filePermissions);
            update_site_option($filemtimeOptionName, $this->currentTimeGmt);
        }

        return is_int($bytesWritten);
    }

    /**
     * [writeLine description]
     * @param  resource $fp      [description]
     * @param  string $level   [description]
     * @param  array $content [description]
     * @return integer          [description]
     */
    protected function writeLine($fp, $level, $content)
    {
        $date = sprintf('%s UTC', date('Y-m-d H:i:s', $this->currentTimeGmt));
        $line = json_encode(['date' => $date, 'blog_id' => $this->currentBlogId, 'level' => $level, 'content' => $content]) . PHP_EOL;
        $line .= $level == 'DEBUG' ? print_r($content, true) . PHP_EOL : '';

        $bytesWritten = 0;
        for ($written = 0, $length = $this->strLen($line); $written < $length; $written += $bytesWritten) {
            if (($bytesWritten = fwrite($fp, $this->subStr($line, $written))) === false) {
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
     * [getErrorLevels description]
     * @return array [description]
     */
    public function getErrorLevels()
    {
        return $this->levels;
    }

    /**
     * [getThreshold description]
     * @param  integer $bitmask [description]
     * @return boolean          [description]
     */
    protected function getThreshold($bitmask)
    {
        return ($this->threshold & (1 << $bitmask)) != 0;
    }
}
