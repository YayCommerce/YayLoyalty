<?php
/**
 * The points card, in one of its shapes: a sign-in card for a guest (`guest`), or for a customer the header and
 * one list (`flexible` or `hidden`): the Points discount row, then the rows RedeemCard::ROWS_FILTER adds. The row
 * opens by `mode`: into a points field (`free_input`), or onto the most the cart allows, with no field (`auto_max`).
 * A customer's card also carries the checkout title, summary ("✓ 2 rewards selected"), Change and Update. The server
 * draws one markup for every page, because the blocks' card is shared by Cart and Checkout: redeem.js sets
 * `data-mode` from the page, and redeem.css shows those three only in checkout mode. `data-applied` marks a card
 * with something applied, which checkout shows collapsed.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the request.
 * Styled by assets/storefront/redeem.css alone: the Design tab's colours belong to the launcher.
 *
 * @var array $view RedeemCard::view() plus endpoint, nonce, account_url and money; a customer's view also
 *                  carries heading_balance, worth, rows, summary (points, count) and dropped (the names for
 *                  the amber notice; '' for a deleted reward).
 */

use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

$yayloyalty_state       = $view['state'];
$yayloyalty_is_flexible = 'flexible' === $yayloyalty_state;
$yayloyalty_is_auto     = $yayloyalty_is_flexible && 'auto_max' === $view['mode'];
$yayloyalty_money       = $view['money'];
$yayloyalty_label       = SettingsHelper::points_label_plural();
$yayloyalty_note        = '' !== ( $view['bound_by'] ?? '' ) ? RedemptionService::message_for( $view['bound_by'] ) : '';
// Rounding to the step reads neutral; every other bound is a limit that lowered the amount.
$yayloyalty_note_tone = Clamp::BOUND_STEP === ( $view['bound_by'] ?? '' ) ? '' : ' yayloyalty-redeem__note--warning';
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
    <?php if ( $yayloyalty_is_flexible ) : ?>
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
            <span class="yayloyalty-redeem__badge"><?php echo wp_kses( $yayloyalty_icon, Kses::svg() ); ?></span>
            <div class="yayloyalty-redeem__guest-text">
                <h3 class="yayloyalty-redeem__heading"><?php echo wp_kses( RedeemCard::heading( null, null ), Kses::inline() ); ?></h3>
                <p class="yayloyalty-redeem__text"><?php echo esc_html( RedemptionService::message_for( ProgramGate::REASON_NO_CUSTOMER ) ); ?></p>
            </div>
            <a class="yayloyalty-redeem__signin" href="<?php echo esc_url( $view['account_url'] ); ?>"><?php esc_html_e( 'Sign in', 'yayloyalty' ); ?></a>
        </div>
    <?php else : ?>
        <div class="yayloyalty-redeem__header">
            <?php echo wp_kses( $yayloyalty_icon, Kses::svg() ); ?>
            <h3 class="yayloyalty-redeem__heading"><?php echo wp_kses( RedeemCard::heading( $view['heading_balance'], $view['worth'] ), Kses::inline() ); ?></h3>
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

        <?php if ( ! empty( $view['dropped'] ) ) : ?>
            <div class="yayloyalty-redeem__alert" data-yl-alert role="status">
                <?php foreach ( $view['dropped'] as $yayloyalty_dropped ) : ?>
                    <p><?php echo esc_html( RedeemCard::dropped_line( $yayloyalty_dropped ) ); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="yayloyalty-redeem__list" data-yl-list id="<?php echo esc_attr( $yayloyalty_list_id ); ?>" data-balance="<?php echo esc_attr( (string) $view['heading_balance'] ); ?>">
            <?php if ( $yayloyalty_is_flexible ) : ?>
                <?php
                $yayloyalty_is_ticked  = $view['requested'] > 0;
                $yayloyalty_is_stuck   = $view['max'] < $view['min'];
                $yayloyalty_preview_at = $view['points'] > 0 ? $view['value'] : $view['max_value'];
                $yayloyalty_auto_at    = $view['points'] > 0 ? $view['points'] : $view['max'];
                // A field whose max is below its min is invalid, so a field with nothing to give sits at the minimum.
                $yayloyalty_value = $view['points'] > 0 ? $view['points'] : max( $view['min'], $view['max'] );
                // What redeem.js shows when a typed amount is rewritten on blur, or refused.
                /* translators: %s: points amount with the store's label, as "2,450 Points". */
                $yayloyalty_max_note = sprintf( __( 'You can use up to %s on this order.', 'yayloyalty' ), SettingsHelper::points_text( $view['max'] ) );
                /* translators: %s: points amount with the store's label, as "100 Points". */
                $yayloyalty_step_note = sprintf( __( 'Rounded down to a multiple of %s.', 'yayloyalty' ), SettingsHelper::points_text( $view['effective_step'] ) );
                /* translators: %s: points amount with the store's label, as "100 Points". */
                $yayloyalty_min_error = sprintf( __( 'Enter at least %s.', 'yayloyalty' ), SettingsHelper::points_text( $view['min'] ) );
                ?>
                <div class="yayloyalty-redeem__item" data-yl-flexible>
                    <label class="yayloyalty-redeem__option">
                        <span class="yayloyalty-redeem__option-body">
                            <span class="yayloyalty-redeem__name"><?php echo esc_html( $view['name'] ); ?></span>
                            <?php if ( $yayloyalty_is_auto ) : ?>
                                <span class="yayloyalty-redeem__chip"><?php echo esc_html( SettingsHelper::points_text( $view['max'] ) ); ?></span>
                            <?php else : ?>
                                <span class="yayloyalty-redeem__chip"><?php esc_html_e( 'Flexible amount', 'yayloyalty' ); ?></span>
                            <?php endif; ?>
                        </span>
                        <input
                            type="checkbox"
                            class="yayloyalty-redeem__check"
                            data-yl-flexible-toggle
                            data-requested="<?php echo esc_attr( (string) $view['requested'] ); ?>"
                            data-max="<?php echo esc_attr( (string) $view['max'] ); ?>"
                            <?php checked( $yayloyalty_is_ticked ); ?>
                            <?php disabled( $view['is_disabled'] ); ?>
                        >
                    </label>
                    <div class="yayloyalty-redeem__body" data-yl-flexible-body<?php echo $yayloyalty_is_ticked ? '' : ' hidden'; ?>>
                        <?php if ( $yayloyalty_is_auto ) : ?>
                            <p class="yayloyalty-redeem__auto">
                                <?php
                                /* translators: 1: points amount with the store's label, as "1,200 Points", 2: money amount. */
                                echo esc_html( sprintf( __( '%1$s = %2$s off', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_auto_at ), LauncherCopy::money( $yayloyalty_preview_at ) ) );
                                ?>
                            </p>
                        <?php else : ?>
                            <div class="yayloyalty-redeem__limits">
                                <span>
                                    <?php
                                    /* translators: %s: points amount with the store's label, as "100 Points". */
                                    echo esc_html( sprintf( __( 'Min %s', 'yayloyalty' ), SettingsHelper::points_text( $view['min'] ) ) );
                                    ?>
                                </span>
                                <span>
                                    <?php
                                    /* translators: 1: points amount with the store's label, as "2,450 Points", 2: what they take off, as "$24.50". */
                                    echo esc_html( sprintf( __( 'Use up to %1$s (= %2$s)', 'yayloyalty' ), SettingsHelper::points_text( $view['max'] ), LauncherCopy::money( $view['max_value'] ) ) );
                                    ?>
                                </span>
                            </div>
                            <div class="yayloyalty-redeem__controls">
                                <span class="yayloyalty-redeem__stepper">
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        pattern="[0-9]*"
                                        class="yayloyalty-redeem__points"
                                        data-yl-points
                                        data-max-note="<?php echo esc_attr( $yayloyalty_max_note ); ?>"
                                        data-step-note="<?php echo esc_attr( $yayloyalty_step_note ); ?>"
                                        data-min-error="<?php echo esc_attr( $yayloyalty_min_error ); ?>"
                                        min="<?php echo esc_attr( (string) $view['min'] ); ?>"
                                        max="<?php echo esc_attr( (string) $view['max'] ); ?>"
                                        step="<?php echo esc_attr( (string) $view['effective_step'] ); ?>"
                                        value="<?php echo esc_attr( (string) $yayloyalty_value ); ?>"
                                        aria-label="<?php echo esc_attr( $yayloyalty_aria ); ?>"
                                        <?php disabled( $yayloyalty_is_stuck ); ?>
                                    >
                                    <?php // The field's own arrow keys step it, so the chevrons stay out of the tab order and the accessibility tree. ?>
                                    <span class="yayloyalty-redeem__chevrons" aria-hidden="true">
                                        <button type="button" class="yayloyalty-redeem__chevron" data-yl-step="up" tabindex="-1" <?php disabled( $yayloyalty_is_stuck ); ?>>
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m18 15-6-6-6 6"/></svg>
                                        </button>
                                        <button type="button" class="yayloyalty-redeem__chevron" data-yl-step="down" tabindex="-1" <?php disabled( $yayloyalty_is_stuck ); ?>>
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
                                        </button>
                                    </span>
                                </span>
                                <span class="yayloyalty-redeem__off" data-yl-off><?php echo esc_html( sprintf( $yayloyalty_off_template, LauncherCopy::money( $yayloyalty_preview_at ) ) ); ?></span>
                            </div>
                            <p class="yayloyalty-redeem__note" data-yl-field-note role="status" hidden></p>
                        <?php endif; ?>
                        <?php if ( '' !== $yayloyalty_note ) : ?>
                            <p class="yayloyalty-redeem__note<?php echo esc_attr( $yayloyalty_note_tone ); ?>" data-yl-server-note role="status"><?php echo esc_html( $yayloyalty_note ); ?></p>
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
