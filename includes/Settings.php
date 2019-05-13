<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Options;
use RRZE\Log\Log;

class Settings
{
    protected $options;

    protected $option_name;

    protected $adminSettingsPage;

    public $log;

    public function __construct()
    {
        $this->optionName = Options::getOptionName();
        $this->options = Options::getOptions();

        $this->log = new Log();
    }

    public function adminSettingsMenu()
    {
        $this->adminSettingsPage = add_options_page(__('Log', 'rrze-log'), __('Log', 'rrze-log'), 'manage_options', 'rrzelog', [$this, 'adminSettingsPage']);
        //add_action('load-' . $this->adminSettingsPage, [$this, 'adminHelpMenu']);
    }

    public function adminSettingsPage()
    {
        ?>
        <div class="wrap">
            <h2><?php echo __('Log Settings', 'rrze-log'); ?></h2>
            <form method="post" action="options.php">
            <?php settings_fields('rrzelog_options'); ?>
            <?php do_settings_sections('rrzelog_options'); ?>
            <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function adminSettingsSections()
    {
        register_setting('rrzelog_options', $this->optionName, [$this, 'optionsValidate']);
        add_settings_section('rrzelog_section', false, '__return_false', 'rrzelog_options');
        add_settings_field('rrzelog-enable', __('Enable Log', 'rrze-log'), [$this, 'enabledField'], 'rrzelog_options', 'rrzelog_section');
        add_settings_field('rrzelog-threshold', __('Error Level', 'rrze-log'), [$this, 'thresholdField'], 'rrzelog_options', 'rrzelog_section');
    }

    public function optionsValidate($input)
    {
        $input['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $input_threshold = !empty($input['threshold']) ? (array) $input['threshold'] : [];

        $this->options->threshold = 0;

        $levels = $this->log->getErrorLevels();

        foreach ($levels as $level => $bitmask) {
            if (isset($input_threshold[$level])) {
                $this->set_threshold($bitmask);
            }
        }

        $input['threshold'] = $this->options->threshold;

        return $input;
    }

    public function adminHelpMenu()
    {
        $content = [
            '<p></p>',
        ];


        $help_tab = [
            'id' => $this->adminSettingsPage,
            'title' => __('Overview', 'rrze-log'),
            'content' => implode(PHP_EOL, $content),
        ];

        $help_sidebar = sprintf('<p><strong>%1$s:</strong></p><p><a href="http://blogs.fau.de/webworking">RRZE-Webworking</a></p><p><a href="https://github.com/RRZE-Webteam">%2$s</a></p>', __('For more information:', 'rrze-log'), __('RRZE Webteam on Github', 'rrze-log'));

        $screen = get_current_screen();

        if ($screen->id != $this->adminSettingsPage) {
            return;
        }

        $screen->add_help_tab($help_tab);

        $screen->set_help_sidebar($help_sidebar);
    }

    public function networkSettingsMenu()
    {
        if (isset($_POST['_wpnonce']) &&  wp_verify_nonce($_POST['_wpnonce'], 'rrzelog_network-options') && current_user_can('manage_network_options')) {
            if (isset($_POST['rrzelog-site-submit'])) {
                $this->options->enabled = !empty($_POST[$this->optionName]['enabled']) ? 1 : 0;
                $input_threshold = !empty($_POST[$this->optionName]['threshold']) ? (array) $_POST[$this->optionName]['threshold'] : [];

                $this->options->threshold = 0;

                $levels = $this->log->getErrorLevels();

                foreach ($levels as $level => $bitmask) {
                    if (isset($input_threshold[$level])) {
                        $this->set_threshold($bitmask);
                    }
                }

                update_site_option($this->optionName, $this->options);

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
            [$this, 'networkPage']
        );
    }

    public function networkPage()
    {
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

    public function networkSettingsSections()
    {
        add_settings_section('rrzelog_section', false, '__return_false', 'rrzelog_network');
        add_settings_field('rrzelog-enable', __('Enable Log', 'rrze-log'), [$this, 'enabledField'], 'rrzelog_network', 'rrzelog_section');
        add_settings_field('rrzelog-threshold', __('Error Level', 'rrze-log'), [$this, 'thresholdField'], 'rrzelog_network', 'rrzelog_section');
    }

    public function enabledField()
    {
        ?>
        <label>
            <input type="checkbox" id="rrzelog-enabled" name="<?php printf('%s[enabled]', $this->optionName); ?>" value="1"<?php checked($this->options->enabled, 1); ?>>
        </label>
        <?php
    }

    public function thresholdField()
    {
        $levels = $this->log->getErrorLevels(); ?>
        <label for="rrzelog-threshold">
            <?php foreach ($levels as $level => $bitmask) :?>
            <input type="checkbox" id="<?php printf('rrzelog-level-%s', strtolower($level)); ?>" name="<?php printf('%s[threshold][%s]', $this->optionName, $level); ?>" value="1"<?php checked($this->getThreshold($bitmask), 1); ?>> <?php echo $level; ?> </br>
            <?php endforeach; ?>
        </label>
        <?php
    }

    protected function getThreshold($bitmask)
    {
        return ($this->options->threshold & (1 << $bitmask)) != 0;
    }

    protected function set_threshold($bitmask, $new = true)
    {
        $this->options->threshold = ($this->options->threshold & ~(1 << $bitmask)) | ($new << $bitmask);
    }
}
