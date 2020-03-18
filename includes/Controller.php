<?php

namespace RRZE\Log;

use RRZE\Log\ListTable;

defined('ABSPATH') || exit;

class Controller
{
    /**
     * [protected description]
     * @var string
     */
    protected $pluginFile;

    /**
     * [protected description]
     * @var array
     */
    protected $messages = [];

    /**
     * [protected description]
     * @var object
     */
    protected $listTable;

    public function __construct($pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }
    
    public function logPageScreenOptions()
    {
        $option = 'per_page';
        $args = [
            'label' => __('Number of items per page:', 'rrze-log'),
            'default' => 20,
            'option' => 'rrze_log_per_page'
        ];

        add_screen_option($option, $args);
        $this->listTable = new ListTable($this->pluginFile);
    }

    public function logList()
    {
        $this->listTable->prepare_items();

        $action = isset($_GET['action']) ? $_GET['action'] : 'index';

        $data = [
            'action' => $action,
            'listTable' => $this->listTable
        ];
        //\RRZE\Dev\dLog($data);
        $this->show('index/index', $data);
    }
    
    protected function show($view, $data = [])
    {
        if (!current_user_can('update_plugins') || !current_user_can('update_themes')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'rrze-log'));
        }

        $data['messages'] = $this->messages;

        return include 'Views/base.php';
    }
}
