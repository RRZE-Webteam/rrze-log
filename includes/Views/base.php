<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;
?>
<div class="wrap">
<?php
foreach ($data['messages'] as $message) :
    if (is_wp_error($message)) : ?>
        <div class="error">
            <p>
                <?php printf(__('Error: %s', 'rrze-log'), $message->get_error_message()); ?>
            </p>
        </div>
    <?php else: ?>
        <div class="updated"><p><?php echo $message; ?></p></div>
    <?php endif;
endforeach;

include $view . '.php';
?>
        <hr>
</div>
