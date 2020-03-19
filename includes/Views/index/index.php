<?php defined('ABSPATH') || exit; ?>
<h2>
    <?php _e('Logs', 'rrze-log'); ?>
</h2>

<form method="post">
    <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
    <?php $data['listTable']->search_box(__('Search', 'rrze-log'), 's'); ?>
</form>

<form method="get">
    <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
    <?php $data['listTable']->display(); ?>
</form>
