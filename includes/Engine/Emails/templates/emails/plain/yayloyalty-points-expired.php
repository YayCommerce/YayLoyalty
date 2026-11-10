<?php
/**
 * Points expired email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-points-expired.php.
 *
 * Variables mirror PointsExpiredEmail::build_values() and the HTML template's list; change them together.
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when there is no name. Absent when there is no expiry to show.
 * @var string $expired_text  Escaped HTML, e.g. "<strong>500 Points</strong> expired from your account." Absent when there is no expiry to show.
 * @var string $balance_sentence Escaped HTML: the balance after the expiry as a sentence. Absent when there is no expiry to show.
 * @var string $points       The points that expired, formatted, e.g. "500". Absent when there is no expiry to show.
 * @var string $points_label "Point" or "Points", from the merchant's labels. Absent when there is no expiry to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The expire row, for overrides. Absent when there is no expiry to show.
 * @var string $cta_text  The button's text, e.g. "View your Points". Absent when there is no expiry to show.
 * @var string $cta_url   Where the button links. Absent when there is no expiry to show.
 */

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks: firing them is what gives this email the store's header, footer and styles.

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $entry ) ) {
    if ( ! empty( $greeting_text ) ) {
        echo esc_html( Text::plain( $greeting_text ) ) . "\n\n";
    }

    echo esc_html( Text::plain( $expired_text ) ) . "\n\n";
    echo esc_html( Text::plain( $balance_sentence ) ) . "\n\n";
} else {
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'Some of your loyalty %s have expired and are no longer in your balance.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ) . "\n\n";
}

if ( ! empty( $cta_url ) ) {
    echo esc_html( $cta_text ) . ': ' . esc_url_raw( $cta_url ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
