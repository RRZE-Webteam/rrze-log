<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\DB;
use WP_List_Table;

/**
 * [ListTable description]
 */
class ListTable extends WP_List_Table
{
    /**
     * [protected description]
     * @var string
     */
    protected $pluginFile;

    /**
     * [protected description]
     * @var object
     */
    protected $db;

    /**
     * [protected description]
     * @var array
     */
    const LEVELS = ['ERROR', 'WARNING', 'NOTICE', 'INFO'];
    
    public function __construct($pluginFile)
    {
        global $status, $page;

        $this->pluginFile = $pluginFile;

        parent::__construct([
            'singular' => 'log',
            'plural' => 'logs',
            'ajax' => false
        ]);
    }

    /**
     * [column_default description]
     * @param  array $item        [description]
     * @param  string $columnName [description]
     * @return string              [description]
     */
    public function column_default($item, $columnName)
    {
        switch ($columnName) {
            case 'level':
            case 'blog_id':
                return isset($item[$columnName]) ? $item[$columnName] : '';
            default:
                return 'hello';
        }
    }

    /**
     * [column_cb description]
     * @param  array $item [description]
     * @return string       [description]
     */
    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s">',
            $this->_args['singular'],
            $item['__id']
        );
    }

    /**
     * Display row actions
     * @param  array $item [description]
     * @return string       [description]
     */
    public function column_level($item)
    {
        $page = $_REQUEST['page'];
        $id = $item['__id'];

        $actions = [
            'edit' => sprintf('<a href="?page=%1$s&action=%2$s&id=%3$s">%4$s</a>', $page, 'edit', $id, __('Edit', 'rrze-log'))
        ];

        return sprintf(
            '%1$s %2$s',
            $item['level'],
            $this->row_actions($actions)
        );
    }

    /**
     * [get_columns description]
     * @return array [description]
     */
    public function get_columns()
    {
        return [
            'cb' => '<input type="checkbox">',
            'level' => __('Level', 'rrze-log'),
            'blog_id' => __('Blog', 'rrze-log')
        ];
    }

    /**
     * [prepare_items description]
     * @return void
     */
    public function prepare_items()
    {
        //Retrieve $customvar for use in query to get items.
        $customvar = (isset($_REQUEST['customvar']) ? $_REQUEST['customvar'] : 'all');

        $fields = '__id,level,blog_id';
        $level = isset($_GET['level']) && in_array($_GET['level'], static::LEVELS) ? $_GET['level'] : '';
        
        $this->process_bulk_action();

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $perPage = $this->get_items_per_page('rrze_log_per_page', 20);
        $currentPage = $this->get_pagenum();
        
        //$this->items = $db->select('__id,level,blog_id')->where('content.plugin', 'LIKE', 'rrze-log')->limit($perPage, ($currentPage - 1) * $perPage)->results();
        //$totalItems = $db->where('content.plugin', 'LIKE', 'rrze-log')->count();
        /**
        if ($level) {
            $this->items = $this->db->where('level', 'LIKE', $level)
                ->limit($perPage, (($currentPage - 1) * $perPage))
                ->results();
            $totalItems = $this->db->where('level', 'LIKE', $level)->count();
        } else {
            $this->items = $this->db->select($fields)
                ->limit($perPage, (($currentPage - 1) * $perPage))
                ->results();
            $totalItems = $this->db->count();
        }
        */
        
        $logPath = plugin_dir_path($this->pluginFile) . Logger::LOG_DIR . DIRECTORY_SEPARATOR;
        $logFile = sprintf('%1$s%2$s.log', $logPath, date('Y-m-d'));
        
        $logParser = new LogParser($logFile, (($currentPage - 1) * $perPage), $perPage);
        $this->items = [];
        foreach ($logParser->getItems() as $key => $value) {
            $this->items[] = json_decode($value, true);
        }

        $totalItems = $logParser->getTotalLines();
        \RRZE\Dev\dLog($totalItems);
        $this->set_pagination_args(
            [
                'total_items' => $totalItems, // Total number of items
                'per_page' => $perPage, // How many items to show on a page
                'total_pages' => ceil($totalItems / $perPage)   // Total number of pages
            ]
        );
    }

    protected function extra_tablenav($which)
    {
        ?>
		<div class="alignleft actions">
		<?php
        if ('top' === $which) {
            ob_start();

            $this->levelsDropdown();
            //$this->pluginsDropdown();

            $output = ob_get_clean();

            if (! empty($output)) {
                echo $output;
                submit_button(__('Filter'), '', 'filter_action', false, ['id' => 'rrze-log-level-submit']);
            }
        } ?>
		</div>
		<?php
    }

    protected function levelsDropdown()
    {
        $levelFilter = isset($_GET['level']) ? $_GET['level'] : ''; ?>
        <select id="levels-filter" name="level">
            <option value=""><?php _e('All levels'); ?></option>
            <?php foreach (static::LEVELS as $level) :
                $selected = $levelFilter == $level ? ' selected = "selected"' : ''; ?>
            <option value="<?php echo $level; ?>"<?php echo $selected; ?>><?php echo $level; ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    protected function pluginsDropdown()
    {
        $pluginFilter = isset($_GET['plugin']) ? $_GET['plugin'] : '';
        $plugins = $this->db->select('content.plugin')
            ->where('content.plugin', '!=', '')
            ->results();

        $key = 'content.plugin';
        $plugins = $this->arryUnique($plugins, $key);
        if (empty($plugins)) {
            return;
        } ?>
        <select id="plugins-filter" name="plugin">
            <option value=""><?php _e('All plugins'); ?></option>
            <?php foreach ($plugins as $plugin) :
                $selected = $pluginFilter == $plugin[$key] ? ' selected = "selected"' : ''; ?>
            <option value="<?php echo $plugin[$key]; ?>"<?php echo $selected; ?>><?php echo $plugin[$key]; ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    protected function arryUnique($ary, $key)
    {
        $tmpAry = [];
        $i = 0;
        $keyAry = [];
   
        foreach ($ary as $val) {
            if (!in_array($val[$key], $keyAry)) {
                $keyAry[$i] = $val[$key];
                $tmpAry[$i] = $val;
            }
            $i++;
        }
        return $tmpAry;
    }
}
