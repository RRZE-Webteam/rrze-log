<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Options {
    /**
     * Option name
     * @var string
     */
    protected static $optionName = 'rrze_log';

    /**
     * Default options
     * @return array
     */
    protected static function defaultOptions() {
        $options = [
            'enabled' => '0',
            'maxLines' => 1000,
            'adminMenu' => '0',
            'debugMaxLines' => 1000,
            'debugLogAccess' => '',
            'auditEnabled' => '0',
        ];

        return $options;
    }

    /**
     * Returns the options.
     * @return object
     */
    public static function getOptions() {
        $defaults = self::defaultOptions();

        $options = (array) get_site_option(self::$optionName);
        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        /*
         * Network-wide override via rrze_settings
         * This always wins on multisite installations.
         */
        if (is_multisite()) {
            $settingsOptions = get_site_option('rrze_settings');

            if (is_array($settingsOptions)) {
                $settingsOptions = (object) $settingsOptions;
            }

            if (
                is_object($settingsOptions)
                && isset($settingsOptions->plugins)
                && is_object($settingsOptions->plugins)
                && !empty($settingsOptions->plugins->rrze_log_auditEnabled)
            ) {
                $options['auditEnabled'] = '1';
            }
        }
        
        return (object) $options;
    }

    /**
     * Returns the name of the option.
     * @return string
     */
    public static function getOptionName() {
        return self::$optionName;
    }
}
