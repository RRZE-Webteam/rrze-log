<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Options
{
    protected $option_name = 'rrze_log';
    
    public function __construct()
    {
    }

    protected function default_options()
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

    public function get_options()
    {
        $defaults = self::default_options();

        $options = (array) get_site_option($this->option_name);
        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return (object) $options;
    }
    
    public function get_option_name()
    {
        return $this->option_name;
    }
}
