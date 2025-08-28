<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_List_Table;

/**
 * List Table
 */
class ListTable extends WP_List_Table
{
    /**
     * Options values.
     * @var object
     */
    public $options;

    /**
     * Constructor
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

    public function column_default($item, $columnName)
    {
        switch ($columnName) {
            case 'siteurl':
                return isset($item[$columnName]) ? parse_url($item[$columnName], PHP_URL_HOST) . parse_url($item[$columnName], PHP_URL_PATH) : '';
            default:
                return isset($item[$columnName]) ? $item[$columnName] : '';
        }
    }

    public function column_siteurl($item)
    {
        return untrailingslashit($item['siteurl']);
    }

    public function column_datetime($item)
    {
        return sprintf(
            '<span title="%1$s">%2$s</span>',
            get_date_from_gmt($item['datetime'], __('Y/m/d') . ' G:i:s.u'),
            get_date_from_gmt($item['datetime'], __('Y/m/d') . ' H:i:s')
        );
    }

    public function get_columns()
    {
        $columns = [
            'level' => __('Error level', 'rrze-log'),
            'siteurl' => __('Website', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'datetime' => __('Date', 'rrze-log')
        ];
        if (!is_network_admin() && $this->options->adminMenu) {
            unset($columns['siteurl']);
        }
        return $columns;
    }

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

    public function prepare_items()
    {
        $s = $_REQUEST['s'] ?? '';
        $level = $_REQUEST['level'] ?? '';
        $logFile = $_REQUEST['logfile'] ?? '';

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $perPage = $this->get_items_per_page('rrze_log_per_page');
        $currentPage = $this->get_pagenum();

        $search = array_map('trim', explode(' ', trim($s)));

        if ($level) {
            $search[] = '"level":"' . trim($level) . '"';
        }

        $logs = Utils::getLog($logFile, $search, (($currentPage - 1) * $perPage), $perPage);
        $this->items = $logs['items'] ?? [];
        $totalItems = $logs['total_items'] ?? 0;

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
     * Dropdown with error levels.
     */
    protected function levelsDropdown()
    {
        $levelFilter = isset($_REQUEST['level']) ? $_REQUEST['level'] : ''; ?>
        <select id="levels-filter" name="level">
            <option value=""><?php _e('All error levels', 'rrze-log'); ?></option>
            <?php foreach (CONSTANTS::LEVELS as $level) :
                $selected = $levelFilter == $level ? ' selected = "selected"' : ''; ?>
                <option value="<?php echo $level; ?>" <?php echo $selected; ?>><?php echo $level; ?></option>
            <?php endforeach; ?>
        </select>
    <?php
    }
}
