<?php
/**
 * The points card, in one of its shapes: a sign-in card for a guest (`guest`), or for a customer the header and
 * one list (`slider` or `hidden`): the Points discount row, which opens into the slider, then the rows
 * RedeemCard::ROWS_FILTER adds.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the request.
 * Styled by assets/storefront/redeem.css alone: the Design tab's colours belong to the launcher.
 *
 * @var array $view RedeemCard::view(), heading_balance, worth, rows and summary (points, value), plus
 *                  endpoint, nonce, account_url and money.
 */

use YayLoyalty\Engine\Redemption\CouponProvider;
use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_state     = $view['state'];
$yayloyalty_is_slider = 'slider' === $yayloyalty_state;
$yayloyalty_money     = $view['money'];
$yayloyalty_label     = SettingsHelper::points_label_plural();
$yayloyalty_note      = '' !== ( $view['bound_by'] ?? '' ) ? RedemptionService::message_for( $view['bound_by'] ) : '';
/* translators: 1: the store's points label, as "Points", 2: money amount. */
$yayloyalty_off_template = sprintf( __( '%1$s = %2$s', 'yayloyalty' ), $yayloyalty_label, '%s' );
/* translators: %s: the store's points label, as "Points". */
$yayloyalty_aria = sprintf( __( '%s to spend', 'yayloyalty' ), $yayloyalty_label );
$yayloyalty_icon = '<svg class="yayloyalty-redeem__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C9.5 3 12 8 12 8s2.5-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';
?>
<div
    class="yayloyalty-redeem"
    data-yl-redeem
    data-state="<?php echo esc_attr( $yayloyalty_state ); ?>"
    data-endpoint="<?php echo esc_url( $view['endpoint'] ); ?>"
    data-nonce="<?php echo esc_attr( $view['nonce'] ); ?>"
    <?php if ( $yayloyalty_is_slider ) : ?>
        data-rate="<?php echo esc_attr( (string) $view['rate'] ); ?>"
        data-decimals="<?php echo esc_attr( (string) $yayloyalty_money['decimals'] ); ?>"
        data-decimal-sep="<?php echo esc_attr( $yayloyalty_money['decimal_sep'] ); ?>"
        data-thousand-sep="<?php echo esc_attr( $yayloyalty_money['thousand_sep'] ); ?>"
        data-price-format="<?php echo esc_attr( $yayloyalty_money['format'] ); ?>"
        data-symbol="<?php echo esc_attr( $yayloyalty_money['symbol'] ); ?>"
        data-off-template="<?php echo esc_attr( $yayloyalty_off_template ); ?>"
    <?php endif; ?>
>
    <?php if ( 'guest' === $yayloyalty_state ) : ?>
        <div class="yayloyalty-redeem__guest">
            <span class="yayloyalty-redeem__badge"><?php echo $yayloyalty_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG literal. ?></span>
            <h3 class="yayloyalty-redeem__heading"><?php echo RedeemCard::heading( null, null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- heading() escapes every value. ?></h3>
            <p class="yayloyalty-redeem__text"><?php echo esc_html( RedemptionService::message_for( ProgramGate::REASON_NO_CUSTOMER ) ); ?></p>
            <a class="yayloyalty-redeem__signin" href="<?php echo esc_url( $view['account_url'] ); ?>"><?php esc_html_e( 'Sign in', 'yayloyalty' ); ?></a>
        </div>
    <?php else : ?>
        <div class="yayloyalty-redeem__header">
            <?php echo $yayloyalty_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG literal. ?>
            <h3 class="yayloyalty-redeem__heading"><?php echo RedeemCard::heading( $view['heading_balance'], $view['worth'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- heading() escapes every value. ?></h3>
        </div>

        <div class="yayloyalty-redeem__list" data-yl-list id="<?php echo esc_attr( wp_unique_id( 'yayloyalty-redeem-' ) . '-list' ); ?>">
            <?php if ( $yayloyalty_is_slider ) : ?>
                <?php
                $yayloyalty_is_ticked = $view['requested'] > 0;
                // A range whose max is below its min is invalid, so a slider with nothing to give sits at the minimum.
                $yayloyalty_top        = max( $view['min'], $view['max'] );
                $yayloyalty_is_stuck   = $view['max'] < $view['min'];
                $yayloyalty_preview_at = $view['points'] > 0 ? $view['value'] : $view['max_value'];
                ?>
                <div class="yayloyalty-redeem__item" data-yl-slider>
                    <label class="yayloyalty-redeem__option">
                        <span class="yayloyalty-redeem__option-body">
                            <span class="yayloyalty-redeem__name"><?php echo esc_html( CouponProvider::display_label() ); ?></span>
                            <span class="yayloyalty-redeem__chip"><?php esc_html_e( 'Flexible amount', 'yayloyalty' ); ?></span>
                            <?php if ( $view['is_disabled'] ) : ?>
                                <span class="yayloyalty-redeem__desc">
                                    <?php
                                    /* translators: %s: points amount with the store's label, as "100 Points". */
                                    echo esc_html( sprintf( __( '%s minimum', 'yayloyalty' ), SettingsHelper::points_text( $view['min'] ) ) );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <input
                            type="checkbox"
                            class="yayloyalty-redeem__check"
                            data-yl-slider-toggle
                            <?php checked( $yayloyalty_is_ticked ); ?>
                            <?php disabled( $view['is_disabled'] ); ?>
                        >
                    </label>
                    <div class="yayloyalty-redeem__body" data-yl-slider-body<?php echo $yayloyalty_is_ticked ? '' : ' hidden'; ?>>
                        <div class="yayloyalty-redeem__limits">
                            <span>
                                <?php
                                /* translators: %s: points amount with the store's label, as "100 Points". */
                                echo esc_html( sprintf( __( 'Min %s', 'yayloyalty' ), SettingsHelper::points_text( $view['min'] ) ) );
                                ?>
                            </span>
                            <span>
                                <?php
                                /* translators: %s: points amount with the store's label, as "100 Points". */
                                echo esc_html( sprintf( __( 'Max %s', 'yayloyalty' ), SettingsHelper::points_text( $view['max'] ) ) );
                                ?>
                            </span>
                        </div>
                        <input
                            type="range"
                            class="yayloyalty-redeem__range"
                            data-yl-range
                            min="<?php echo esc_attr( (string) $view['min'] ); ?>"
                            max="<?php echo esc_attr( (string) $yayloyalty_top ); ?>"
                            step="<?php echo esc_attr( (string) $view['step'] ); ?>"
                            value="<?php echo esc_attr( (string) ( $view['points'] > 0 ? $view['points'] : $yayloyalty_top ) ); ?>"
                            aria-label="<?php echo esc_attr( $yayloyalty_aria ); ?>"
                            <?php disabled( $yayloyalty_is_stuck ); ?>
                        >
                        <div class="yayloyalty-redeem__controls">
                            <input
                                type="number"
                                inputmode="numeric"
                                class="yayloyalty-redeem__points"
                                data-yl-points
                                min="<?php echo esc_attr( (string) $view['min'] ); ?>"
                                max="<?php echo esc_attr( (string) $view['max'] ); ?>"
                                step="<?php echo esc_attr( (string) $view['step'] ); ?>"
                                value="<?php echo esc_attr( (string) ( $view['points'] > 0 ? $view['points'] : $yayloyalty_top ) ); ?>"
                                aria-label="<?php echo esc_attr( $yayloyalty_aria ); ?>"
                                <?php disabled( $yayloyalty_is_stuck ); ?>
                            >
                            <span class="yayloyalty-redeem__unit"><?php echo esc_html( $yayloyalty_label ); ?></span>
                            <span class="yayloyalty-redeem__off" data-yl-off><?php echo esc_html( sprintf( $yayloyalty_off_template, LauncherCopy::money( $yayloyalty_preview_at ) ) ); ?></span>
                        </div>
                        <?php if ( '' !== $yayloyalty_note ) : ?>
                            <p class="yayloyalty-redeem__note" role="status"><?php echo esc_html( $yayloyalty_note ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ( $view['rows'] as $yayloyalty_row ) : ?>
                <?php echo $yayloyalty_row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the rows filter's contract: each row is escaped by whoever adds it. ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
