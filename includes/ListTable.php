<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\Logger;
use RRZE\Log\LogParser;
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
    protected $logPath;

    /**
     * [__construct description]
     */
    public function __construct()
    {
        global $status, $page;

        $this->logPath = Logger::LOG_DIR . DIRECTORY_SEPARATOR;
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
            case 'siteurl':
                return isset($item[$columnName]) ? parse_url($item[$columnName], PHP_URL_HOST) . parse_url($item[$columnName], PHP_URL_PATH) : '';
            default:
                return isset($item[$columnName]) ? $item[$columnName] : '';
        }
    }

    /**
     * [column_date description]
     * @param  array $item [description]
     * @return string       [description]
     */
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
            'siteurl' => __('Website', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'datetime' => __('Date', 'rrze-log')
        ];
    }

    /**
     * [single_row description]
     * @param  array $item [description]
     */
    public function single_row($item) {
        echo '<tr class="data">';
        $this->single_row_columns( $item );
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
        $level = !empty($_REQUEST['level']) && in_array($_REQUEST['level'], Logger::LEVELS) ? $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) && $this->verifyLogfileFormat($_REQUEST['logfile']) ? $_REQUEST['logfile'] : date('Y-m-d');

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $perPage = $this->get_items_per_page('rrze_log_per_page', 20);
        $currentPage = $this->get_pagenum();

        $logFile = sprintf('%1$s%2$s.log', $this->logPath, $logFile);

        $search = [];
        if ($s) {
            $search[] = $s;
        }
        if ($level) {
            $search[] = '"level":"' . $level . '"';
        }
        $logParser = new LogParser($logFile, $search, (($currentPage - 1) * $perPage), $perPage);
        if (!is_wp_error($logParser)) {
            foreach ($logParser->getItems() as $value) {
                $this->items[] = json_decode($value, true);
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
            $this->logFilesDropdown();

            $output = ob_get_clean();

            if (! empty($output)) {
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
            <option value=""><?php _e('All levels'); ?></option>
            <?php foreach (Logger::LEVELS as $level) :
                $selected = $levelFilter == $level ? ' selected = "selected"' : ''; ?>
            <option value="<?php echo $level; ?>"<?php echo $selected; ?>><?php echo $level; ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * [logFilesDropdown description]
     */
    protected function logFilesDropdown()
    {
        $logFilesFilter = isset($_REQUEST['logfile']) ? $_REQUEST['logfile'] : date('Y-m-d');
        $logFiles = [];
        foreach (new \DirectoryIterator($this->logPath) as $file) {
            if ($file->isFile()) {
                $logFiles[$file->getFilename()] = $file->getBasename('.' . $file->getExtension());
            }
        }

        if (count($logFiles) < 2) {
            return;
        }
        krsort($logFiles); ?>
        <select id="logfiles-filter" name="logfile">
            <?php foreach ($logFiles as $logfile) :
                $selected = $logFilesFilter == $logfile ? ' selected = "selected"' : ''; ?>
            <option value="<?php echo $logfile; ?>"<?php echo $selected; ?>><?php echo $logfile; ?></option>
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
