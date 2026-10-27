<?php
/**
 * Reward claimed email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-reward-claimed.php.
 *
 * Variables mirror RewardClaimedEmail::build_values() and the HTML template's list; change them together.
 *
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var string    $greeting_name The customer's first name, else display name; '' for none. Absent when there is no claim to show.
 * @var string    $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when $greeting_name is ''. Absent when there is no claim to show.
 * @var string    $claimed_text  Escaped HTML, e.g. "You used <strong>800 Points</strong> on order <a href="…">#1234</a>."; the link is dropped without an order URL, the number without an order. Absent when there is no claim to show.
 * @var array<int, array{name: string, points: int, points_text: string, discount: string}> $reward_lines One per applied line, oldest first, e.g. [ 'name' => 'Points discount', 'points' => 500, 'points_text' => '500 Points', 'discount' => wc_price() HTML in the order's currency ]. Absent when there is no claim to show.
 * @var string    $reward_lines_html Escaped HTML: $reward_lines as a table of name, points and discount. Absent when there is no claim to show.
 * @var string    $balance_text  The balance after the order, e.g. "300 Points". Absent when there is no claim to show.
 * @var string    $balance_sentence Escaped HTML: the new balance as a sentence, $balance_text in bold. Absent when there is no claim to show.
 * @var string    $balance_value What the balance is worth, as wc_price() HTML in the base currency; '' while the discount reward is not live. Absent when there is no claim to show.
 * @var string    $balance_value_text Escaped HTML sentence around $balance_value; '' when that is ''. Absent when there is no claim to show.
 * @var string    $points       The points used on the order, formatted, e.g. "800". Absent when there is no claim to show.
 * @var string    $points_label "Point" or "Points", from the merchant's labels. Absent when there is no claim to show.
 * @var string    $balance      The balance after the order, formatted, e.g. "300". Absent when there is no claim to show.
 * @var string    $order_number The order number, e.g. "1234"; '' when the order is gone. Absent when there is no claim to show.
 * @var string    $order_url    The order's My Account URL, e.g. "https://example.com/my-account/view-order/1234/"; '' without an order. Absent when there is no claim to show.
 * @var \WC_Order|null $order   The order, for overrides; null when it is gone. Absent when there is no claim to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The order's REDEEM row, for overrides. Absent when there is no claim to show.
 */

use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own email hooks give this email the store's header, footer and styles; variables are function-local, since wc_get_template() includes this file inside a function.

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $entry ) ) {
    if ( ! empty( $greeting_text ) ) {
        echo esc_html( Text::plain( $greeting_text ) ) . "\n\n";
    }

    echo esc_html( Text::plain( $claimed_text ) ) . "\n\n";

    foreach ( $reward_lines as $reward_line ) {
        echo esc_html( '- ' . $reward_line['name'] . ': ' . $reward_line['points_text'] . ' (−' . Text::plain( $reward_line['discount'] ) . ')' ) . "\n";
    }

    echo "\n" . esc_html( Text::plain( $balance_sentence ) ) . "\n\n";

    if ( ! empty( $balance_value_text ) ) {
        echo esc_html( Text::plain( $balance_value_text ) ) . "\n\n";
    }
} else {
    echo esc_html__( 'You used your loyalty points on an order. Enjoy!', 'yayloyalty' ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
