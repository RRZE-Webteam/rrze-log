<?php

namespace RRZE\Log;

use RRZE\Log\Options;
use RRZE\Log\Rotate;

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

        if (is_multisite()) {
            $this->logPath = RRZELOG_DIR . DIRECTORY_SEPARATOR . $this->currentBlogId . DIRECTORY_SEPARATOR;
        } else {
            $this->logPath = RRZELOG_DIR . DIRECTORY_SEPARATOR;
        }

        $this->threshold = absint($this->options->threshold);

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
        $timestamp = current_time('timestamp', 1);
        $file = sprintf('%1$s%2$s.log', $this->logPath, $prefix);

        $rotateResult = $this->rotate->prepare($file);
        if (!$rotateResult || is_wp_error($rotateResult)) {
            return false;
        }

        $line = '';

        if (!file_exists($file)) {
            $newFile = true;
        }

        if (!$fp = @fopen($file, 'ab')) {
            return false;
        }

        flock($fp, LOCK_EX);

        $line .= $this->format($timestamp, $level, $content);

        $bytesWritten = 0;
        for ($written = 0, $length = $this->strLen($line); $written < $length; $written += $bytesWritten) {
            if (($bytesWritten = fwrite($fp, $this->subStr($line, $written))) === false) {
                break;
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (isset($newFile) && $newFile === true) {
            chmod($file, $this->filePermissions);
            $this->options->rotatestamp = filemtime($file);
            update_site_option($this->optionName, $this->options);
        }

        return is_int($bytesWritten);
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
     * [format description]
     * @param  integer $timestamp [description]
     * @param  integer $level     [description]
     * @param  array $content   [description]
     * @return string            [description]
     */
    protected function format($timestamp, $level, $content)
    {
        $date = sprintf('%s UTC', date('Y-m-d H:i:s', $timestamp));
        $line = json_encode(['date' => $date, 'blog_id' => $this->currentBlogId, 'level' => $level, 'content' => $content]) . PHP_EOL;
        if ($level == 'DEBUG') {
            $line .= print_r($content, true) . PHP_EOL;
        }
        return $line;
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
