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
        $settings = new Settings($this->pluginFile, $this->optionName, $this->options);
        $settings->onLoaded();

        $this->logger = new Logger($this->pluginFile, $this->options);
        $this->logger->onLoaded();

        add_action('rrze.log.error', [$this, 'logError']);
        add_action('rrze.log.warning', [$this, 'logWarning']);
        add_action('rrze.log.notice', [$this, 'logNotice']);
        add_action('rrze.log.info', [$this, 'logInfo']);
        add_action('rrze.log.debug', [$this, 'logDebug']);

        //Test
        $this->test();
    }

    /**
     * [logError description]
     * @param  array  $content [description]
     */
    public function logError($content = [])
    {
        if ($content = $this->sanitizeContent($content)) {
            $this->logger->error($content['message'], $content['context']);
        }
    }

    /**
     * [logWarning description]
     * @param  array  $content [description]
     */
    public function logWarning($content)
    {
        if ($content = $this->sanitizeContent($content)) {
            $this->logger->warning($content['message'], $content['context']);
        }
    }

    /**
     * [logNotice description]
     * @param  array  $content [description]
     */
    public function logNotice($content = [])
    {
        if ($content = $this->sanitizeContent($content)) {
            $this->logger->notice($content['message'], $content['context']);
        }
    }

    /**
     * [logInfo description]
     * @param  array  $content [description]
     */
    public function logInfo($content = [])
    {
        if ($content = $this->sanitizeContent($content)) {
            $this->logger->info($content['message'], $content['context']);
        }
    }

    /**
     * [logDebug description]
     * @param  array  $content [description]
     */
    public function logDebug($content = [])
    {
        if (defined('WP_DEBUG') && WP_DEBUG && ($content = $this->sanitizeContent($content))) {
            $this->logger->debug($content['message'], $content['context']);
        }
    }
    
    protected function sanitizeContent($content)
    {
        $message = '';
        if (!is_array($content)) {
            return false;
        }
        if (isset($content['message'])) {
            $message = $content['message'];
            unset($content['message']);
        }
        return [
            'message' => $message,
            'context' => $this->objectToArray($content)
        ];
    }

    protected function isJson($string)
    {
        // php 5.3 or newer needed;
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    protected function objectToArray($objectOrArray)
    {    
        // if is_json -> decode :
        if (is_string($objectOrArray) && $this->isJson($objectOrArray)) {
            $objectOrArray = json_decode($objectOrArray);
        }

        // if object -> convert to array :
        if (is_object($objectOrArray)) {
            $objectOrArray = (array) $objectOrArray;
        }

        // if not array -> just convert to array :
        if (!is_array($objectOrArray)) {
            return (array) $objectOrArray;
;
        }

        // if empty array -> return [] :
        if (count($objectOrArray) == 0) {
            return [];
        }

        // repeat tasks for each item :
        $output = [];
        foreach ($objectOrArray as $key => $o_a) {
            $output[$key] = $this->objectToArray($o_a);
        }
        return $output;
    }
    
    protected function test()
    {
        $ary = [
            'one' => 1,
            'two' => 2
        ];
        $obj = new \stdClass;
        $obj->one = 1;
        $obj->two = 2;

        $logType = [
            'rrze.log.error',
            'rrze.log.debug',
            'rrze.log.warning',
            'rrze.log.notice',
            'rrze.log.info'
        ];

        do_action($logType[rand(0, 4)], ['message' => 'Hello World!', 'plugin' =>'rrze-log', 'ary' => $ary, 'obj' => $obj]);

        //\RRZE\Dev\dLog($result);
    }
}
