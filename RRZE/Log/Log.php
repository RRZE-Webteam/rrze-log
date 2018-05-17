<?php

namespace RRZE\Log;

use RRZE\Log\Options;

defined('ABSPATH') || exit;

class Log {

    protected $options;
    protected $log_path;
    protected $threshold;
    protected $enabled;
    protected $func_overload;
    
    protected $file_permissions = 0644;    
    protected $levels = ['ERROR' => 1, 'WARNING' => 2, 'NOTICE' => 4, 'INFO' => 8, 'DEBUG' => 16];

    public function __construct() {
        $options = new Options();
        $this->options = $options->get_options();

        $this->enabled = $this->options->enabled;
        if (!$this->enabled) {
            return FALSE;
        }
       
        isset($this->func_overload) || $this->func_overload = (extension_loaded('mbstring') && ini_get('mbstring.func_overload'));

        $this->log_path = RRZELOG_DIR . DIRECTORY_SEPARATOR . get_current_blog_id() . DIRECTORY_SEPARATOR;

        $this->threshold = absint($this->options->threshold);
    }

    public function write_error($context = []) {
        $this->write('ERROR', $context);
    }
    
    public function write_warning($context = []) {
        $this->write('WARNING', $context);
    }

    public function write_notice($context = []) {
        $this->write('NOTICE', $context);
    }

    public function write_info($context = []) {
        $this->write('INFO', $context);
    }
    
    public function write_debug($context = []) {
        $this->write('DEBUG', $context);
    }
    
    protected function write($level, $context) {
        if (!$this->is_log_path_writable()) {
            return FALSE;
        }

        $level = strtoupper($level);
        
        if (!isset($this->levels[$level]) || !$this->get_threshold($this->levels[$level])) {
            return FALSE;
        }

        $filepath = $this->log_path . date('Y-m-d', current_time('timestamp', 1)) . '.log';
        $message = '';

        if (!file_exists($filepath)) {
            $newfile = TRUE;
        }

        if (!$fp = @fopen($filepath, 'ab')) {
            return FALSE;
        }

        flock($fp, LOCK_EX);

        $date = date('Y-m-d H:i:s', current_time('timestamp', 1));
        $message .= $this->format($level, $date, $context);

        for ($written = 0, $length = $this->strlen($message); $written < $length; $written += $result) {
            if (($result = fwrite($fp, $this->substr($message, $written))) === FALSE) {
                break;
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if (isset($newfile) && $newfile === TRUE) {
            chmod($filepath, $this->file_permissions);
        }

        return is_int($result);
    }

    protected function is_log_path_writable() {
        file_exists($this->log_path) || wp_mkdir_p($this->log_path);

        if (!is_dir($this->log_path) || !$this->is_writable($this->log_path)) {
            return FALSE;
        }
        
        return TRUE;
    }
    
    protected function is_writable($file) {
        if (DIRECTORY_SEPARATOR === '/' && !ini_get('safe_mode')) {
            return is_writable($file);
        }

        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === FALSE) {
                return FALSE;
            }

            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return TRUE;
        } elseif (!is_file($file) OR ( $fp = @fopen($file, 'ab')) === FALSE) {
            return FALSE;
        }

        fclose($fp);
        return TRUE;
    }

    protected function format($level, $date, $context) {
        $line[] = sprintf('[%1$s UTC] %2$s', $date, $level);
        foreach($context as $key => $value) {
            $line[] = sprintf('%1$s %2$s', $key, $value);
        }
        return implode(' ', $line) . PHP_EOL;
    }

    protected function strlen($str) {
        return ($this->func_overload) ? mb_strlen($str, '8bit') : strlen($str);
    }

    protected function substr($str, $start, $length = NULL) {
        if ($this->func_overload) {
            return mb_substr($str, $start, $length, '8bit');
        }

        return isset($length) ? substr($str, $start, $length) : substr($str, $start);
    }

    public function get_error_levels() {
        return $this->levels;
    }
    
    protected function get_threshold($bitmask) {
        return ($this->threshold & (1 << $bitmask)) != 0;
    }
    
}
