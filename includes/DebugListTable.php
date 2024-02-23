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
     * Absolut Log Path
     *
     * @var string
     */
    protected $logPath;

    /**
     * Constructor
     *
     * @param string $logPath
     */
    public function __construct(string $logPath)
    {
        global $status, $page;

        $this->options = Options::getOptions();
        $this->logPath = $logPath;
        $this->items = [];

        parent::__construct([
            'singular' => 'log',
            'plural' => 'logs',
            'ajax' => false
        ]);
    }

    public function column_default($item, $columnName)
    {
        return isset($item[$columnName]) ? $item[$columnName] : '';
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
        printf('<pre style="white-space: pre-wrap;">%1$s</pre>', print_r($item, true));
        echo '</td> </tr>';
        echo '<tr class="hidden"> </tr>';
    }

    public function prepare_items()
    {
        $s = !empty($_REQUEST['s']) ? array_map('trim', explode(' ', trim($_REQUEST['s']))) : '';
        $level = !empty($_REQUEST['level']) && in_array($_REQUEST['level'], CONSTANTS::DEBUG_LEVELS) ? $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) && Utils::verifyLogfileFormat($_REQUEST['logfile']) ? $_REQUEST['logfile'] : date('Y-m-d');

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $perPage = $this->get_items_per_page('rrze_log_per_page', 1);
        $currentPage = $this->get_pagenum();

        $logFile = sprintf('%1$s%2$s.log', $this->logPath, $logFile);

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

    protected function extra_tablenav($which)
    {
?>
        <div class="alignleft actions">
            <?php
            if ('top' === $which) {
                ob_start();

                $this->levelsDropdown();
                $this->logFilesDropdown();

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

    /**
     * Dropdown with log files.
     */
    protected function logFilesDropdown()
    {
        $logFilesFilter = isset($_REQUEST['logfile']) ? $_REQUEST['logfile'] : date('Y-m-d');
        $logFiles = [];
        if (!is_dir($this->logPath)) {
            return;
        }
        foreach (new \DirectoryIterator($this->logPath) as $file) {
            if ($file->isFile()) {
                $logfile = $file->getBasename('.' . $file->getExtension());
                if (Utils::verifyLogfileFormat($logfile)) {
                    $logFiles[$logfile] = $logfile;
                }
            }
        }

        if (count($logFiles) < 2) {
            return;
        }
        krsort($logFiles); ?>
        <select id="logfiles-filter" name="logfile">
            <?php foreach ($logFiles as $logfile) :
                $selected = $logFilesFilter == $logfile ? ' selected = "selected"' : ''; ?>
                <option value="<?php echo $logfile; ?>" <?php echo $selected; ?>><?php echo $logfile; ?></option>
            <?php endforeach; ?>
        </select>
<?php
    }
}
