<?php
/**
 * The order-received page's "Rewards" card. Styled by assets/storefront/thankyou-points.css.
 *
 * @var array{state: string, line: string, hint: string, balance: int} $yayloyalty_thankyou ThankYouPoints::markup():
 *      `state` is 'earned' or 'pending'; `line` is LauncherCopy::points_sentence_html(), already escaped.
 */

use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\ThankYouPoints;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_class = ThankYouPoints::CSS_CLASS;
?>
<section class="<?php echo esc_attr( $yayloyalty_class . ' ' . $yayloyalty_class . '--' . $yayloyalty_thankyou['state'] ); ?>">
    <div class="<?php echo esc_attr( $yayloyalty_class ); ?>__head">
        <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__icon"><?php echo AccountRewardsIcon::design( 'gift' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></span>
        <h2 class="<?php echo esc_attr( $yayloyalty_class ); ?>__title"><?php esc_html_e( 'Rewards', 'yayloyalty' ); ?></h2>
    </div>
    <div class="<?php echo esc_attr( $yayloyalty_class ); ?>__body">
        <div class="<?php echo esc_attr( $yayloyalty_class ); ?>__balance">
            <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__balance-label"><?php esc_html_e( 'Available balance', 'yayloyalty' ); ?></p>
            <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__balance-figure">
                <strong><?php echo esc_html( LauncherCopy::points( $yayloyalty_thankyou['balance'] ) ); ?></strong>
                <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__balance-unit"><?php echo esc_html( SettingsHelper::points_label( $yayloyalty_thankyou['balance'] ) ); ?></span>
            </p>
        </div>
        <div class="<?php echo esc_attr( $yayloyalty_class ); ?>__earned">
            <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__line"><?php echo $yayloyalty_thankyou['line']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- points_sentence_html() escapes every part. ?></p>
            <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__hint"><?php echo esc_html( $yayloyalty_thankyou['hint'] ); ?></p>
        </div>
    </div>
    <img class="<?php echo esc_attr( $yayloyalty_class ); ?>__art" src="<?php echo esc_url( YAYLOYALTY_PLUGIN_URL . 'assets/storefront/thankyou-illustration.svg' ); ?>" alt="" width="176" height="156" loading="lazy" decoding="async">
</section>
