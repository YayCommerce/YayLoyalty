<?php
/**
 * Points earned email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/yayloyalty-points-earned.php.
 *
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var string    $greeting_name The customer's first name, else display name; '' for none. Absent when there is no award to show.
 * @var string    $earned_text   Escaped HTML, e.g. "You earned <strong>50 Points</strong> for order #123."; '' when a filter hides it. Absent when there is no award to show.
 * @var string    $balance_text  The balance after this award, e.g. "120 Points". Absent when there is no award to show.
 * @var string    $balance_value What the balance is worth, as wc_price() HTML; '' when the discount reward is not live. Absent when there is no award to show.
 * @var string    $balance_value_text Escaped HTML, e.g. "That's worth <strong>$1.50</strong> in rewards."; '' when $balance_value is ''. Absent when there is no award to show.
 * @var string    $cta_url      Where "View your points" links. Absent when there is no award to show.
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
    <?php if ( ! empty( $greeting_name ) ) : ?>
<p>
        <?php
        /* translators: %s: customer's first name. */
        echo esc_html( sprintf( __( 'Hi %s,', 'yayloyalty' ), $greeting_name ) );
        ?>
</p>
    <?php endif; ?>
    <?php if ( ! empty( $earned_text ) ) : ?>
<p><?php echo wp_kses_post( $earned_text ); ?></p>
    <?php endif; ?>
<p>
    <?php
    /* translators: %s: points with label, e.g. "120 Points". */
    echo wp_kses_post( sprintf( esc_html__( 'Your balance is now %s.', 'yayloyalty' ), '<strong>' . esc_html( $balance_text ) . '</strong>' ) );
    ?>
</p>
    <?php if ( ! empty( $balance_value_text ) ) : ?>
<p>
        <?php
        echo $balance_value_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built; kses would strip wc_price()'s <bdi>, which keeps the amount readable in right-to-left stores.
        ?>
</p>
    <?php endif; ?>
<?php else : ?>
<p><?php esc_html_e( 'Thanks for being part of our loyalty program. New points have been added to your account.', 'yayloyalty' ); ?></p>
<?php endif; ?>
<?php if ( ! empty( $cta_url ) ) : ?>
<p><a class="button" href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:12px 24px;text-decoration:none;font-weight:bold;"><?php esc_html_e( 'View your points', 'yayloyalty' ); ?></a></p>
<?php endif; ?>
<?php echo $is_email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
    echo $is_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    echo $is_email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
