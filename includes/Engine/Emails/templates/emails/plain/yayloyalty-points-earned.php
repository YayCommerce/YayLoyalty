<?php
/**
 * Points earned email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-points-earned.php.
 *
 * Variables mirror PointsEarnedEmail::build_values() and the HTML template's list; change them together.
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $greeting_name The customer's first name, else display name; '' for none. Absent when there is no award to show.
 * @var string $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when $greeting_name is ''. Absent when there is no award to show.
 * @var string $earned_text   Escaped HTML, e.g. "You earned <strong>50 Points</strong> for order #123."; '' when a filter hides it. Absent when there is no award to show.
 * @var string $balance_text  The balance after this award, e.g. "120 Points". Absent when there is no award to show.
 * @var string $balance_sentence Escaped HTML: the new balance as a sentence, $balance_text in bold. Absent when there is no award to show.
 * @var string $balance_value What the balance is worth, as wc_price() HTML; '' while the discount reward is not live. Absent when there is no award to show.
 * @var string $balance_value_text Escaped HTML sentence around $balance_value; '' when that is ''. Absent when there is no award to show.
 * @var string $points       The points earned, formatted, e.g. "50". Absent when there is no award to show.
 * @var string $points_label "Point" or "Points", from the merchant's labels. Absent when there is no award to show.
 * @var string $balance      The balance after this award, formatted, e.g. "120". Absent when there is no award to show.
 * @var string $order_number The order the points were earned for; '' unless earned from an order. Absent when there is no award to show.
 * @var string $cta_url      Where "View your points" links; '' (the default) hides it. Absent when there is no award to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The awarded row, for overrides. Absent when there is no award to show.
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

    if ( ! empty( $earned_text ) ) {
        echo esc_html( Text::plain( $earned_text ) ) . "\n\n";
    }

    echo esc_html( Text::plain( $balance_sentence ) ) . "\n\n";

    if ( ! empty( $balance_value_text ) ) {
        echo esc_html( Text::plain( $balance_value_text ) ) . "\n\n";
    }
} else {
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'Thanks for being part of our loyalty program. New %s have been added to your account.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ) . "\n\n";
}

if ( ! empty( $cta_url ) ) {
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'View your %s:', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ) . ' ' . esc_url_raw( $cta_url ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
