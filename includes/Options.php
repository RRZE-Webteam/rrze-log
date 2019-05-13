<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Options
{
    /**
     * Option name
     * @var string
     */
    protected static $optionName = 'rrze_log';

    /**
     * Default options
     * @return array
     */
    protected static function defaultOptions()
    {
        $options = [
            'enabled' => '0',
            'threshold' => '2',
            'rotatemax' => '1',
            'rotatetime' => DAY_IN_SECONDS,
            'rotatestamp' => '0'
        ];

        return $options;
    }

    /**
     * Returns the options.
     * @return object
     */
    public static function getOptions()
    {
        $defaults = self::defaultOptions();

        $options = (array) get_site_option(self::$optionName);
        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return (object) $options;
    }

    /**
     * Returns the name of the option.
     * @return string
     */
    public static function getOptionName()
    {
        return self::$optionName;
    }
}
