<?php

defined('ABSPATH') || exit;

$title = isset($data['title']) ? (string) $data['title'] : '';
$listTable = isset($data['listTable']) ? $data['listTable'] : null;

$action = isset($data['action']) ? (string) $data['action'] : 'index';
$s = isset($data['s']) ? (string) $data['s'] : '';
$level = isset($data['level']) ? (string) $data['level'] : '';
$logfile = isset($data['logfile']) ? (string) $data['logfile'] : '';

if ($logfile === '') {
    $logfile = date('Y-m-d');
}

?>
<div class="wrap">
    <h1><?php echo esc_html($title); ?></h1>

    <?php if (!empty($data['messages']) && is_array($data['messages'])): ?>
        <?php foreach ($data['messages'] as $msg): ?>
            <div class="notice notice-info is-dismissible">
                <p><?php echo esc_html((string) $msg); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($listTable instanceof WP_List_Table): ?>
        <form method="get">
            <?php
            foreach ($_GET as $key => $value) {
                if ($key === 's') {
                    continue;
                }
                if (is_array($value)) {
                    continue;
                }
                printf(
                    '<input type="hidden" name="%s" value="%s">',
                    esc_attr((string) $key),
                    esc_attr((string) $value)
                );
            }
            ?>
            <input type="hidden" name="page" value="<?php echo esc_attr(isset($_REQUEST['page']) ? (string) $_REQUEST['page'] : 'rrze-log'); ?>">

            <?php
            $listTable->search_box(__('Search', 'rrze-log'), 'rrze-log-search');

            if (method_exists($listTable, 'views')) {
                $listTable->views();
            }

            $listTable->display();
            ?>
        </form>
    <?php else: ?>
        <div class="notice notice-error">
            <p><?php echo esc_html__('List table is not available.', 'rrze-log'); ?></p>
        </div>
    <?php endif; ?>
</div>
