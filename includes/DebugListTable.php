<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\DebugLogParser;
use WP_List_Table;

/**
 * Debug List Table
 */
class DebugListTable extends WP_List_Table {

    /**
     * Options values.
     * @var object
     */
    public $options;

    /**
    * Current orderby.
    * @var string
    */
   protected string $orderby = 'datetime';

   /**
    * Current order.
    * @var string
    */
   protected string $order = 'desc';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->options = Options::getOptions();
        $this->items = [];

        parent::__construct([
            'singular' => 'log',
            'plural' => 'logs',
            'ajax' => false,
        ]);
    }

    /*
     * Table Class
     */
    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $classes[] = 'rrze-log-debug';
        return $classes;
    }
    
    /**
     * Columns.
     */
    public function get_columns() {
        return [
            'datetime' => __('Time', 'rrze-log'),
            'level' => __('Level', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'occurrences' => __('#', 'rrze-log'),
        ];
    }

    /**
     * Default column renderer.
     */
    public function column_default($item, $columnName) {
        if (!is_array($item)) {
            return '';
        }

        switch ($columnName) {
            case 'datetime':
                return Utils::formatDatetimeWithUtcTooltip(
                    (string) ($item['datetime'] ?? ''),
                    'Y-m-d H:i:s',
                    'Y-m-d H:i:s \U\T\C'
                );
            case 'level':
                return esc_html((string) ($item['level'] ?? ''));
            case 'message':
                return $this->renderMessageCell($item);
            case 'occurrences':
                return esc_html((string) (isset($item['occurrences']) ? (int) $item['occurrences'] : 1));
            default:
                return '';
        }
    }

    /**
     * Render a single row with a level class and inline details inside the message cell.
     */
    public function single_row($item) {
        if (!is_array($item)) {
            return;
        }

        $level = strtolower((string) ($item['level'] ?? ''));
        $level = preg_replace('/[^a-z0-9_\-]/', '', $level);
        if ($level === '') {
            $level = 'unknown';
        }

        echo '<tr class="data level-' . esc_attr($level) . '">';

        $columns = $this->get_columns();
        foreach (array_keys($columns) as $col) {
            $classes = 'column-' . $col;
            echo '<td class="' . esc_attr($classes) . '">';

            if ($col === 'datetime') {
                echo Utils::formatDatetimeWithUtcTooltip(
                    (string) ($item['datetime'] ?? ''),
                    'Y-m-d H:i:s',
                    'Y-m-d H:i:s \U\T\C'
                );
            } elseif ($col === 'level') {
                echo esc_html((string) ($item['level'] ?? ''));
            } elseif ($col === 'message') {
                echo $this->renderMessageCell($item);
            } elseif ($col === 'occurrences') {
                echo esc_html((string) (isset($item['occurrences']) ? (int) $item['occurrences'] : 1));
            }

            echo '</td>';
        }

        echo '</tr>';
    }

    /**
     * Renders the message cell with:
     * - short message as toggle link (only if expandable)
     * - full message hidden in same cell (<pre>)
     */
    protected function renderMessageCell(array $item): string {
        $full = trim((string) ($item['message'] ?? ''));
        $fullMessage = $this->buildFullMessage($item);
        $short = $this->buildShortMessage($item, $fullMessage);

        
        if ($short === '') {
            return '';
        }

        $expandable = $this->isExpandable($short, $full);

        $out = '';

        if ($expandable) {
            $out .= '<a href="#" class="rrze-log-message-toggle" aria-expanded="false">'
                . esc_html($short)
                . '</a>';
            
            $out .= '<div class="rrze-log-message-full" aria-hidden="true">'
                    . '<pre>' . esc_html($fullMessage) . '</pre>'
                    . $this->renderCopyButton($fullMessage)
                    . '</div>';
            
        } else {
            $out .= esc_html($short);
        }

        return $out;
    }

    protected function buildFullMessage(array $item): string {
        if (isset($item['details']) && is_array($item['details']) && !empty($item['details'])) {
            $lines = [];

            foreach ($item['details'] as $line) {
                if (!is_scalar($line)) {
                    continue;
                }
                $lines[] = (string) $line;
            }

            return trim(implode("\n", $lines));
        }

        return trim((string) ($item['message'] ?? ''));
    }

    protected function buildShortMessage(array $item, string $full): string {
        if (isset($item['message_short']) && is_string($item['message_short']) && $item['message_short'] !== '') {
            return (string) $item['message_short'];
        }

        return $full;
    }


    /**
     * Copy link in last column, only visible when row is expanded.
     */
   protected function renderCopyButton(string $fullMessage): string {
        if ($fullMessage === '') {
            return '';
        }

        return sprintf(
            '<button type="button" class="button-link rrze-log-copy" aria-label="%s" title="%s" data-copy="%s">'
                . '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>'
            . '</button>',
            esc_attr__('Copy full message', 'rrze-log'),
            esc_attr__('Copy', 'rrze-log'),
            esc_attr($fullMessage)
        );
    }



    /**
     * Expand only if there is a meaningful difference.
     * - If short == full -> no toggle.
     */
    protected function isExpandable(string $short, string $full): bool {
        if ($full === '') {
            return false;
        }

        if ($short === $full) {
            return false;
        }

        if (mb_strlen($short) === mb_strlen($full)) {
            return false;
        }

        return true;
    }

    /**
     * Prepare list items.
     */
    public function prepare_items() {
        $s = $_REQUEST['s'] ?? '';
        $level = $_REQUEST['level'] ?? '';

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [$columns, $hidden, $sortable];

        $perPage = $this->get_items_per_page('rrze_log_per_page');
        $currentPage = $this->get_pagenum();

        $logFile = Constants::DEBUG_LOG_FILE;

        $search = array_map('trim', explode(' ', trim((string) $s)));
        $search = array_filter($search);

        $levelFilter = is_string($level) ? strtoupper(trim($level)) : '';
        if ($levelFilter !== '') {
            // Fallback (wirksam auch ohne Parser-Upgrade):
            $search[] = '"level":"' . $levelFilter . '"';
        }

        $useTailChunk = ($levelFilter === '');

        $logParser = new DebugLogParser(
            $logFile,
            $search,
            (($currentPage - 1) * $perPage),
            $perPage,
            $useTailChunk,
            null,
            $levelFilter
        );

        $items = $logParser->getItems();
        if (!is_wp_error($items)) {
            foreach ($items as $value) {
                $this->items[] = $value;
            }
        }
        $this->orderby = isset($_REQUEST['orderby']) ? sanitize_key((string) $_REQUEST['orderby']) : 'datetime';
        $this->order = isset($_REQUEST['order']) ? strtolower((string) $_REQUEST['order']) : 'desc';
        $this->order = $this->order === 'asc' ? 'asc' : 'desc';

        $this->sortItems();
        
        $totalItems = $logParser->getTotalLines();

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($totalItems / $perPage),
        ]);
    }
    /*
     * Sortable Columns
     */
    public function get_sortable_columns() {
        return [
            'datetime' => ['datetime', true],
            'level' => ['level', false],
            'message' => ['message', false],
            'occurrences' => ['occurrences', false],
        ];
    }
    
    protected function sortItems(): void {
        if (empty($this->items)) {
            return;
        }

        usort($this->items, [$this, 'compareItems']);
    }

    protected function compareItems(array $a, array $b): int {
        $dir = $this->order === 'asc' ? 1 : -1;

        $va = $this->getSortValue($a, $this->orderby);
        $vb = $this->getSortValue($b, $this->orderby);

        if ($va === $vb) {
            return 0;
        }

        if (is_numeric($va) && is_numeric($vb)) {
            return ($va < $vb ? -1 : 1) * $dir;
        }

        $cmp = strcasecmp((string) $va, (string) $vb);
        return ($cmp < 0 ? -1 : 1) * $dir;
    }

    protected function getSortValue(array $item, string $key) {
        switch ($key) {
            case 'datetime':
                return $this->getUnixTime($item);
            case 'level':
                return Utils::levelWeight((string) ($item['level'] ?? ''));
            case 'message':
                return (string) ($item['message_short'] ?? ($item['message'] ?? ''));
            case 'occurrences':
                return (int) ($item['occurrences'] ?? 1);
            default:
                return $this->getUnixTime($item);
        }
    }

    protected function getUnixTime(array $item): int {
        $raw = (string) ($item['datetime'] ?? '');
        if ($raw === '') {
            return 0;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return 0;
        }

        return (int) $ts;
    }

    
    
    /**
     * Filter dropdown.
     */
    protected function extra_tablenav($which) {
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
            }
            ?>
        </div>
        <?php
    }

    /**
     * Dropdown with error levels.
     */
    protected function levelsDropdown() {
        $levelFilter = isset($_REQUEST['level']) ? strtoupper(trim((string) $_REQUEST['level'])) : '';

        $parser = new DebugLogParser(
            Constants::DEBUG_LOG_FILE,
            [],
            0,
            -1,
            false,
            null,
            ''
        );

        $available = $parser->getAvailableLevels();

        echo '<select id="levels-filter" name="level">';
        echo '<option value="">' . esc_html(__('All error levels', 'rrze-log')) . '</option>';

        foreach ($available as $level => $count) {
            printf(
                '<option value="%s"%s>%s (%d)</option>',
                esc_attr($level),
                selected($levelFilter, $level, false),
                esc_html($level),
                (int) $count
            );
        }

        echo '</select>';
    }
}
