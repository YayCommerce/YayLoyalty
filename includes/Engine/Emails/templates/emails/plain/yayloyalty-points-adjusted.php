<?php
/**
 * Points adjusted email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-points-adjusted.php.
 *
 * Variables mirror PointsAdjustedEmail::build_values() and the HTML template's list; change them together.
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $greeting_name The customer's first name, else display name; '' for none. Absent when there is no adjustment to show.
 * @var string $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when $greeting_name is ''. Absent when there is no adjustment to show.
 * @var string $adjusted_text Escaped HTML, e.g. "We added <strong>50 Points</strong> to your account." Absent when there is no adjustment to show.
 * @var string $balance_text  The balance after this adjustment, e.g. "120 Points". Absent when there is no adjustment to show.
 * @var string $balance_sentence Escaped HTML: the new balance as a sentence, $balance_text in bold. Absent when there is no adjustment to show.
 * @var string $balance_value What the balance is worth, as wc_price() HTML; '' until redemption ships (RedemptionService::is_available()), or while the discount reward is not live. Absent when there is no adjustment to show.
 * @var string $balance_value_text Escaped HTML sentence around $balance_value; '' when that is ''. Absent when there is no adjustment to show.
 * @var string $points       The points added or removed, unsigned and formatted, e.g. "50". Absent when there is no adjustment to show.
 * @var string $points_label "Point" or "Points", from the merchant's labels. Absent when there is no adjustment to show.
 * @var string $balance      The balance after this adjustment, formatted, e.g. "120". Absent when there is no adjustment to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The adjustment row, for overrides. Absent when there is no adjustment to show.
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

    echo esc_html( Text::plain( $adjusted_text ) ) . "\n\n";

    echo esc_html( Text::plain( $balance_sentence ) ) . "\n\n";

    if ( ! empty( $balance_value_text ) ) {
        echo esc_html( Text::plain( $balance_value_text ) ) . "\n\n";
    }
} else {
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'Your %s balance was updated.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
