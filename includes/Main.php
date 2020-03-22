<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Settings;
use RRZE\Log\Logger;

class Main
{
    /**
     * [protected description]
     * @var string
     */
    protected $pluginFile;

    /**
     * [protected description]
     * @var string
     */
    protected $optionName;

    /**
     * [protected description]
     * @var object
     */
    protected $options;

    /**
     * [protected description]
     * @var string
     */
    protected $logger;

    /**
     * [__construct description]
     * @param string $pluginFile [description]
     */
    public function __construct($pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * [onLoaded description]
     * @return void
     */
    public function onLoaded()
    {
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);

        $settings = new Settings($this->pluginFile, $this->optionName, $this->options);
        $settings->onLoaded();

        if (!$this->options->enabled) {
            return;
        }

        $this->logger = new Logger($this->options);
        $this->logger->onLoaded();

        add_action('rrze.log.error', [$this, 'logError'], 10, 2);
        add_action('rrze.log.warning', [$this, 'logWarning'], 10, 2);
        add_action('rrze.log.notice', [$this, 'logNotice'], 10, 2);
        add_action('rrze.log.info', [$this, 'logInfo'], 10, 2);
    }

    /**
     * [logError description]
     * @param  mixed $message [description]
     * @param  array  $context [description]
     */
    public function logError($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->error($content['message'], $content['context']);
        }
    }

    /**
     * [logWarning description]
     * @param  mixed $message [description]
     * @param  array  $context [description]
     */
    public function logWarning($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->warning($content['message'], $content['context']);
        }
    }

    /**
     * [logNotice description]
     * @param  mixed $message [description]
     * @param  array  $context [description]
     */
    public function logNotice($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->notice($content['message'], $content['context']);
        }
    }

    /**
     * [logInfo description]
     * @param  mixed $message [description]
     * @param  array  $context [description]
     */
    public function logInfo($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->info($content['message'], $content['context']);
        }
    }

    /**
     * [sanitizeArgs description]
     * @param  mixed $message [description]
     * @param  array  $context [description]
     * @return array          [description]
     */
    protected function sanitizeArgs($message, $context)
    {
        if (empty($message)) {
            return false;
        }

        if (is_string($message) && empty($context)) {
            $context = [];
        } elseif (is_array($message) && empty($context)) {
            $context = $message;
            $message = '';
        }

        if (!is_array($context)) {
            return false;
        }

        $message = !$message && $context ? '{' . implode('} {', array_keys($context)) . '}' : $message;
        $message = $context ? $this->interpolate($message, $context) : $message;

        return [
            'message' => trim($message),
            'context' => $context
        ];
    }

    /**
     * [interpolate description]
     * @param  string $message [description]
     * @param  array  $context [description]
     * @return string          [description]
     */
    protected function interpolate($message, array $context)
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = sprintf('%1$s: %2$s', $key, $value);
            } else {
                $replace['{' . $key . '}'] = '';
            }
        }
        return strtr($message, $replace);
    }

    public function adminEnqueueScripts()
    {
        wp_register_style('rrze-log-list-table', plugins_url('assets/css/list-table.min.css', plugin_basename($this->pluginFile)));
        wp_register_script('rrze-log-list-table', plugins_url('assets/js/list-table.min.js', plugin_basename($this->pluginFile)));
    }

}
