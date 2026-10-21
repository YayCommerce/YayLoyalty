<?php
/**
 * The guest panel's top card, in the points card's frame: on a product page what buying it earns and is
 * worth, elsewhere the signed-out prompt. Styled by assets/storefront/account/guest-card.css
 * on top of points-card.css.
 *
 * @var array $view            Launcher::view(); `estimate` the product's points, null off a product page, and
 *                             `estimate_off` what they take off an order, null when the discount wouldn't take them.
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_estimate = $view['estimate'];
?>
<section class="yayloyalty-account__card yayloyalty-account__guest-card">
    <div class="yayloyalty-account__card-body">
        <?php if ( null !== $yayloyalty_estimate ) : ?>
            <h2 class="yayloyalty-account__heading"><?php esc_html_e( 'Earn with this purchase', 'yayloyalty' ); ?></h2>
            <p class="yayloyalty-account__amount">
                <span class="yayloyalty-account__balance"><?php echo esc_html( LauncherCopy::points( $yayloyalty_estimate ) ); ?></span>
                <span class="yayloyalty-account__unit"><?php echo esc_html( SettingsHelper::points_label( $yayloyalty_estimate ) ); ?></span>
            </p>
            <?php if ( null !== $view['estimate_off'] ) : ?>
                <p class="yayloyalty-account__caption">
                    <strong>
                        <?php
                        /* translators: %s: money amount. */
                        echo esc_html( sprintf( __( '%s off', 'yayloyalty' ), LauncherCopy::money( $view['estimate_off'] ) ) );
                        ?>
                    </strong>
                    <?php esc_html_e( 'your next order', 'yayloyalty' ); ?>
                </p>
            <?php endif; ?>
        <?php else : ?>
            <h2 class="yayloyalty-account__guest-prompt"><?php echo esc_html( LauncherCopy::signed_out_prompt() ); ?></h2>
            <p class="yayloyalty-account__caption">
                <?php
                /* translators: %s: the store's points label, as "Points". */
                echo esc_html( sprintf( __( 'Collect %s on every order and spend them on money off your next one. Free to join.', 'yayloyalty' ), SettingsHelper::points_label_plural() ) );
                ?>
            </p>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/card-art.php'; ?>
</section>
