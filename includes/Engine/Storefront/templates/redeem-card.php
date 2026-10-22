<?php
/**
 * The points card, in one of its shapes: a sign-in card for a guest (`guest`), or for a customer the header and
 * one list (`slider` or `hidden`): the Points discount row, which opens into the slider, then the rows
 * RedeemCard::ROWS_FILTER adds.
 * A customer's card also carries the checkout title, summary ("✓ 2 rewards selected"), Change and Update. The server
 * draws one markup for every page, because the blocks' card is shared by Cart and Checkout: redeem.js sets
 * `data-mode` from the page, and redeem.css shows those three only in checkout mode. `data-applied` marks a card
 * with something applied, which checkout shows collapsed.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the request.
 * Styled by assets/storefront/redeem.css alone: the Design tab's colours belong to the launcher.
 *
 * @var array $view RedeemCard::view() plus endpoint, nonce, account_url and money; a customer's view also
 *                  carries heading_balance, worth, rows and summary (points, count).
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
$yayloyalty_aria       = sprintf( __( '%s to spend', 'yayloyalty' ), $yayloyalty_label );
$yayloyalty_is_applied = 'guest' !== $yayloyalty_state && $view['summary']['points'] > 0;
$yayloyalty_list_id    = wp_unique_id( 'yayloyalty-redeem-' ) . '-list';
$yayloyalty_icon       = '<svg class="yayloyalty-redeem__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C9.5 3 12 8 12 8s2.5-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';
?>
<div
    class="yayloyalty-redeem"
    data-yl-redeem
    data-state="<?php echo esc_attr( $yayloyalty_state ); ?>"
    data-endpoint="<?php echo esc_url( $view['endpoint'] ); ?>"
    data-nonce="<?php echo esc_attr( $view['nonce'] ); ?>"
    <?php echo $yayloyalty_is_applied ? 'data-applied' : ''; ?>
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
            <div class="yayloyalty-redeem__guest-text">
                <h3 class="yayloyalty-redeem__heading"><?php echo RedeemCard::heading( null, null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- heading() escapes every value. ?></h3>
                <p class="yayloyalty-redeem__text"><?php echo esc_html( RedemptionService::message_for( ProgramGate::REASON_NO_CUSTOMER ) ); ?></p>
            </div>
            <a class="yayloyalty-redeem__signin" href="<?php echo esc_url( $view['account_url'] ); ?>"><?php esc_html_e( 'Sign in', 'yayloyalty' ); ?></a>
        </div>
    <?php else : ?>
        <div class="yayloyalty-redeem__header">
            <?php echo $yayloyalty_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG literal. ?>
            <h3 class="yayloyalty-redeem__heading"><?php echo RedeemCard::heading( $view['heading_balance'], $view['worth'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- heading() escapes every value. ?></h3>
            <?php if ( $yayloyalty_is_applied ) : ?>
                <p class="yayloyalty-redeem__summary" data-yl-summary>
                    <span class="yayloyalty-redeem__tick" aria-hidden="true">✓</span>
                    <?php
                    /* translators: %s: how many rewards are applied, the points discount counting as one. */
                    echo esc_html( sprintf( _n( '%s reward selected', '%s rewards selected', $view['summary']['count'], 'yayloyalty' ), number_format_i18n( $view['summary']['count'] ) ) );
                    ?>
                </p>
                <button type="button" class="yayloyalty-redeem__change" data-yl-change aria-expanded="false" aria-controls="<?php echo esc_attr( $yayloyalty_list_id ); ?>"><?php esc_html_e( 'Change', 'yayloyalty' ); ?></button>
            <?php endif; ?>
            <button type="button" class="yayloyalty-redeem__update" data-yl-update aria-controls="<?php echo esc_attr( $yayloyalty_list_id ); ?>" <?php disabled( ! $yayloyalty_is_applied ); ?>><?php esc_html_e( 'Update', 'yayloyalty' ); ?></button>
        </div>

        <div class="yayloyalty-redeem__list" data-yl-list id="<?php echo esc_attr( $yayloyalty_list_id ); ?>" data-balance="<?php echo esc_attr( (string) $view['heading_balance'] ); ?>">
            <?php if ( $yayloyalty_is_slider ) : ?>
                <?php
                $yayloyalty_is_ticked = $view['requested'] > 0;
                // A range whose max is below its min is invalid, so a slider with nothing to give sits at the minimum.
                $yayloyalty_top        = max( $view['min'], $view['max'] );
                $yayloyalty_is_stuck   = $view['max'] < $view['min'];
                $yayloyalty_preview_at = $view['points'] > 0 ? $view['value'] : $view['max_value'];
                $yayloyalty_value      = $view['points'] > 0 ? $view['points'] : $yayloyalty_top;
                // How far the track is filled: WebKit draws no fill of its own (redeem.css, redeem.js paintRange()).
                $yayloyalty_fill = $yayloyalty_top > $view['min'] ? ( $yayloyalty_value - $view['min'] ) / ( $yayloyalty_top - $view['min'] ) * 100 : 100;
                ?>
                <div class="yayloyalty-redeem__item" data-yl-slider>
                    <label class="yayloyalty-redeem__option">
                        <span class="yayloyalty-redeem__option-body">
                            <span class="yayloyalty-redeem__name"><?php echo esc_html( CouponProvider::display_label() ); ?></span>
                            <span class="yayloyalty-redeem__chip"><?php esc_html_e( 'Flexible amount', 'yayloyalty' ); ?></span>
                        </span>
                        <input
                            type="checkbox"
                            class="yayloyalty-redeem__check"
                            data-yl-slider-toggle
                            data-requested="<?php echo esc_attr( (string) $view['requested'] ); ?>"
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
                            style="--yayloyalty-redeem-fill: <?php echo esc_attr( (string) round( $yayloyalty_fill, 2 ) ); ?>%"
                            min="<?php echo esc_attr( (string) $view['min'] ); ?>"
                            max="<?php echo esc_attr( (string) $yayloyalty_top ); ?>"
                            step="<?php echo esc_attr( (string) $view['step'] ); ?>"
                            value="<?php echo esc_attr( (string) $yayloyalty_value ); ?>"
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
                                value="<?php echo esc_attr( (string) $yayloyalty_value ); ?>"
                                aria-label="<?php echo esc_attr( $yayloyalty_aria ); ?>"
                                <?php disabled( $yayloyalty_is_stuck ); ?>
                            >
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
