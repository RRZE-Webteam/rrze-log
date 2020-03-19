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
        $this->items = [];

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
                return isset($item[$columnName]) ? $item[$columnName] : '';
            case 'blog_id':
                return isset($item[$columnName]) && absint($item[$columnName]) ? get_site_url($item[$columnName]) : '';
            default:
                return 'hello';
        }
    }

    /**
     * Display row actions
     * @param  array $item [description]
     * @return string       [description]
     */
    public function column_level($item)
    {
        $page = $_REQUEST['page'];

        $actions = [
            //'edit' => sprintf('<a href="?page=%1$s&action=%2$s&id=%3$s">%4$s</a>', $page, 'edit', $id, __('Edit', 'rrze-log'))
        ];

        return sprintf(
            '%1$s %2$s',
            $item['level'],
            $this->row_actions($actions)
        );
    }

    public function column_datetime($item) {
        return sprintf(
            '<span title="%1$s">%2$s</span>',
            get_date_from_gmt($item['datetime'], __('Y/m/d') . ' G:i:s.u'),
            get_date_from_gmt($item['datetime'], __('Y/m/d') . ' H:i:s')
        );
	}

    /**
     * [get_columns description]
     * @return array [description]
     */
    public function get_columns()
    {
        return [
            'level' => __('Level', 'rrze-log'),
            'blog_id' => __('Blog', 'rrze-log'),
            'datetime' => __('Date', 'rrze-log')
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

        $level = isset($_REQUEST['level']) && in_array($_REQUEST['level'], static::LEVELS) ? $_REQUEST['level'] : '';

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $perPage = $this->get_items_per_page('rrze_log_per_page', 20);
        $currentPage = $this->get_pagenum();

        $logPath = plugin_dir_path($this->pluginFile) . Logger::LOG_DIR . DIRECTORY_SEPARATOR;
        $logFile = sprintf('%1$s%2$s.log', $logPath, date('Y-m-d'));

        $search = [];
        if ($level) {
            $search[] = '"level":"' . $level . '"';
        }
        $logParser = new LogParser($logFile, $search, (($currentPage - 1) * $perPage), $perPage);
        if (is_wp_error($logParser)) {
            return;
        }
        foreach ($logParser->getItems() as $key => $value) {
            $this->items[] = json_decode($value, true);
        }

        $totalItems = $logParser->getTotalLines();

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
        $levelFilter = isset($_REQUEST['level']) ? $_REQUEST['level'] : ''; ?>
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
        $pluginFilter = isset($_REQUEST['plugin']) ? $_REQUEST['plugin'] : '';
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
