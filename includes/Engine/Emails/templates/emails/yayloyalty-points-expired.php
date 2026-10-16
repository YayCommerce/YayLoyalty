<?php
/**
 * Points expired email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/yayloyalty-points-expired.php.
 *
 * Variables mirror PointsExpiredEmail::build_values() and the plain template's list; change them together.
 *
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var string    $greeting_text Escaped HTML, e.g. "Hi Anna,"; '' when there is no name. Absent when there is no expiry to show.
 * @var string    $expired_text  Escaped HTML, e.g. "<strong>500 Points</strong> expired from your account." Absent when there is no expiry to show.
 * @var string    $balance_sentence Escaped HTML: the balance after the expiry as a sentence. Absent when there is no expiry to show.
 * @var string    $points       The points that expired, formatted, e.g. "500". Absent when there is no expiry to show.
 * @var string    $points_label "Point" or "Points", from the merchant's labels. Absent when there is no expiry to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The expire row, for overrides. Absent when there is no expiry to show.
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
<p><?php echo wp_kses_post( $expired_text ); ?></p>
<p><?php echo wp_kses_post( $balance_sentence ); ?></p>
<?php else : ?>
<p>
    <?php
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'Some of your loyalty %s have expired and are no longer in your balance.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) );
    ?>
</p>
<?php endif; ?>
<?php echo $is_email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
    echo $is_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    echo $is_email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
