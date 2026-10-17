<?php
/**
 * Points expiring soon email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-points-expiring-soon.php.
 *
 * Variables mirror PointsExpiringSoonEmail::prepare() and the HTML template's list; change them together.
 * Every variable below is absent when there is no reminder to show.
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $greeting_text     Escaped HTML, e.g. "Hi Anna,"; '' when there is no name.
 * @var string $expiring_text     Escaped HTML, e.g. "<strong>120 Points</strong> will expire on 14 November 2026."
 * @var string $keep_text         Escaped "Earn or redeem before then to keep them." in inactivity mode, else ''.
 * @var string $points_value_text Escaped HTML: what the expiring points are worth, as a sentence; '' while unknown.
 * @var string $points_value      The same value as wc_price() HTML; '' while unknown.
 * @var string $points            The expiring points, formatted, e.g. "120".
 * @var string $points_label      "Point" or "Points", from the merchant's labels.
 * @var string $expiry_date       The (first) expiry date in the site's date format.
 * @var string $cta_url           Where "Use your points" links; '' (the default) hides it.
 */

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks: firing them is what gives this email the store's header, footer and styles.

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $expiring_text ) ) {
    if ( ! empty( $greeting_text ) ) {
        echo esc_html( Text::plain( $greeting_text ) ) . "\n\n";
    }

    echo esc_html( Text::plain( $expiring_text ) . ( ! empty( $keep_text ) ? ' ' . Text::plain( $keep_text ) : '' ) ) . "\n\n";

    if ( ! empty( $points_value_text ) ) {
        echo esc_html( Text::plain( $points_value_text ) ) . "\n\n";
    }

    if ( ! empty( $cta_url ) ) {
        /* translators: %s: the store's plural points label, e.g. "Points". */
        echo esc_html( sprintf( __( 'Use your %s:', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ) . ' ' . esc_url_raw( $cta_url ) . "\n\n";
    }
} else {
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'Some of your loyalty %s will expire soon. Use them on your next order before they are gone.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
