<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_List_Table;

class ListTable extends WP_List_Table {
    public $options;
    protected string $orderby = 'datetime';
    protected string $order = 'desc';

    public function __construct() {
        $this->options = Options::getOptions();
        $this->items = [];

        parent::__construct([
            'singular' => 'log',
            'plural' => 'logs',
            'ajax' => false
        ]);
    }

    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $classes[] = 'rrze-log-actionlog';
        return $classes;
    }

    public function get_columns() {
        $columns = [
            'datetime' => __('Time', 'rrze-log'),
            'level' => __('Level', 'rrze-log'),
            'siteurl'  => __('Website', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
        ];

        return $columns;
    }

    public function get_sortable_columns(): array {
        return [
            'level'    => ['level', false],
            'siteurl'  => ['siteurl', false],
            'message'  => ['message', false],
            'datetime' => ['datetime', true],
        ];
    }

    public function column_default($item, $columnName) {
        if (!is_array($item)) {
            return '';
        }

        switch ($columnName) {
            case 'datetime':
                return Utils::formatDatetimeWithUtcTooltip((string) ($item['datetime'] ?? ''), 'Y-m-d H:i:s', 'Y-m-d H:i:s \U\T\C');
            case 'level':
                return esc_html((string) ($item['level'] ?? ''));
            case 'occurrences':
                return esc_html((string) (isset($item['occurrences']) ? (int) $item['occurrences'] : 1));
            case 'message':
                return $this->renderMessageCell($item);
            default:
                return isset($item[$columnName]) ? esc_html((string) $item[$columnName]) : '';
        }
    }

    protected function renderMessageCell(array $item): string {
        $message = (string) ($item['message'] ?? '');
        $hasContext = $this->hasContext($item);

        if (!$hasContext) {
            return esc_html($message);
        }

        $contextText = $this->stringifyContext($item['context'] ?? null);
        $contextText = trim($contextText);

        $messageHtml = sprintf(
            '<a href="#" class="rrze-log-message-toggle" aria-expanded="false">%s</a>',
            esc_html($message)
        );

        $detailsHtml = sprintf(
            '<div class="rrze-log-message-full" aria-hidden="true"><pre>%s</pre></div>',
            esc_html($contextText)
        );

        $copyHtml = sprintf(
            '<button type="button" class="button-link rrze-log-copy" aria-label="%s" title="%s" data-copy="%s">' .
            '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>' .
            '</button>',
            esc_attr__('Copy details', 'rrze-log'),
            esc_attr__('Copy', 'rrze-log'),
            esc_attr($contextText)
        );

        return $messageHtml . $detailsHtml . $copyHtml;
    }
    
    protected function hasContext(array $item): bool {
        if (!isset($item['context'])) {
            return false;
        }

        $ctx = $item['context'];

        if (is_string($ctx)) {
            return trim($ctx) !== '';
        }

        if (is_array($ctx)) {
            return !empty($ctx);
        }

        if (is_object($ctx)) {
            return true;
        }

        return false;
    }

    public function column_siteurl($item) {
        $url = isset($item['siteurl']) ? (string) $item['siteurl'] : '';
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (is_array($parts) && isset($parts['host'])) {
            $host = (string) $parts['host'];
            $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
            return esc_html($host . $path);
        }

        // Fallback falls wp_parse_url nichts liefert
        $clean = preg_replace('#^https?://#i', '', $url);
        return esc_html(rtrim((string) $clean, '/'));
    }
    
    protected function stringifyContext($ctx): string {
        if ($ctx === null) {
            return '';
        }

        if (is_string($ctx)) {
            return $ctx;
        }

        if (is_array($ctx)) {
            return print_r($ctx, true);
        }

        if (is_object($ctx)) {
            if ($ctx instanceof \JsonSerializable) {
                $json = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return is_string($json) ? $json : print_r($ctx, true);
            }

            if (method_exists($ctx, '__toString')) {
                return (string) $ctx;
            }

            return print_r($ctx, true);
        }

        return (string) $ctx;
    }

    public function single_row($item) {
        if (!is_array($item)) {
            return;
        }

        $context = $item['context'] ?? null;
        $hasDetails = !empty($context);

        $full = '';
        if ($hasDetails) {
            if (is_array($context) || is_object($context)) {
                $full = print_r($context, true);
            } else {
                $full = (string) $context;
            }
        }

        echo '<tr class="data level-' . esc_attr(strtolower((string)($item['level'] ?? 'other'))) . '">';

        foreach (array_keys($this->get_columns()) as $col) {

            echo '<td class="column-' . esc_attr($col) . '">';

            if ($col === 'datetime') {
                echo Utils::formatDatetimeWithUtcTooltip((string)($item['datetime'] ?? ''), 'Y-m-d H:i:s','Y-m-d H:i:s \U\T\C');
            } elseif ($col === 'level') {
                echo esc_html((string)($item['level'] ?? ''));
            } elseif ($col === 'siteurl') {
                echo $this->column_siteurl($item);
            } elseif ($col === 'message') {

                $msg = (string)($item['message'] ?? '');

                if ($hasDetails) {

                    echo '<a href="#" class="rrze-log-message-toggle" aria-expanded="false">'
                        . esc_html($msg)
                        . '</a>';

                    $contextHtml = Utils::renderContextTree($context);
                    $copyText = $this->stringifyContext($context);

                    echo '<div class="rrze-log-message-full" aria-hidden="true">';

                    echo '<div class="rrze-log-context-tree">';
                    echo $contextHtml;
                    echo '</div>';

                    echo '<button type="button" class="button-link rrze-log-copy dashicons-before dashicons-clipboard"'
                        . ' data-copy="' . esc_attr($full) . '"'
                        . ' title="' . esc_attr__('Copy full details', 'rrze-log') . '">'
                        . '</button>';

                    echo '</div>';

                } else {
                    echo esc_html($msg);
                }
            }

            echo '</td>';
        }

        echo '</tr>';
    }

    public function prepare_items() {
        $s = isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '';
        $level = isset($_REQUEST['level']) ? (string) $_REQUEST['level'] : '';
        $logFile = isset($_REQUEST['logfile']) ? (string) $_REQUEST['logfile'] : '';

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $this->orderby = isset($_REQUEST['orderby']) ? sanitize_key((string) $_REQUEST['orderby']) : 'datetime';
        $this->order = isset($_REQUEST['order']) ? strtolower((string) $_REQUEST['order']) : 'desc';
        $this->order = $this->order === 'asc' ? 'asc' : 'desc';

        $perPage = (int) $this->get_items_per_page('rrze_log_per_page');
        if ($perPage <= 0) {
            $perPage = 20;
        }

        $currentPage = (int) $this->get_pagenum();
        if ($currentPage <= 0) {
            $currentPage = 1;
        }

        $search = array_map('trim', explode(' ', trim($s)));
        $search = array_filter($search, 'strlen');

        if ($level !== '') {
            $search[] = '"level":"' . trim($level) . '"';
        }

        $logFilePath = $logFile !== '' ? $logFile : Constants::LOG_FILE;

        $parser = new LogParser(
            $logFilePath,
            $search,
            (($currentPage - 1) * $perPage),
            $perPage,
            true
        );

        $items = $parser->getItemsDecoded();

        $this->items = [];
        if (!is_wp_error($items)) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (!isset($row['datetime'])) {
                    $row['datetime'] = '';
                }
                if (!isset($row['level'])) {
                    $row['level'] = '';
                }
                if (!isset($row['message'])) {
                    $row['message'] = '';
                }
                if (!isset($row['siteurl'])) {
                    $row['siteurl'] = '';
                }
                if (!isset($row['context'])) {
                    $row['context'] = null;
                }

                $this->items[] = $row;
            }
        }

        if (!empty($this->items)) {
            usort($this->items, [$this, 'compareItems']);
        }

        $totalItems = (int) $parser->getTotalLines();

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'total_pages' => $perPage > 0 ? (int) ceil($totalItems / $perPage) : 1,
        ]);
    }
    
    
    public function compareItems(array $a, array $b): int {
        $dir = $this->order === 'asc' ? 1 : -1;

        $va = $this->getSortValue($a, $this->orderby);
        $vb = $this->getSortValue($b, $this->orderby);

        if ($va === $vb) {
            return 0;
        }

        if (is_int($va) && is_int($vb)) {
            return ($va < $vb ? -1 : 1) * $dir;
        }

        $cmp = strcasecmp((string) $va, (string) $vb);
        return ($cmp < 0 ? -1 : 1) * $dir;
    }

    protected function getSortValue(array $item, string $key) {
        switch ($key) {
            case 'datetime':
                $ts = strtotime((string) ($item['datetime'] ?? ''));
                return $ts === false ? 0 : (int) $ts;
            case 'level':
                return (int) Utils::levelWeight((string) ($item['level'] ?? ''));
            case 'siteurl':
                return (string) ($item['siteurl'] ?? '');
            case 'message':
                return (string) ($item['message'] ?? '');
            default:
                $ts = strtotime((string) ($item['datetime'] ?? ''));
                return $ts === false ? 0 : (int) $ts;
        }
    }

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

    protected function levelsDropdown() {
        $levelFilter = isset($_REQUEST['level']) ? (string) $_REQUEST['level'] : '';
        ?>
        <select id="levels-filter" name="level">
            <option value=""><?php _e('All error levels', 'rrze-log'); ?></option>
            <?php foreach (Constants::LEVELS as $level) { ?>
                <option value="<?php echo esc_attr((string) $level); ?>"<?php selected($levelFilter, $level); ?>>
                    <?php echo esc_html((string) $level); ?>
                </option>
            <?php } ?>
        </select>
        <?php
    }
}