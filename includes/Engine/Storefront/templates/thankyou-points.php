<?php
/**
 * The order-received page's "Points & rewards" card. Styled by assets/storefront/thankyou-points.css.
 *
 * @var array{line: string, hint: string, balance: int} $yayloyalty_thankyou ThankYouPoints::markup(): `line` is
 *      LauncherCopy::points_sentence_html(), already escaped.
 */

use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\ThankYouPoints;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_class = ThankYouPoints::CSS_CLASS;
?>
<section class="<?php echo esc_attr( $yayloyalty_class ); ?>">
    <div class="<?php echo esc_attr( $yayloyalty_class ); ?>__head">
        <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__icon"><?php echo AccountRewardsIcon::get( 'gift' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></span>
        <h2 class="<?php echo esc_attr( $yayloyalty_class ); ?>__title"><?php esc_html_e( 'Points & rewards', 'yayloyalty' ); ?></h2>
        <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__balance">
            <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__balance-label"><?php esc_html_e( 'Current balance', 'yayloyalty' ); ?></span>
            <strong><?php echo esc_html( LauncherCopy::points( $yayloyalty_thankyou['balance'] ) ); ?></strong>
            <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__balance-label"><?php echo esc_html( SettingsHelper::points_label( $yayloyalty_thankyou['balance'] ) ); ?></span>
        </p>
    </div>
    <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__line">
        <span><?php echo $yayloyalty_thankyou['line']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- points_sentence_html() escapes every part. ?></span>
        <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__hint"><?php echo esc_html( $yayloyalty_thankyou['hint'] ); ?></span>
    </p>
</section>
