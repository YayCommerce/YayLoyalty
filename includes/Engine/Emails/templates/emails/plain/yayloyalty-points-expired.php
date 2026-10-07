<?php
/**
 * Points expired email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-points-expired.php.
 *
 * @var string $email_heading
 * @var string $additional_content
 */

use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks: firing them is what gives this email the store's header, footer and styles.

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: the store's plural points label, e.g. "Points". */
echo esc_html( sprintf( __( 'Some of your loyalty %s have expired and are no longer in your balance.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ) . "\n\n";

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
