<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\DebugLogParser;
use WP_List_Table;

/**
 * Debug List Table
 */
class DebugListTable extends WP_List_Table
{
    /**
     * Options values.
     * @var object
     */
    public $options;

    /**
     * Constructor
     * @return void
     */
    public function __construct()  {
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
        return isset($item[$columnName]) ? $item[$columnName] : '';
    }

    public function column_datetime($item)
    {
        $raw = (string) ($item['datetime'] ?? '');
        $ts  = strtotime($raw);
        if (!$ts) {
            return '—';
        }

        $utc = gmdate('Y/m/d G:i:s \U\T\C', $ts);

        $dt = new \DateTimeImmutable('@' . $ts);
        $dt = $dt->setTimezone(wp_timezone());
        $local = $dt->format('Y/m/d G:i:s');

        return sprintf(
            '<span title="%s">%s</span>',
            esc_attr($utc),
            esc_html($local)
        );
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
            'message' => __('Message', 'rrze-log'),
            'datetime' => __('Date', 'rrze-log'),
            'toggle'  => __('Details', 'rrze-log')
        ];
        if (!is_network_admin() && $this->options->adminMenu) {
            unset($columns['siteurl']);
        }
        return $columns;
    }

    public function single_row($item)   {
        $message = $item['message'] ?? '';
        $safe = esc_html($message);
        $safe = preg_replace('/[ \t]+/', ' ', $safe);
        $item['message'] = nl2br($safe);
        $item['toggle'] ='<button type="button" class="rrze-log-toggle" aria-expanded="false" aria-label="Details anzeigen"> ▸ </button>';  
        echo '<tr class="data">';
        $this->single_row_columns($item);


        echo '</tr>';
        printf('<tr class="metadata metadata-hidden"> <td colspan=%d>', count($this->get_columns()));
        $item['datetime'] = get_date_from_gmt($item['datetime'], __('Y/m/d') . ' G:i:s');
        print_r($item);
        echo '</td> </tr>';
    }

    public function prepare_items()    {
        $s = $_REQUEST['s'] ?? '';
        $level = $_REQUEST['level'] ?? '';

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $perPage = $this->get_items_per_page('rrze_log_per_page');
        $currentPage = $this->get_pagenum();

        $logFile = Constants::DEBUG_LOG_FILE;

        $search = array_map('trim', explode(' ', trim($s)));

        if ($level) {
            $search[] = '"level":"' . trim($level) . '"';
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
            <?php foreach (Constants::DEBUG_LEVELS as $level) :
                $selected = $levelFilter == $level ? ' selected = "selected"' : ''; ?>
                <option value="<?php echo $level; ?>" <?php echo $selected; ?>><?php echo $level; ?></option>
            <?php endforeach; ?>
        </select>
<?php
    }
}
