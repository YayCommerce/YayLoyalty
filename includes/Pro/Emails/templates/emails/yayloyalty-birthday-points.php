<?php
/**
 * Birthday points email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/yayloyalty-birthday-points.php.
 *
 * Variables mirror BirthdayPointsEmail::build_values() and the plain template's list; change them together.
 *
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var string    $greeting_name The customer's first name, else display name; '' for none. Absent when there is no award to show.
 * @var string    $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when $greeting_name is ''. Absent when there is no award to show.
 * @var string    $birthday_text Escaped HTML, e.g. "To celebrate your birthday, we added <strong>150 Points</strong> to your account."; '' when a filter hides it. Absent when there is no award to show.
 * @var string    $balance_text  The balance after this award, e.g. "300 Points". Absent when there is no award to show.
 * @var string    $balance_sentence Escaped HTML: the new balance as a sentence, $balance_text in bold. Absent when there is no award to show.
 * @var string    $balance_value What the balance is worth, as wc_price() HTML; '' while the discount reward is not live. Absent when there is no award to show.
 * @var string    $balance_value_text Escaped HTML, e.g. "That's worth <strong>$3.00</strong> in rewards."; '' when $balance_value is ''. Absent when there is no award to show.
 * @var string    $points       The points awarded, formatted, e.g. "150". Absent when there is no award to show.
 * @var string    $points_label "Point" or "Points", from the merchant's labels. Absent when there is no award to show.
 * @var string    $balance      The balance after this award, formatted, e.g. "300". Absent when there is no award to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The awarded row, for overrides. Absent when there is no award to show.
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own email hooks give this email the store's header, footer and styles; variables are function-local, since wc_get_template() includes this file inside a function.

$is_email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo $is_email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php if ( ! empty( $entry ) ) : ?>
    <?php if ( ! empty( $greeting_text ) ) : ?>
<p><?php echo wp_kses_post( $greeting_text ); ?></p>
    <?php endif; ?>
    <?php if ( ! empty( $birthday_text ) ) : ?>
<p><?php echo wp_kses_post( $birthday_text ); ?></p>
    <?php endif; ?>
<p><?php echo wp_kses_post( $balance_sentence ); ?></p>
    <?php if ( ! empty( $balance_value_text ) ) : ?>
<p>
        <?php
        echo $balance_value_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built; kses would strip wc_price()'s <bdi>, which keeps the amount readable in right-to-left stores.
        ?>
</p>
    <?php endif; ?>
<?php else : ?>
<p><?php esc_html_e( 'To celebrate your birthday, we added bonus points to your account.', 'yayloyalty' ); ?></p>
<?php endif; ?>
<?php echo $is_email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
    echo $is_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    echo $is_email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
