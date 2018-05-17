<?php

namespace RRZE\Log;

use RRZE\Log\Options;

defined('ABSPATH') || exit;

class Settings {
    
    protected $options;
    
    protected $option_name;
    
    protected $admin_settings_page;
    
    public $log;
    
    public function __construct() {
        $options = new Options();
        $this->options = $options->get_options();
        $this->option_name = $options->get_option_name();
        
        $this->log = new Log();
    }
    
    public function admin_settings_menu() {
        $this->admin_settings_page = add_options_page(__('Log', 'rrze-log'), __('Log', 'rrze-log'), 'manage_options', 'rrzelog', [$this, 'admin_settings_page']);
        //add_action('load-' . $this->admin_settings_page, [$this, 'admin_help_menu']);        
    }

    public function admin_settings_page() {
        ?>
        <div class="wrap">
            <h2><?php echo __('Log Settings', 'rrze-log'); ?></h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('rrzelog_options');
                do_settings_sections('rrzelog_options');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    public function admin_settings_sections() {
        register_setting('rrzelog_options', $this->option_name, [$this, 'options_validate']);
        add_settings_section('rrzelog_section', FALSE, '__return_false', 'rrzelog_options');
        add_settings_field('rrzelog-enable', __('Enable Log', 'rrze-log'), [$this, 'enabled_field'], 'rrzelog_options', 'rrzelog_section');
        add_settings_field('rrzelog-threshold', __('Error Level', 'rrze-log'), [$this, 'threshold_field'], 'rrzelog_options', 'rrzelog_section');
    }

    public function options_validate($input) {
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $input_threshold = !empty($input['threshold']) ? (array) $input['threshold'] : [];

        $this->options->threshold = 0;
        
        $levels = $this->log->get_error_levels();

        foreach ($levels as $level => $bitmask) {
            if (isset($input_threshold[$level])) {
                $this->set_threshold($bitmask);
            }
        }
        
        $input['threshold'] = $this->options->threshold;
        
        return $input;
    }

    public function admin_help_menu() {

        $content = [
            '<p></p>',
        ];


        $help_tab = [
            'id' => $this->admin_settings_page,
            'title' => __('Overview', 'rrze-log'),
            'content' => implode(PHP_EOL, $content),
        ];

        $help_sidebar = sprintf('<p><strong>%1$s:</strong></p><p><a href="http://blogs.fau.de/webworking">RRZE-Webworking</a></p><p><a href="https://github.com/RRZE-Webteam">%2$s</a></p>', __('For more information:', 'rrze-log'), __('RRZE Webteam on Github', 'rrze-log'));

        $screen = get_current_screen();

        if ($screen->id != $this->admin_settings_page) {
            return;
        }

        $screen->add_help_tab($help_tab);

        $screen->set_help_sidebar($help_sidebar);
    }
    
    public function network_settings_menu() {
        if (isset($_POST['_wpnonce']) &&  wp_verify_nonce($_POST['_wpnonce'], 'rrzelog_network-options') && current_user_can('manage_network_options')) {
            
            if (isset($_POST['rrzelog-site-submit'])) {
                $this->options->enabled = !empty($_POST[$this->option_name]['enabled']) ? 1 : 0;
                $input_threshold = !empty($_POST[$this->option_name]['threshold']) ? (array) $_POST[$this->option_name]['threshold'] : [];
                
                $this->options->threshold = 0;
                
                $levels = $this->log->get_error_levels();
                
                foreach ($levels as $level => $bitmask) {
                    if (isset($input_threshold[$level])) {
                        $this->set_threshold($bitmask);
                    }
                }
                
                update_site_option($this->option_name, $this->options);
                
                wp_redirect(add_query_arg(['page' => 'rrzelog-network', 'update' => 'updated'], network_admin_url('settings.php')));
                exit;
            }
                        
        }
        
        add_submenu_page(
            'settings.php', 
            __('Log', 'rrze-log'), 
            __('Log', 'rrze-log'), 
            'manage_network_options', 
            'rrzelog-network', 
            [$this, 'network_page']
        );
    }
    
    public function network_page() {
        ?>
        <div class="wrap">
            <h2><?php echo esc_html(__('Log', 'rrze-log')); ?></h2>

            <form method="post">
                <?php
                settings_fields('rrzelog_network');
                do_settings_sections('rrzelog_network');
                submit_button(esc_html__('Saves Changes', 'rrze-log'), 'primary', 'rrzelog-site-submit')
                ?>
            </form>

        </div>
        <?php
    }
    
    public function network_settings_sections() {
        add_settings_section('rrzelog_section', FALSE, '__return_false', 'rrzelog_network');
        add_settings_field('rrzelog-enable', __('Enable Log', 'rrze-log'), [$this, 'enabled_field'], 'rrzelog_network', 'rrzelog_section');
        add_settings_field('rrzelog-threshold', __('Error Level', 'rrze-log'), [$this, 'threshold_field'], 'rrzelog_network', 'rrzelog_section');        
    }
            
    public function enabled_field() {
        ?>
        <label>
            <input type="checkbox" id="rrzelog-enabled" name="<?php printf('%s[enabled]', $this->option_name); ?>" value="1"<?php checked($this->options->enabled, 1); ?>>
        </label>
        <?php
    }
  
    public function threshold_field() {
        $levels = $this->log->get_error_levels();
        ?>
        <label for="rrzelog-threshold">
            <?php foreach($levels as $level => $bitmask) :?>
            <input type="checkbox" id="<?php printf('rrzelog-level-%s', strtolower($level)); ?>" name="<?php printf('%s[threshold][%s]', $this->option_name, $level); ?>" value="1"<?php checked($this->get_threshold($bitmask), 1); ?>> <?php echo $level; ?> </br>
            <?php endforeach; ?>
        </label>
        <?php
    }
    
    protected function get_threshold($bitmask) {
        return ($this->options->threshold & (1 << $bitmask)) != 0;
    }
    
    protected function set_threshold($bitmask, $new = TRUE) {
        $this->options->threshold = ($this->options->threshold & ~(1 << $bitmask)) | ($new << $bitmask);
    }
    
}