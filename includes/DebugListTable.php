<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\DebugLogParser;
use WP_List_Table;

/**
 * [ListTable description]
 */
class DebugListTable extends WP_List_Table
{
    /**
     * Options values.
     * @var object
     */
    public $options;

    /**
     * [__construct description]
     */
    public function __construct()
    {
        global $status, $page;

        $this->options = Options::getOptions();
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
        return isset($item[$columnName]) ? $item[$columnName] : '';
    }

    /**
     * [column_date description]
     * @param  array $item [description]
     * @return string       [description]
     */
    public function column_datetime($item)
    {
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
        $columns = [
            'level' => __('Error level', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'datetime' => __('Date', 'rrze-log')
        ];
        if (!is_network_admin() && $this->options->adminMenu) {
            unset($columns['siteurl']);
        }
        return $columns;
    }

    /**
     * [single_row description]
     * @param  array $item [description]
     */
    public function single_row($item)
    {
        echo '<tr class="data">';
        $this->single_row_columns($item);
        echo '</tr>';
        printf('<tr class="metadata metadata-hidden"> <td colspan=%d>', count($this->get_columns()));
        printf('<pre>%1$s</pre>', print_r($item, true));
        echo '</td> </tr>';
        echo '<tr class="hidden"> </tr>';
    }

    /**
     * [prepare_items description]
     */
    public function prepare_items()
    {
        $s = !empty($_REQUEST['s']) ? array_map('trim', explode(' ', trim($_REQUEST['s']))) : '';
        $level = !empty($_REQUEST['level']) && in_array($_REQUEST['level'], CONSTANTS::DEBUG_LEVELS) ? $_REQUEST['level'] : '';

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $perPage = $this->get_items_per_page('rrze_log_per_page', 1);
        $currentPage = $this->get_pagenum();

        $logFile = WP_CONTENT_DIR . '/debug.log';

        $search = [];
        if ($s) {
            $search[] = $s;
        }
        if ($level) {
            $search[] = '"level":"' . $level . '"';
        }
        $logParser = new DebugLogParser($logFile, $search, (($currentPage - 1) * $perPage), $perPage);
        $items = $logParser->getItems();
        if (!is_wp_error($items)) {
            foreach ($items as $value) {
                $this->items[] = $value;
            }
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

    /**
     * [extra_tablenav description]
     * @param  string $which [description]
     */
    protected function extra_tablenav($which)
    {
?>
        <div class="alignleft actions">
            <?php
            if ('top' === $which) {
                ob_start();

                $this->levelsDropdown();

                $output = ob_get_clean();

                if (!empty($output)) {
                    echo $output;
                    submit_button(__('Filter'), '', 'filter_action', false, ['id' => 'rrze-log-level-submit']);
                }
            } ?>
        </div>
    <?php
    }

    /**
     * [levelsDropdown description]
     */
    protected function levelsDropdown()
    {
        $levelFilter = isset($_REQUEST['level']) ? $_REQUEST['level'] : ''; ?>
        <select id="levels-filter" name="level">
            <option value=""><?php _e('All error levels', 'rrze-log'); ?></option>
            <?php foreach (Constants::DEBUG_LEVELS as $level) :
                $selected = $levelFilter == $level ? ' selected = "selected"' : ''; ?>
                <option value="<?php echo $level; ?>" <?php echo $selected; ?>><?php echo $level; ?></option>
            <?php endforeach; ?>
        </select>
<?php
    }

    /**
     * [verifyLogfileFormat description]
     * @param  string $date [description]
     * @return boolean       [description]
     */
    protected function verifyLogfileFormat($date)
    {
        $dt = \DateTime::createFromFormat("Y-m-d", $date);
        return $dt !== false && !array_sum($dt::getLastErrors());
    }
}
