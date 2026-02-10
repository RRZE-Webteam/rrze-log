<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_List_Table;

/**
 * List Table
 */
class ListTable extends WP_List_Table {
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

    public function column_default($item, $columnName)  {
        switch ($columnName) {
            case 'siteurl':
                return isset($item[$columnName]) ? parse_url($item[$columnName], PHP_URL_HOST) . parse_url($item[$columnName], PHP_URL_PATH) : '';
            default:
                return isset($item[$columnName]) ? $item[$columnName] : '';
        }
    }

    public function column_siteurl($item)  {
        return untrailingslashit($item['siteurl']);
    }

    public function column_datetime($item)  {
        $raw = (string) ($item['datetime'] ?? '');
        return Utils::formatDatetimeWithUtcTooltip($raw);
    }

    /**
     * Render the "message" column.
     *
     * @param array|object $item Current row item.
     * @return string
     */
    public function column_message($item)
    {
        $text = is_array($item) ? ($item['message'] ?? '') : ($item->message ?? '');
        $excerpt = wp_html_excerpt($text, 400, '…');
        return esc_html($excerpt);
    }

    public function get_columns()  {
        $columns = [
            'level' => __('Error level', 'rrze-log'),
            'siteurl' => __('Website', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'datetime' => __('Date', 'rrze-log'),
            'toggle'  => __('Details', 'rrze-log')
        ];
        if (!is_network_admin() && $this->options->adminMenu) {
            unset($columns['siteurl']);
        }
        return $columns;
    }

    public function single_row($item) {
        $detail = $item['context']['detail'] ?? '';
        $safe = esc_html($detail);
        $safe = preg_replace('/[ \t]+/', ' ', $safe);
        $item['context']['detail'] = nl2br($safe);
        $item['toggle'] ='<button type="button" class="rrze-log-toggle" aria-expanded="false" aria-label="Details anzeigen"> ▸ </button>';  
        echo '<tr class="data">';
        $this->single_row_columns($item);        
        echo '</tr>';
        
        printf('<tr class="metadata"> <td colspan=%d>', count($this->get_columns()));
        $item['datetime'] = get_date_from_gmt($item['datetime'], __('Y/m/d') . ' G:i:s');
        print_r($item);
        echo '</td> </tr>';
    }

    public function prepare_items()  {
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

    protected function extra_tablenav($which)  {
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
    protected function levelsDropdown()   {
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
