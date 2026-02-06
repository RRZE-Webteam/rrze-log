<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_List_Table;

/**
 * AuditListTable
 *
 * List table for the admin audit log.
 * Uses LogParser on Constants::AUDIT_LOG_FILE and intentionally provides no level dropdown.
 */
class AuditListTable extends WP_List_Table {

    /**
     * Options values.
     * @var object
     */
    public $options;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->options = Options::getOptions();
        $this->items = [];

        parent::__construct([
            'singular' => 'audit',
            'plural' => 'audits',
            'ajax' => false,
        ]);
    }

    /**
     * Default column handler.
     *
     * @param array  $item
     * @param string $columnName
     * @return string
     */
    public function column_default($item, $columnName) {
        if (!is_array($item)) {
            return '';
        }

        switch ($columnName) {
            case 'level':
                return esc_html($item['level'] ?? '');
            case 'siteurl':
                return esc_html($item['siteurl'] ?? '');
            case 'message':
                return esc_html($item['message'] ?? '');
            case 'datetime':
                return esc_html($item['datetime'] ?? '');
            default:
                return '';
        }
    }

    /**
     * Columns shown in table.
     *
     * @return array
     */
    public function get_columns() {
        $columns = [
            'level' => __('Error level', 'rrze-log'),
            'siteurl' => __('Website', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'datetime' => __('Date', 'rrze-log'),
        ];

        if (!is_network_admin() && $this->options->adminMenu) {
            unset($columns['siteurl']);
        }

        return $columns;
    }

    /**
     * Prepare list table items (pagination, search).
     */
    public function prepare_items() {
        $s = $_REQUEST['s'] ?? '';
        $logFile = Constants::AUDIT_LOG_FILE;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [$columns, $hidden, $sortable];

        $perPage = $this->get_items_per_page('rrze_log_per_page');
        $currentPage = $this->get_pagenum();

        $search = array_map('trim', explode(' ', trim((string) $s)));
        $search = array_filter($search);

        $logParser = new LogParser(
            $logFile,
            $search,
            (($currentPage - 1) * $perPage),
            $perPage
        );

        if (!is_network_admin()) {
            $logItems = $logParser->getItems('siteurl', site_url());
        } else {
            $logItems = $logParser->getItems();
        }

        $items = [];
        if (!is_wp_error($logItems)) {
            foreach ($logItems as $row) {
                $decoded = json_decode($row, true);
                if (is_array($decoded)) {
                    $items[] = $decoded;
                }
            }
        }

        $this->items = $items;

        $totalItems = $logParser->getTotalLines();

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Disable top filter UI (no level dropdown for audit view).
     *
     * @param string $which
     */
    public function extra_tablenav($which) {
        return;
    }
}
