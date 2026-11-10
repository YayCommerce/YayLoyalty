<?php
/**
 * Birthday points email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-birthday-points.php.
 *
 * Variables mirror BirthdayPointsEmail::build_values() and the HTML template's list; change them together.
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $greeting_name The customer's first name, else display name; '' for none. Absent when there is no award to show.
 * @var string $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when $greeting_name is ''. Absent when there is no award to show.
 * @var string $birthday_text Escaped HTML, e.g. "To celebrate your birthday, we added <strong>150 Points</strong> to your account."; '' when a filter hides it. Absent when there is no award to show.
 * @var string $balance_text  The balance after this award, e.g. "300 Points". Absent when there is no award to show.
 * @var string $balance_sentence Escaped HTML: the new balance as a sentence, $balance_text in bold. Absent when there is no award to show.
 * @var string $balance_value What the balance is worth, as wc_price() HTML; '' while the discount reward is not live. Absent when there is no award to show.
 * @var string $balance_value_text Escaped HTML sentence around $balance_value; '' when that is ''. Absent when there is no award to show.
 * @var string $points       The points awarded, formatted, e.g. "150". Absent when there is no award to show.
 * @var string $points_label "Point" or "Points", from the merchant's labels. Absent when there is no award to show.
 * @var string $balance      The balance after this award, formatted, e.g. "300". Absent when there is no award to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The awarded row, for overrides. Absent when there is no award to show.
 * @var string $cta_text  The button's text, e.g. "View your Points". Absent when there is no award to show.
 * @var string $cta_url   Where the button links. Absent when there is no award to show.
 */

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

    if ( ! empty( $birthday_text ) ) {
        echo esc_html( Text::plain( $birthday_text ) ) . "\n\n";
    }

    echo esc_html( Text::plain( $balance_sentence ) ) . "\n\n";

    if ( ! empty( $balance_value_text ) ) {
        echo esc_html( Text::plain( $balance_value_text ) ) . "\n\n";
    }
} else {
    echo esc_html__( 'To celebrate your birthday, we added bonus points to your account.', 'yayloyalty' ) . "\n\n";
}

if ( ! empty( $cta_url ) ) {
    echo esc_html( $cta_text ) . ': ' . esc_url_raw( $cta_url ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
