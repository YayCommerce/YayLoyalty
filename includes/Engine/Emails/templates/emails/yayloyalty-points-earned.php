<?php
/**
 * Points earned email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/yayloyalty-points-earned.php.
 *
 * Variables mirror PointsEarnedEmail::build_values() and the plain template's list; change them together.
 *
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var string    $greeting_name The customer's first name, else display name; '' for none. Absent when there is no award to show.
 * @var string    $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when $greeting_name is ''. Absent when there is no award to show.
 * @var string    $earned_text   Escaped HTML, e.g. "You earned <strong>50 Points</strong> for order #123."; '' when a filter hides it. Absent when there is no award to show.
 * @var string    $balance_text  The balance after this award, e.g. "120 Points". Absent when there is no award to show.
 * @var string    $balance_sentence Escaped HTML: the new balance as a sentence, $balance_text in bold. Absent when there is no award to show.
 * @var string    $balance_value What the balance is worth, as wc_price() HTML; '' while the discount reward is not live. Absent when there is no award to show.
 * @var string    $balance_value_text Escaped HTML, e.g. "That's worth <strong>$1.50</strong> in rewards."; '' when $balance_value is ''. Absent when there is no award to show.
 * @var string    $points       The points earned, formatted, e.g. "50". Absent when there is no award to show.
 * @var string    $points_label "Point" or "Points", from the merchant's labels. Absent when there is no award to show.
 * @var string    $balance      The balance after this award, formatted, e.g. "120". Absent when there is no award to show.
 * @var string    $order_number The order the points were earned for; '' unless earned from an order. Absent when there is no award to show.
 * @var string    $cta_url      Where "View your points" links; '' (the default) hides it. Absent when there is no award to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The awarded row, for overrides. Absent when there is no award to show.
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use YayLoyalty\Helpers\SettingsHelper;

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
    <?php if ( ! empty( $earned_text ) ) : ?>
<p><?php echo wp_kses_post( $earned_text ); ?></p>
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
<p>
    <?php
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'Thanks for being part of our loyalty program. New %s have been added to your account.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) );
    ?>
</p>
<?php endif; ?>
<?php if ( ! empty( $cta_url ) ) : ?>
<p><a class="button" href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:12px 24px;text-decoration:none;font-weight:bold;"><?php echo esc_html( sprintf( /* translators: %s: the store's plural points label, e.g. "Points". */ __( 'View your %s', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ); ?></a></p>
<?php endif; ?>
<?php echo $is_email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
    echo $is_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    echo $is_email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
