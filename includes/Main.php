<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Settings;
use RRZE\Log\Log;

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

    public function __construct($pluginFile)
    {
        $this->pluginFile = $pluginFile;
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();
    }

    public function onLoaded()
    {
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);

        $settings = new Settings($this->pluginFile, $this->optionName, $this->options);
        $settings->onLoaded();

        if (!$this->options->enabled) {
            return;
        }

        $this->logger = new Logger();
        $this->logger->onLoaded();

        add_action('rrze.log.error', [$this, 'logError'], 10, 2);
        add_action('rrze.log.warning', [$this, 'logWarning'], 10, 2);
        add_action('rrze.log.notice', [$this, 'logNotice'], 10, 2);
        add_action('rrze.log.info', [$this, 'logInfo'], 10, 2);

        //Test
        $this->test();
    }

    /**
     * [logError description]
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
     * @param  array  $context [description]
     */
    public function logInfo($message, $context = [])
    {
        if ($content = $this->sanitizeArgs($message, $context)) {
            $this->logger->info($content['message'], $content['context']);
        }
    }

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

    protected function test()
    {
        $logType = [
            'rrze.log.error',
            'rrze.log.debug',
            'rrze.log.warning',
            'rrze.log.notice',
            'rrze.log.info'
        ];
        $types = [
            'plugin' => 'rrze-log',
            'theme' => 'fau-einrichtungen',
            'theme' => 'blue-edgy',
            'plugin' => 'rrze-calendar',
            'plugin' => 'cms-workflow'
        ];
        $typeKey = array_rand($types);
        $typeName = $types[$typeKey];
        $ary = [
            'wordOne' => $this->randomWord(rand(4, 6)),
            'wordTwo' => $this->randomWord(rand(4, 6))
        ];
        $obj = new \stdClass;
        $obj->wordOne = $this->randomWord(rand(4, 6));
        $obj->wordTwo = $this->randomWord(rand(4, 6));

        for ($i = 1; $i <= 100; $i++) {
            do_action($logType[rand(0, 4)], $this->randomText(), [$typeKey => $typeName, 'ary' => $ary, 'obj' => $obj]);
            do_action($logType[rand(0, 4)], '(only message) ' . $this->randomText());
            do_action($logType[rand(0, 4)], [$typeKey => $typeName, 'ary' => $ary, 'obj' => $obj]);
        }
    }

    protected function randomText()
    {
        $text = [];
        $limit = rand(3, 6);
        for ($i = 1; $i <= $limit; $i++) {
            $length = rand(4, 6);
            $text[] = $this->randomWord($length);
        }
        return implode(' ', $text);
    }

    protected function randomWord($length = 6)
    {
        $word = '';
        $vowels = ["a","e","i","o","u"];
        $consonants = [
            'b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm',
            'n', 'p', 'r', 's', 't', 'v', 'w', 'x', 'y', 'z'
        ];
        $max = $length/2;
        for ($i = 1; $i <= $max; $i++)
        {
            $word .= $consonants[rand(0,19)];
            $word .= $vowels[rand(0,4)];
        }
        return $word;
    }
}
