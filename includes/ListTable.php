<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_List_Table;

class ListTable extends WP_List_Table {
    public $options;
    protected string $orderby = 'datetime';
    protected string $order = 'desc';
    protected string $selectedLevel = 'ERROR';
    protected string $selectedLogFile = '';

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
        if (!is_network_admin()) {
            unset($columns['siteurl']);
        }
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
        $logFile = isset($_REQUEST['logfile']) ? sanitize_text_field((string) wp_unslash($_REQUEST['logfile'])) : '';

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

        $level = strtoupper(trim($level));
        if (!in_array($level, Constants::LEVELS, true)) {
            $level = 'ERROR';
        }

        $this->selectedLevel = $level;
        $this->selectedLogFile = $this->getRequestedLogFileForLevel($level, $logFile);

        $search[] = '"level":"' . trim($level) . '"';

        $this->prepareItemsFromFile(
            $this->selectedLogFile,
            $search,
            $currentPage,
            $perPage
        );
    }

    /**
     * Returns the selected log file for the requested level.
     */
    protected function getRequestedLogFileForLevel(string $level, string $requestedFile): string {
        $files = $this->getLogFilesForLevel($level);
        $currentFile = Constants::getLogFileForLevel($level);

        if ($requestedFile === '') {
            return $currentFile;
        }

        foreach ($files as $file) {
            if ($requestedFile === $file || basename($requestedFile) === basename($file)) {
                return $file;
            }
        }

        return $currentFile;
    }

    /**
     * Returns current and rotated log files for a level.
     */
    protected function getLogFilesForLevel(string $level): array {
        $currentFile = Constants::getLogFileForLevel($level);
        $dir = dirname($currentFile);
        $name = pathinfo($currentFile, PATHINFO_FILENAME);
        $files = [
            $currentFile,
        ];

        if (!is_dir($dir)) {
            return $files;
        }

        $patterns = [
            $dir . '/' . $name . '-daily-[1-7].log',
            $dir . '/' . $name . '-weekly-[1-5].log',
            $dir . '/' . $name . '-monthly-[1-9].log',
            $dir . '/' . $name . '-monthly-1[0-2].log',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (!is_array($matches)) {
                continue;
            }

            foreach ($matches as $match) {
                if (is_file($match)) {
                    $files[] = $match;
                }
            }
        }

        $files = array_values(array_unique($files));
        usort($files, [$this, 'compareLogFiles']);

        return $files;
    }

    /**
     * Sort log files with the current file first, then newest archive files.
     */
    protected function compareLogFiles(string $a, string $b): int {
        $currentFile = Constants::getLogFileForLevel($this->selectedLevel);

        if ($a === $currentFile) {
            return -1;
        }

        if ($b === $currentFile) {
            return 1;
        }

        $aTime = @filemtime($a);
        $bTime = @filemtime($b);
        $aTime = $aTime ? (int) $aTime : 0;
        $bTime = $bTime ? (int) $bTime : 0;

        if ($aTime === $bTime) {
            return strcasecmp(basename($a), basename($b));
        }

        return $aTime < $bTime ? 1 : -1;
    }

    /**
     * Prepare items from a single log file.
     */
    protected function prepareItemsFromFile(string $logFilePath, array $search, int $currentPage, int $perPage): void {
        $offset = ($currentPage - 1) * $perPage;

        $parser = new LogParser(
            $logFilePath,
            $search,
            $offset,
            $perPage,
            false
        );

        $items = $parser->getItemsDecoded();
        $this->items = $this->normalizeRows($items);

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

    /**
     * Normalize decoded parser rows for table rendering.
     */
    protected function normalizeRows($items): array {
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

        return $this->items;
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
                $this->logFilesDropdown();
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
        $levelFilter = isset($_REQUEST['level']) ? strtoupper(trim((string) $_REQUEST['level'])) : 'ERROR';
        if (!in_array($levelFilter, Constants::LEVELS, true)) {
            $levelFilter = 'ERROR';
        }
        ?>
        <select id="levels-filter" name="level">
            <?php foreach (Constants::LEVELS as $level) { ?>
                <option value="<?php echo esc_attr((string) $level); ?>"<?php selected($levelFilter, $level); ?>>
                    <?php echo esc_html((string) $level); ?>
                </option>
            <?php } ?>
        </select>
        <?php
    }

    /**
     * Dropdown with the current and rotated files for the selected level.
     */
    protected function logFilesDropdown(): void {
        $level = $this->selectedLevel;
        $files = $this->getLogFilesForLevel($level);
        $selected = $this->selectedLogFile !== '' ? $this->selectedLogFile : Constants::getLogFileForLevel($level);
        ?>
        <label class="screen-reader-text" for="rrze-log-file-filter">
            <?php esc_html_e('Log file', 'rrze-log'); ?>
        </label>
        <select id="rrze-log-file-filter" name="logfile">
            <?php foreach ($files as $file) { ?>
                <option value="<?php echo esc_attr(basename($file)); ?>"<?php selected($selected, $file); ?>>
                    <?php echo esc_html($this->getLogFileLabel($file, $level)); ?>
                </option>
            <?php } ?>
        </select>
        <?php
    }

    /**
     * Returns a readable file option label.
     */
    protected function getLogFileLabel(string $file, string $level): string {
        $currentFile = Constants::getLogFileForLevel($level);
        $label = basename($file);

        if ($file === $currentFile) {
            $label .= ' (' . __('current', 'rrze-log') . ')';
        }

        return $label;
    }
}
