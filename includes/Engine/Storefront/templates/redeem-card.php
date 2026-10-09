<?php
/**
 * The points card: a sign-in line for a guest, the slider form, or the applied statement.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the request.
 * Styled by assets/storefront/redeem.css alone: the Design tab's text and colours belong to the launcher.
 *
 * @var array $view RedeemCard::view() plus endpoint, nonce, account_url and money.
 */

use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_state = $view['state'];
$yayloyalty_money = $view['money'];
$yayloyalty_label = SettingsHelper::points_label_plural();
$yayloyalty_note  = '' !== ( $view['bound_by'] ?? '' ) ? RedemptionService::message_for( $view['bound_by'] ) : '';
/* translators: %s: money amount. */
$yayloyalty_off_template = __( '= %s off', 'yayloyalty' );
$yayloyalty_is_disabled  = ! empty( $view['is_disabled'] );
/* translators: %s: the store's points label, as "Points". */
$yayloyalty_aria = sprintf( __( '%s to spend', 'yayloyalty' ), $yayloyalty_label );
?>
<div
    class="yayloyalty-redeem"
    data-yl-redeem
    data-state="<?php echo esc_attr( $yayloyalty_state ); ?>"
    data-endpoint="<?php echo esc_url( $view['endpoint'] ); ?>"
    data-nonce="<?php echo esc_attr( $view['nonce'] ); ?>"
    <?php if ( 'form' === $yayloyalty_state ) : ?>
        data-rate="<?php echo esc_attr( (string) $view['rate'] ); ?>"
        data-decimals="<?php echo esc_attr( (string) $yayloyalty_money['decimals'] ); ?>"
        data-decimal-sep="<?php echo esc_attr( $yayloyalty_money['decimal_sep'] ); ?>"
        data-thousand-sep="<?php echo esc_attr( $yayloyalty_money['thousand_sep'] ); ?>"
        data-price-format="<?php echo esc_attr( $yayloyalty_money['format'] ); ?>"
        data-symbol="<?php echo esc_attr( $yayloyalty_money['symbol'] ); ?>"
        data-off-template="<?php echo esc_attr( $yayloyalty_off_template ); ?>"
    <?php endif; ?>
>
    <h3 class="yayloyalty-redeem__heading">
        <?php
        /* translators: %s: the store's points label, as "Points". */
        echo esc_html( sprintf( __( 'Your %s', 'yayloyalty' ), $yayloyalty_label ) );
        ?>
    </h3>

    <?php if ( 'guest' === $yayloyalty_state ) : ?>
        <p class="yayloyalty-redeem__text">
            <a class="yayloyalty-redeem__signin" href="<?php echo esc_url( $view['account_url'] ); ?>"><?php echo esc_html( RedemptionService::message_for( ProgramGate::REASON_NO_CUSTOMER ) ); ?></a>
        </p>
    <?php elseif ( 'form' === $yayloyalty_state ) : ?>
        <div class="yayloyalty-redeem__box">
            <p class="yayloyalty-redeem__title">
                <?php
                if ( null === $view['worth'] ) {
                    /* translators: %s: points amount with the store's label, as "9,850 Points". */
                    echo esc_html( sprintf( __( 'You have %s', 'yayloyalty' ), SettingsHelper::points_text( $view['balance'] ) ) );
                } else {
                    /* translators: 1: points amount with the store's label, as "9,850 Points", 2: money amount. */
                    echo esc_html( sprintf( __( 'You have %1$s (%2$s)', 'yayloyalty' ), SettingsHelper::points_text( $view['balance'] ), LauncherCopy::money( $view['worth'] ) ) );
                }
                ?>
            </p>
            <?php // A range whose max is below its min is invalid, so a disabled slider sits at the minimum. ?>
            <input
                type="range"
                class="yayloyalty-redeem__range"
                data-yl-range
                min="<?php echo esc_attr( (string) $view['min'] ); ?>"
                max="<?php echo esc_attr( (string) max( $view['min'], $view['max'] ) ); ?>"
                step="<?php echo esc_attr( (string) $view['step'] ); ?>"
                value="<?php echo esc_attr( (string) max( $view['min'], $view['max'] ) ); ?>"
                aria-label="<?php echo esc_attr( $yayloyalty_aria ); ?>"
                <?php disabled( $yayloyalty_is_disabled ); ?>
            >
            <div class="yayloyalty-redeem__limits">
                <span>
                    <?php
                    /* translators: %s: points amount with the store's label, as "100 Points". */
                    echo esc_html( sprintf( __( '%s minimum', 'yayloyalty' ), SettingsHelper::points_text( $view['min'] ) ) );
                    ?>
                </span>
                <span>
                    <?php
                    /* translators: %s: money amount. */
                    echo esc_html( sprintf( __( 'up to %s here', 'yayloyalty' ), LauncherCopy::money( $view['max_value'] ) ) );
                    ?>
                </span>
            </div>
            <div class="yayloyalty-redeem__controls">
                <input
                    type="number"
                    inputmode="numeric"
                    class="yayloyalty-redeem__points"
                    data-yl-points
                    min="<?php echo esc_attr( (string) $view['min'] ); ?>"
                    max="<?php echo esc_attr( (string) $view['max'] ); ?>"
                    step="<?php echo esc_attr( (string) $view['step'] ); ?>"
                    value="<?php echo esc_attr( (string) $view['max'] ); ?>"
                    aria-label="<?php echo esc_attr( $yayloyalty_aria ); ?>"
                    <?php disabled( $yayloyalty_is_disabled ); ?>
                >
                <span class="yayloyalty-redeem__unit"><?php echo esc_html( $yayloyalty_label ); ?></span>
                <span class="yayloyalty-redeem__off" data-yl-off><?php echo esc_html( sprintf( $yayloyalty_off_template, LauncherCopy::money( $view['max_value'] ) ) ); ?></span>
                <button type="button" class="yayloyalty-redeem__apply" data-yl-apply <?php disabled( $yayloyalty_is_disabled ); ?>><?php esc_html_e( 'Apply', 'yayloyalty' ); ?></button>
            </div>
            <?php if ( ! $yayloyalty_is_disabled ) : ?>
                <p class="yayloyalty-redeem__help"><?php esc_html_e( 'Drag the slider or type an exact number.', 'yayloyalty' ); ?></p>
            <?php endif; ?>
            <?php if ( '' !== $yayloyalty_note ) : ?>
                <p class="yayloyalty-redeem__note" role="status"><?php echo esc_html( $yayloyalty_note ); ?></p>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="yayloyalty-redeem__box">
            <p class="yayloyalty-redeem__title">
                <?php
                /* translators: 1: points amount with the store's label, as "5,000 Points", 2: money amount. */
                echo esc_html( sprintf( __( '%1$s applied — %2$s off', 'yayloyalty' ), SettingsHelper::points_text( $view['points'] ), LauncherCopy::money( $view['value'] ) ) );
                ?>
            </p>
            <div class="yayloyalty-redeem__row">
                <span>
                    <?php
                    /* translators: %s: points amount with the store's label, as "4,850 Points". */
                    echo esc_html( sprintf( __( '%s remaining', 'yayloyalty' ), SettingsHelper::points_text( $view['remaining'] ) ) );
                    ?>
                </span>
                <button type="button" class="yayloyalty-redeem__remove" data-yl-remove><?php esc_html_e( 'Remove', 'yayloyalty' ); ?></button>
            </div>
            <?php if ( '' !== $yayloyalty_note ) : ?>
                <p class="yayloyalty-redeem__note" role="status"><?php echo esc_html( $yayloyalty_note ); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
