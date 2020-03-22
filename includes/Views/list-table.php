<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;
?>
<h2>
    <?php _e('Logs', 'rrze-log'); ?>
</h2>

<form method="get">
    <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
    <input type="hidden" name="level" value="<?php echo $data['level'] ?>">
    <input type="hidden" name="logfile" value="<?php echo $data['logfile'] ?>">
    <?php $data['listTable']->search_box(__('Search'), 's'); ?>
</form>

<form method="get">
    <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
    <input type="hidden" name="s" value="<?php echo $data['s'] ?>">
    <input type="hidden" name="logfile" value="<?php echo $data['logfile'] ?>">
    <?php $data['listTable']->display(); ?>
</form>
