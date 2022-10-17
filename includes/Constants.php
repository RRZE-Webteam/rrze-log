<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Constants
{
    /**
     * Required PHP version.
     * @var string
     */
    const REQUIRED_PHP_VERSION = '7.4';

    /**
     * Required WP version.
     * @var string
     */
    const REQUIRED_WP_VERSION = '6.0';

    /**
     * Full log path.
     * @var string
     */
    const LOG_PATH = WP_CONTENT_DIR . '/log/rrze-log/';
}
