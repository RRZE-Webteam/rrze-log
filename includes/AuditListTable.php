<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use WP_List_Table;

class AuditListTable extends WP_List_Table {

    /**
     * Options values.
     * @var object
     */
    public $options;

    /**
     * Current orderby.
     * @var string
     */
    protected string $orderby = 'time';

    /**
     * Current order.
     * @var string
     */
    protected string $order = 'desc';

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
     * Adds custom table classes.
     *
     * @return array
     */
    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $classes[] = 'rrze-log-audit';
        return $classes;
    }

    /**
     * Columns shown in table.
     *
     * @return array
     */
    public function get_columns() {
        $columns = [
            'time' => __('Time', 'rrze-log'),
            'siteurl' => __('Website', 'rrze-log'),
            'user' => __('User', 'rrze-log'),
            'role' => __('Role', 'rrze-log'),
            'message' => __('Message', 'rrze-log'),
            'type' => __('Type', 'rrze-log'),
            'object' => __('Object', 'rrze-log'),
        ];

        if (!is_network_admin() && $this->options->adminMenu) {
            unset($columns['siteurl']);
        }

        return $columns;
    }

    /**
     * Defines sortable columns.
     *
     * @return array
     */
    protected function get_sortable_columns() {
        return [
            'time' => ['time', true],
            'siteurl' => ['siteurl', false],
            'user' => ['user', false],
            'role' => ['role', false],
            'message' => ['message', false],
            'type' => ['type', false],
            'object' => ['object', false],
        ];
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
            case 'time':
                return esc_html($this->formatTime($item));
            case 'siteurl':
                return esc_html($this->formatSiteDomain($item));
            case 'user':
                return $this->renderUserColumn($item);
            case 'role':
                return esc_html($this->getContextRole($item));
            case 'message':
                return esc_html($item['message'] ?? '');
            case 'type':
                return esc_html($this->getContextAuditTypeLabel($item));
            case 'object':
                return esc_html($this->getContextObject($item));
            default:
                return '';
        }
    }

    /**
     * Adds filter controls above the table (Type + Role).
     *
     * @param string $which
     */
    public function extra_tablenav($which) {
        if ($which !== 'top') {
            return;
        }

        $currentType = isset($_REQUEST['audit_type']) ? sanitize_key((string) $_REQUEST['audit_type']) : '';
        $currentRole = isset($_REQUEST['audit_role']) ? sanitize_key((string) $_REQUEST['audit_role']) : '';
        $currentSearch = isset($_REQUEST['s']) ? (string) $_REQUEST['s'] : '';

        $types = [
            '' => __('All types', 'rrze-log'),
            'cms' => __('CMS-Administration', 'rrze-log'),
            'site' => __('Website-Administration', 'rrze-log'),
            'editorial' => __('Redaktion', 'rrze-log'),
        ];

        $roles = [
            '' => __('All roles', 'rrze-log'),
            'superadmin' => __('Superadmin', 'rrze-log'),
            'administrator' => __('Administrator', 'rrze-log'),
            'editor' => __('Editor', 'rrze-log'),
            'author' => __('Author', 'rrze-log'),
            'contributor' => __('Contributor', 'rrze-log'),
            'subscriber' => __('Subscriber', 'rrze-log'),
            'unknown' => __('Unknown', 'rrze-log'),
        ];

        echo '<div class="alignleft actions">';

        echo '<label class="screen-reader-text" for="rrze-log-audit-type-filter">' . esc_html(__('Filter by type', 'rrze-log')) . '</label>';
        echo '<select id="rrze-log-audit-type-filter" name="audit_type">';
        foreach ($types as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($currentType, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';

        echo '&nbsp;';

        echo '<label class="screen-reader-text" for="rrze-log-audit-role-filter">' . esc_html(__('Filter by role', 'rrze-log')) . '</label>';
        echo '<select id="rrze-log-audit-role-filter" name="audit_role">';
        foreach ($roles as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($currentRole, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';

        if ($currentSearch !== '') {
            printf(
                '<input type="hidden" name="s" value="%s">',
                esc_attr($currentSearch)
            );
        }

        submit_button(__('Filter'), 'secondary', 'filter_action', false);

        echo '</div>';
    }

    /**
     * Prepare list table items (pagination, search, filter, sort).
     */
    public function prepare_items() {
        $s = $_REQUEST['s'] ?? '';
        $logFile = Constants::AUDIT_LOG_FILE;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [$columns, $hidden, $sortable];

        $this->orderby = isset($_REQUEST['orderby']) ? sanitize_key((string) $_REQUEST['orderby']) : 'time';
        $this->order = isset($_REQUEST['order']) ? strtolower((string) $_REQUEST['order']) : 'desc';
        $this->order = $this->order === 'asc' ? 'asc' : 'desc';

        $perPage = $this->get_items_per_page('rrze_log_per_page');
        $currentPage = $this->get_pagenum();

        $search = array_map('trim', explode(' ', trim((string) $s)));
        $search = array_filter($search);

        $typeFilter = isset($_REQUEST['audit_type']) ? sanitize_key((string) $_REQUEST['audit_type']) : '';
        if ($typeFilter !== '') {
            $search[] = '"audit_type":"' . $typeFilter . '"';
        }

        $roleFilter = isset($_REQUEST['audit_role']) ? sanitize_key((string) $_REQUEST['audit_role']) : '';
        if ($roleFilter !== '') {
            $search[] = '"role":"' . $roleFilter . '"';
        }

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

        $this->items = $this->sortItems($items);

        $totalItems = $logParser->getTotalLines();

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Sorts decoded items based on current orderby/order.
     * Note: sorting applies to the current page only (file-based pagination).
     *
     * @param array $items
     * @return array
     */
    protected function sortItems(array $items): array {
        if (empty($items)) {
            return $items;
        }

        usort($items, [$this, 'compareItems']);
        return $items;
    }

    /**
     * Comparator for sorting.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
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

    /**
     * Returns a comparable value for a given column key.
     *
     * @param array  $item
     * @param string $key
     * @return mixed
     */
    protected function getSortValue(array $item, string $key) {
        switch ($key) {
            case 'time':
                return $this->getUnixTime($item);
            case 'siteurl':
                return $this->formatSiteDomain($item);
            case 'user':
                return $this->getActorLogin($item);
            case 'role':
                return strtolower($this->getActorRole($item));
            case 'type':
                return strtolower($this->getContextAuditTypeLabel($item));
            case 'message':
                return (string) ($item['message'] ?? '');
            case 'object':
                return $this->getContextObject($item);
            default:
                return $this->getUnixTime($item);
        }
    }

    /**
     * Returns unix timestamp from datetime.
     */
    protected function getUnixTime(array $item): int {
        $dt = isset($item['datetime']) ? (string) $item['datetime'] : '';
        if ($dt === '') {
            return 0;
        }

        $ts = strtotime($dt);
        if ($ts === false) {
            return 0;
        }

        return (int) $ts;
    }

    /**
     * Returns actor login from context.
     */
    protected function getActorLogin(array $item): string {
        $context = $this->getContext($item);

        if (!isset($context['actor']) || !is_array($context['actor'])) {
            return '';
        }

        $actor = $context['actor'];
        return isset($actor['login']) ? (string) $actor['login'] : '';
    }

    /**
     * Returns actor role from context.
     */
    protected function getActorRole(array $item): string {
        $context = $this->getContext($item);

        if (!isset($context['actor']) || !is_array($context['actor'])) {
            return '';
        }

        $actor = $context['actor'];

        if (isset($actor['role']) && $actor['role'] !== '') {
            return (string) $actor['role'];
        }

        if (isset($actor['is_super_admin']) && !empty($actor['is_super_admin'])) {
            return 'superadmin';
        }

        if (isset($actor['roles']) && is_array($actor['roles']) && !empty($actor['roles'][0])) {
            return (string) $actor['roles'][0];
        }

        return '';
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
     * Extracts role from context (with fallback for older log lines).
     */
    protected function getContextRole(array $item): string {
        $role = $this->getActorRole($item);
        return $role !== '' ? strtoupper($role) : '';
    }

    /**
     * Extracts audit type label from context.
     */
    protected function getContextAuditTypeLabel(array $item): string {
        $context = $this->getContext($item);

        if (isset($context['audit_type_label']) && $context['audit_type_label'] !== '') {
            return (string) $context['audit_type_label'];
        }

        if (isset($context['audit_type']) && $context['audit_type'] !== '') {
            return (string) $context['audit_type'];
        }

        return '';
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
     * Formats the time column as "YYYY-MM-DD HH:MM:SS".
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

    /**
     * Formats the site URL to show only the domain name (no protocol).
     */
    protected function formatSiteDomain(array $item): string {
        $siteurl = isset($item['siteurl']) ? (string) $item['siteurl'] : '';
        if ($siteurl === '') {
            return '';
        }

        $parts = wp_parse_url($siteurl);
        if (is_array($parts) && !empty($parts['host'])) {
            return (string) $parts['host'];
        }

        $fallback = preg_replace('#^https?://#i', '', $siteurl);
        $fallback = preg_replace('#/.*$#', '', (string) $fallback);

        return (string) $fallback;
    }

    /**
     * Renders the "User" column:
     * - Dashicon for person
     * - Username as link to user edit screen (site or network based on role)
     * - Adds IP + Browser icons with tooltips
     */
    protected function renderUserColumn(array $item): string {
        $context = $this->getContext($item);

        if (!isset($context['actor']) || !is_array($context['actor'])) {
            return '';
        }

        $actor = $context['actor'];

        $login = isset($actor['login']) ? (string) $actor['login'] : '';
        $id = isset($actor['id']) ? (int) $actor['id'] : 0;
        $role = strtolower(isset($actor['role']) ? (string) $actor['role'] : '');

        $ip = isset($actor['ip']) ? (string) $actor['ip'] : '';
        $ua = isset($actor['user_agent']) ? (string) $actor['user_agent'] : '';

        if ($login === '' && $id <= 0) {
            return '';
        }

        $label = $login !== '' ? $login : (string) $id;
        if ($id > 0) {
            $label .= ' (' . $id . ')';
        }

        $userUrl = '';
        if ($id > 0) {
            if ($role === 'superadmin') {
                $userUrl = network_admin_url('user-edit.php?user_id=' . $id);
            } else {
                $userUrl = admin_url('user-edit.php?user_id=' . $id);
            }
        }

        $personIcon = '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>';

        $userHtml = esc_html($label);
        if ($userUrl !== '') {
            $userHtml = sprintf(
                '<a href="%s">%s</a>',
                esc_url($userUrl),
                esc_html($label)
            );
        }

        $ipIcon = '';
        if ($ip !== '') {
            $ipIcon = sprintf(
                ' <span class="dashicons dashicons-location" title="%s" aria-label="%s"></span>',
                esc_attr($ip),
                esc_attr(__('IP address', 'rrze-log'))
            );
        }

        $uaIcon = '';
        if ($ua !== '') {
            $uaIcon = sprintf(
                ' <span class="dashicons dashicons-desktop" title="%s" aria-label="%s"></span>',
                esc_attr($ua),
                esc_attr(__('Browser', 'rrze-log'))
            );
        }

        return $personIcon . ' ' . $userHtml . $ipIcon . $uaIcon;
    }
}
