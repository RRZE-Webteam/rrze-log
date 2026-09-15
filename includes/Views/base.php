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
                    <?php printf(
                        /* translators: %s: Error message. */
                        esc_html__('Error: %s', 'rrze-log'),
                        esc_html($message->get_error_message())
                    );
                    ?>
                </p>
            </div>
        <?php else : ?>
            <div class="updated">
                <p><?php echo esc_html((string) $message); ?></p>
            </div>
    <?php endif;
    endforeach;

    include $view . '.php';
    ?>
</div>
