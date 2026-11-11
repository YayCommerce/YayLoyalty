<?php
/**
 * The cart and checkout earn card. Styled by assets/storefront/cart-earn.css.
 *
 * @var array{line: string, bonuses: string[], guest: string} $yayloyalty_cart_earn CartEarnPreview::markup(), every
 *      value already escaped HTML: `line` is LauncherCopy::points_sentence_html() ('' when the order earns nothing),
 *      `bonuses` LauncherCopy::product_bonus() lines, `guest` ProductEarnMessage::guest_line() ('' for a member).
 */

use YayLoyalty\Engine\Storefront\AccountMyPointsIcon;
use YayLoyalty\Engine\Storefront\CartEarnPreview;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

$yayloyalty_class = CartEarnPreview::CSS_CLASS;
?>
<div class="<?php echo esc_attr( $yayloyalty_class ); ?>">
    <?php if ( '' !== $yayloyalty_cart_earn['line'] ) : ?>
        <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__line">
            <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__icon"><?php echo wp_kses( AccountMyPointsIcon::get( 'gift' ), Kses::svg() ); ?></span>
            <span class="<?php echo esc_attr( $yayloyalty_class ); ?>__text"><?php echo wp_kses( $yayloyalty_cart_earn['line'], Kses::inline() ); ?></span>
        </p>
    <?php endif; ?>
    <?php foreach ( $yayloyalty_cart_earn['bonuses'] as $yayloyalty_bonus ) : ?>
        <p class="<?php echo esc_attr( $yayloyalty_class ); ?>__bonus"><?php echo wp_kses( $yayloyalty_bonus, Kses::inline() ); ?></p>
    <?php endforeach; ?>
    <?php echo wp_kses_post( $yayloyalty_cart_earn['guest'] ); ?>
</div>
