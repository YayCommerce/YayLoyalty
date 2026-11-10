<?php
/**
 * Points expiring soon email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/yayloyalty-points-expiring-soon.php.
 *
 * Variables mirror PointsExpiringSoonEmail::prepare() and the plain template's list; change them together.
 * Every variable below is absent when there is no reminder to show.
 *
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var string    $greeting_text     Escaped HTML, e.g. "Hi Anna,"; '' when there is no name.
 * @var string    $expiring_text     Escaped HTML, e.g. "<strong>120 Points</strong> will expire on 14 November 2026."
 * @var string    $keep_text         Escaped "Earn or redeem before then to keep them." in inactivity mode, else ''.
 * @var string    $points_value_text Escaped HTML: what the expiring points are worth, as a sentence; '' while unknown.
 * @var string    $points_value      The same value as wc_price() HTML; '' while unknown.
 * @var string    $points            The expiring points, formatted, e.g. "120".
 * @var string    $points_label      "Point" or "Points", from the merchant's labels.
 * @var string    $expiry_date       The (first) expiry date in the site's date format.
 * @var string    $cta_text  The button's text, e.g. "View your Points".
 * @var string    $cta_url   Where the button links.
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own email hooks give this email the store's header, footer and styles; variables are function-local, since wc_get_template() includes this file inside a function.

$is_email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo $is_email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php if ( ! empty( $expiring_text ) ) : ?>
    <?php if ( ! empty( $greeting_text ) ) : ?>
<p><?php echo wp_kses_post( $greeting_text ); ?></p>
    <?php endif; ?>
<p><?php echo wp_kses_post( $expiring_text ); ?><?php echo ! empty( $keep_text ) ? ' ' . wp_kses_post( $keep_text ) : ''; ?></p>
    <?php if ( ! empty( $points_value_text ) ) : ?>
<p><?php echo wp_kses_post( $points_value_text ); ?></p>
    <?php endif; ?>
<?php else : ?>
<p>
    <?php
    /* translators: %s: the store's plural points label, e.g. "Points". */
    echo esc_html( sprintf( __( 'Some of your loyalty %s will expire soon. Use them on your next order before they are gone.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) );
    ?>
</p>
<?php endif; ?>
<?php if ( ! empty( $cta_url ) ) : ?>
<p><a class="button" href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:12px 24px;text-decoration:none;font-weight:bold;"><?php echo esc_html( $cta_text ); ?></a></p>
<?php endif; ?>
<?php echo $is_email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
    echo $is_email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    echo $is_email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
