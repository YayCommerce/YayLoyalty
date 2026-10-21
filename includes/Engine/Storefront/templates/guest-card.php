<?php
/**
 * The guest panel's top card, in the points card's frame: on a product page what buying it earns and is
 * worth, elsewhere the Design tab's signed-out prompt. Styled by assets/storefront/account/guest-card.css
 * on top of points-card.css.
 *
 * @var array $view            Launcher::view(); `estimate` the product's points, null off a product page.
 * @var array $yayloyalty_text The Design tab's storefront_text.
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_estimate = $view['estimate'];
$yayloyalty_prompt   = trim( $yayloyalty_text['signed_out_prompt'] );
$yayloyalty_off      = 0.0;

if ( null !== $yayloyalty_estimate && null !== $view['redeem'] ) {
    // Rounded down to the store's decimals: 180 points at 300 a unit is 0, never "1 off".
    $yayloyalty_scale = 10 ** wc_get_price_decimals();
    $yayloyalty_off   = floor( $yayloyalty_estimate / $view['redeem']['points_per_unit'] * $yayloyalty_scale ) / $yayloyalty_scale;
}
?>
<section class="yayloyalty-account__card yayloyalty-account__guest-card">
    <div class="yayloyalty-account__card-body">
        <?php if ( null !== $yayloyalty_estimate ) : ?>
            <h2 class="yayloyalty-account__heading"><?php esc_html_e( 'Earn with this purchase', 'yayloyalty' ); ?></h2>
            <p class="yayloyalty-account__amount">
                <span class="yayloyalty-account__balance"><?php echo esc_html( LauncherCopy::points( $yayloyalty_estimate ) ); ?></span>
                <span class="yayloyalty-account__unit"><?php echo esc_html( SettingsHelper::points_label( $yayloyalty_estimate ) ); ?></span>
            </p>
            <?php if ( $yayloyalty_off > 0 ) : ?>
                <p class="yayloyalty-account__caption">
                    <strong>
                        <?php
                        /* translators: %s: money amount. */
                        echo esc_html( sprintf( __( '%s off', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_off ) ) );
                        ?>
                    </strong>
                    <?php esc_html_e( 'your next order', 'yayloyalty' ); ?>
                </p>
            <?php endif; ?>
        <?php else : ?>
            <?php if ( '' !== $yayloyalty_prompt ) : ?>
                <h2 class="yayloyalty-account__guest-prompt" data-yl-text="signed_out_prompt"><?php echo esc_html( $yayloyalty_prompt ); ?></h2>
            <?php endif; ?>
            <p class="yayloyalty-account__caption">
                <?php
                /* translators: %s: the store's points label, as "Points". */
                echo esc_html( sprintf( __( 'Collect %s on every order and spend them on money off your next one. Free to join.', 'yayloyalty' ), SettingsHelper::points_label( 0 ) ) );
                ?>
            </p>
        <?php endif; ?>
    </div>
    <img class="yayloyalty-account__art" src="<?php echo esc_url( YAYLOYALTY_PLUGIN_URL . 'assets/storefront/account/points-illustration.svg' ); ?>" alt="" width="160" height="128" loading="lazy" decoding="async">
</section>
