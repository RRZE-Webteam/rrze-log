<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Constants
{
    /**
     * [PLUGIN_PHP_VERSION description]
     * @var string
     */    
    const PLUGIN_PHP_VERSION = '7.4';

    /**
     * [PLUGIN_WP_VERSION description]
     * @var string
     */    
    const PLUGIN_WP_VERSION = '5.6';

    /**
     * [LOG_DIR description]
     * @var string
     */
    const LOG_DIR = WP_CONTENT_DIR . '/log/rrze-log';

    /**
     * [LOG_PATH description]
     * @var string
     */
    const LOG_PATH = WP_CONTENT_DIR . '/log/rrze-log/';
}
