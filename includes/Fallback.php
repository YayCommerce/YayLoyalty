<?php

defined( 'ABSPATH' ) || exit;
add_action(
    'admin_notices',
    function () {
        if ( current_user_can( 'activate_plugins' ) ) {
            ?>
                <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'It looks like you have another YayLoyalty version installed, please delete it before activating this new version. All of the settings and data are still preserved.', 'yayloyalty' ); ?>
                    <a href="https://docs.yaycommerce.com/"><?php esc_html_e( 'Read more details.', 'yayloyalty' ); ?></a>
                    </strong>
                </p>
                </div>
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['activate'] ) ) {
                unset( $_GET['activate'] );
            }
        }
    }
);
