<?php

namespace RRZE\Log;

use RRZE\Log\Options;

defined('ABSPATH') || exit;

class Log
{
    protected $options;
    protected $option_name;
    protected $log_path;
    protected $threshold;
    protected $enabled;
    protected $func_overload;
    
    protected $current_blog_id;
    
    protected $file_permissions = 0644;
    protected $levels = ['ERROR' => 1, 'WARNING' => 2, 'NOTICE' => 4, 'INFO' => 8, 'DEBUG' => 16];
    
    protected $rotatemax;
    protected $rotatetime;
    protected $rotatestamp;
    
    public function __construct()
    {
        $options = new Options();
        $this->option_name = $options->get_option_name();
        $this->options = $options->get_options();

        $this->enabled = $this->options->enabled;
        if (!$this->enabled) {
            return false;
        }
        
        isset($this->func_overload) || $this->func_overload = (extension_loaded('mbstring') && ini_get('mbstring.func_overload'));
        
        $this->current_blog_id = get_current_blog_id();
        
        if (is_multisite()) {
            $this->log_path = RRZELOG_DIR . DIRECTORY_SEPARATOR . $this->current_blog_id . DIRECTORY_SEPARATOR;
        } else {
            $this->log_path = RRZELOG_DIR . DIRECTORY_SEPARATOR;
        }

        $this->threshold = absint($this->options->threshold);
        
        $this->rotatemax = absint($this->options->rotatemax);
        $this->rotatetime = absint($this->options->rotatetime);
        $this->rotatestamp = absint($this->options->rotatestamp);
    }

    public function write_error($content = [])
    {
        $this->write('ERROR', $content);
    }
    
    public function write_warning($content = [])
    {
        $this->write('WARNING', $content);
    }

    public function write_notice($content = [])
    {
        $this->write('NOTICE', $content);
    }

    public function write_info($content = [])
    {
        $this->write('INFO', $content);
    }
    
    public function write_debug($content = [])
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->write('DEBUG', $content);
        }
    }
    
    protected function write($level, $content)
    {
        if (!$this->is_log_path_writable()) {
            return false;
        }
        
        if (!isset($this->levels[$level]) || !$this->get_threshold($this->levels[$level])) {
            return false;
        }
        
        $prefix = in_array($level, ['ERROR', 'WARNING', 'NOTICE']) ? 'error' : strtolower($level);
        $timestamp = current_time('timestamp', 1);
        $file = sprintf('%1$s%2$s.log', $this->log_path, $prefix);

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

        for ($written = 0, $length = $this->strlen($line); $written < $length; $written += $result) {
            if (($result = fwrite($fp, $this->substr($line, $written))) === false) {
                break;
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (isset($newfile) && $newfile === true) {
            chmod($file, $this->file_permissions);
            $this->options->rotatestamp = filemtime($file);
            $this->rotatestamp = absint($this->options->rotatestamp);
            update_site_option($this->option_name, $this->options);
        }
        
        return is_int($result);
    }

    protected function is_log_path_writable()
    {
        file_exists($this->log_path) || wp_mkdir_p($this->log_path);

        if (!is_dir($this->log_path) || !$this->is_writable($this->log_path)) {
            return false;
        }
        
        return true;
    }
    
    protected function is_writable($file)
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

    protected function format($timestamp, $level, $content)
    {
        $date = sprintf('%s UTC', date('Y-m-d H:i:s', $timestamp));
        $line = json_encode(['date' => $date, 'blog_id' => $this->current_blog_id, 'level' => $level, 'content' => $content]) . PHP_EOL;
        if ($level == 'DEBUG') {
            $line .= print_r($content, true) . PHP_EOL;
        }
        return $line;
    }

    protected function strlen($str)
    {
        return ($this->func_overload) ? mb_strlen($str, '8bit') : strlen($str);
    }

    protected function substr($str, $start, $length = null)
    {
        if ($this->func_overload) {
            return mb_substr($str, $start, $length, '8bit');
        }

        return isset($length) ? substr($str, $start, $length) : substr($str, $start);
    }

    public function get_error_levels()
    {
        return $this->levels;
    }
    
    protected function get_threshold($bitmask)
    {
        return ($this->threshold & (1 << $bitmask)) != 0;
    }
    
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
