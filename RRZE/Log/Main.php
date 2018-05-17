<?php

namespace RRZE\Log;

use RRZE\Log\Options;
use RRZE\Log\Settings;
use RRZE\Log\Log;

defined('ABSPATH') || exit;

class Main {
    
    protected $plugin_basename;
    
    protected $options;
    
    protected $settings;
    
    public $log;

    public function __construct($plugin_basename) {
        $this->plugin_basename = $plugin_basename;
        
        $options = new Options();
        $this->options = $options->get_options();
        
        $this->settings = new Settings();
        
        $this->log = new Log();
                
        add_action('rrze.log.error', [$this->log, 'write_error']);
        add_action('rrze.log.debug', [$this->log, 'write_debug']);
        add_action('rrze.log.warning', [$this->log, 'write_warning']);
        add_action('rrze.log.notice', [$this->log, 'write_notice']);
        add_action('rrze.log.info', [$this->log, 'write_info']);
        
        if (is_multisite()) {
            add_action('network_admin_menu', [$this->settings, 'network_settings_menu']);
            add_action('admin_init', [$this->settings, 'network_settings_sections']);
            add_filter('network_admin_plugin_action_links_' . $this->plugin_basename, [$this, 'network_admin_plugin_action_link']);            
        } else {
            add_action('admin_menu', [$this->settings, 'admin_settings_menu']);
            add_action('admin_init', [$this->settings, 'admin_settings_sections']);
            add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'plugin_action_link']);
        }
    }
    
    public function network_admin_plugin_action_link($links) {
        if (!current_user_can('manage_network_options')) {
            return $links;
        }
        return array_merge($links, array(sprintf('<a href="%s">%s</a>', add_query_arg(array('page' => 'rrzelog-network'), network_admin_url('settings.php')), __('Settings', 'rrze-log'))));        
    }
        
    public function plugin_action_link($links) {
        if (!current_user_can('manage_options')) {
            return $links;
        }
        return array_merge($links, array(sprintf('<a href="%s">%s</a>', add_query_arg(array('page' => 'rrzelog'), admin_url('options-general.php')), __('Settings', 'rrze-log'))));
    }
    
}
