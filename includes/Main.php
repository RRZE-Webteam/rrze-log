<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Settings;
use RRZE\Log\Logger;

class Main
{
    /**
     * Option name.
     * @var string
     */
    public $optionName;

    /**
     * Options values.
     * @var object
     */
    public $options;

    /**
     * Logger object.
     * @var object
     */
    protected $logger;

    /**
     * Set properties.
     */
    public function __construct()
    {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    /**
     * Initiate classes & add hooks.
     */
    public function onLoaded()
    {
        file_exists(Constants::LOG_PATH) || wp_mkdir_p(Constants::LOG_PATH);

        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);

        $settings = new Settings;
        $settings->onLoaded();

        if (!$this->options->enabled) {
            return;
        }

        $this->logger = new Logger;
        $this->logger->onLoaded();

        add_action('rrze.log.error', [$this, 'logError'], 10, 2);
        add_action('rrze.log.warning', [$this, 'logWarning'], 10, 2);
        add_action('rrze.log.notice', [$this, 'logNotice'], 10, 2);
        add_action('rrze.log.info', [$this, 'logInfo'], 10, 2);
    }

    /**
     * ERROR log type.
     * @param  mixed $message
     * @param  array  $context
     */
    public function logError($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->error($content['message'], $content['context']);
        }
    }

    /**
     * WARNING log type.
     * @param  mixed $message
     * @param  array  $context
     */
    public function logWarning($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->warning($content['message'], $content['context']);
        }
    }

    /**
     * NOTICE log type.
     * @param  mixed $message
     * @param  array  $context
     */
    public function logNotice($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->notice($content['message'], $content['context']);
        }
    }

    /**
     * INFO log type.
     * @param  mixed $message
     * @param  array  $context
     */
    public function logInfo($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->info($content['message'], $content['context']);
        }
    }

    /**
     * Sanitize log arguments.
     * @param  mixed $message
     * @param  array  $context
     * @return array
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
     * Variable interpolation.
     * @param  string $message
     * @param  array  $context
     * @return string
     */
    protected function interpolate($message, array $context)
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $fromStr = '{' . $key . '}';
            $toStr = '';
            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $toStr = $value;
            }
            $replace[$fromStr] = $toStr;
        }
        return strtr($message, $replace);
    }

    /**
     * Register admin styles & scripts.
     */
    public function adminEnqueueScripts($hook)
    {
        if (!in_array($hook, ['toplevel_page_rrze-log', 'tools_page_rrze-log', 'tools_page_rrze-log-debug'])) {
            return;
        }

        wp_register_style(
            'rrze-log-list-table',
            plugins_url('build/admin.css', plugin()->getBasename()),
            [],
            plugin()->getVersion()
        );

        wp_register_script(
            'rrze-log-list-table',
            plugins_url('build/admin.js', plugin()->getBasename()),
            ['jquery'],
            plugin()->getVersion()
        );
    }
}
