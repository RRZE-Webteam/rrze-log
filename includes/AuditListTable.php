<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_List_Table;

/**
 * AuditListTable
 *
 * List table for the admin audit log.
 * Shows action + user + role + object and a compact timestamp.
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
     * Columns shown in table.
     *
     * @return array
     */
    public function get_columns() {
        $columns = [
            'action' => __('Action', 'rrze-log'),
            'user' => __('User', 'rrze-log'),
            'role' => __('Role', 'rrze-log'),
            'object' => __('Object', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'time' => __('Time', 'rrze-log'),
            'siteurl' => __('Website', 'rrze-log'),
        ];

        if (!is_network_admin() && $this->options->adminMenu) {
            unset($columns['siteurl']);
        }

        return $columns;
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
            case 'action':
                return esc_html($this->getContextAction($item));
            case 'user':
                return $this->renderUserColumn($item);
            case 'role':
                return esc_html($this->getContextRole($item));
            case 'object':
                return esc_html($this->getContextObject($item));
            case 'siteurl':
                return esc_html($item['siteurl'] ?? '');
            case 'message':
                return esc_html($item['message'] ?? '');
            case 'time':
                return esc_html($this->formatTime($item));
            default:
                return '';
        }
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

    /**
     * Extracts action from context.
     */
    protected function getContextAction(array $item): string {
        $context = $this->getContext($item);
        if (!isset($context['action'])) {
            return '';
        }

        return (string) $context['action'];
    }

    /**
     * Extracts role from context.
     */
    protected function getContextRole(array $item): string {
        $context = $this->getContext($item);

        if (!isset($context['actor']) || !is_array($context['actor'])) {
            return '';
        }

        $actor = $context['actor'];
        $role = isset($actor['role']) ? (string) $actor['role'] : '';

        return $role !== '' ? strtoupper($role) : '';
    }

    /**
     * Formats object information from context.
     */
    protected function getContextObject(array $item): string {
        $context = $this->getContext($item);

        if (!isset($context['object']) || !is_array($context['object'])) {
            return '';
        }

        $obj = $context['object'];

        $type = isset($obj['type']) ? (string) $obj['type'] : '';
        $id = isset($obj['id']) ? (int) $obj['id'] : 0;

        $parts = [];

        if ($type !== '') {
            $parts[] = $type;
        }

        if ($id > 0) {
            $parts[] = '#' . $id;
        }

        if (isset($obj['post_type']) && $obj['post_type'] !== '') {
            $parts[] = '(' . (string) $obj['post_type'] . ')';
        }

        if (isset($obj['stylesheet']) && $obj['stylesheet'] !== '') {
            $parts[] = (string) $obj['stylesheet'];
        }

        if (isset($obj['plugin']) && $obj['plugin'] !== '') {
            $parts[] = (string) $obj['plugin'];
        }

        if (isset($obj['login']) && $obj['login'] !== '') {
            $parts[] = (string) $obj['login'];
        }

        if (isset($obj['title']) && $obj['title'] !== '') {
            $title = (string) $obj['title'];
            if (mb_strlen($title) > 80) {
                $title = mb_substr($title, 0, 80) . '...';
            }
            $parts[] = '"' . $title . '"';
        }

        if (isset($obj['name']) && $obj['name'] !== '') {
            $parts[] = '"' . (string) $obj['name'] . '"';
        }

        return implode(' ', $parts);
    }

    /**
     * Returns context array from item.
     */
    protected function getContext(array $item): array {
        if (!isset($item['context']) || !is_array($item['context'])) {
            return [];
        }

        return $item['context'];
    }

    /**
     * Renders the "User" column and includes last-login as a tooltip if present.
     *
     * User format: login (id)
     */
   protected function renderUserColumn(array $item): string {
       $context = $this->getContext($item);

       if (!isset($context['actor']) || !is_array($context['actor'])) {
           return '';
       }

       $actor = $context['actor'];

       $login = isset($actor['login']) ? (string) $actor['login'] : '';
       $id = isset($actor['id']) ? (int) $actor['id'] : 0;

       $label = $login !== '' ? $login : '';
       if ($id > 0) {
           $label .= ' (' . $id . ')';
       }

       $label = trim($label);
       if ($label === '') {
           return '';
       }

       return esc_html($label);
   }


    /**
     * Formats the time column as "YYYY-MM-DD HH:MM:SS".
     * Uses the already stored datetime string if possible; falls back to parsing.
     */
    protected function formatTime(array $item): string {
        $dt = isset($item['datetime']) ? (string) $item['datetime'] : '';
        if ($dt === '') {
            return '';
        }

        $ts = strtotime($dt);
        if ($ts === false) {
            return $dt;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }
}
