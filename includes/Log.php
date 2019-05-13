<?php

namespace RRZE\Log;

use RRZE\Log\Options;

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
     * @var integer
     */
    protected $rotatemax;

    /**
     * [protected description]
     * @var integer
     */
    protected $rotatetime;

    /**
     * [protected description]
     * @var integer
     */
    protected $rotatestamp;

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

        $this->rotatemax = absint($this->options->rotatemax);
        $this->rotatetime = absint($this->options->rotatetime);
        $this->rotatestamp = absint($this->options->rotatestamp);
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

        $this->rotate($file);

        $line = '';

        if (!file_exists($file)) {
            $newfile = true;
        }

        if (!$fp = @fopen($file, 'ab')) {
            return false;
        }

        flock($fp, LOCK_EX);

        $line .= $this->format($timestamp, $level, $content);

        for ($written = 0, $length = $this->strLen($line); $written < $length; $written += $result) {
            if (($result = fwrite($fp, $this->subStr($line, $written))) === false) {
                break;
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (isset($newfile) && $newfile === true) {
            chmod($file, $this->filePermissions);
            $this->options->rotatestamp = filemtime($file);
            $this->rotatestamp = absint($this->options->rotatestamp);
            update_site_option($this->optionName, $this->options);
        }

        return is_int($result);
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

    /**
     * [rotate description]
     * @param  resource $file [description]
     * @return boolean       [description]
     */
    protected function rotate($file = '')
    {
        clearstatcache();

        if (is_file($file) && is_writable($file)) {
            if (filesize($file) > 0) {
                if (time() - $this->rotatestamp <= $this->rotatetime) {
                    return false;
                }

                $fileInfo = pathinfo($file);
                $glob = $fileInfo['dirname'].'/'.$fileInfo['filename'];

                if (!empty($fileInfo['extension'])) {
                    $glob .= '.'.$fileInfo['extension'];
                }

                $glob .= '.*';

                $curFiles = glob($glob);

                $n_curFiles = count($curFiles);

                for ($n = $n_curFiles; $n > 0; $n--) {
                    if (file_exists(str_replace('*', $n, $glob))) {
                        if ($this->rotatemax > 0 && $n >= $this->rotatemax) {
                            unlink(str_replace('*', $n, $glob));
                        } else {
                            rename(str_replace('*', $n, $glob), str_replace('*', $n + 1, $glob));
                        }
                    }
                }

                $newFile = str_replace('*', '1', $glob);

                return rename($file, $newFile);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
}
