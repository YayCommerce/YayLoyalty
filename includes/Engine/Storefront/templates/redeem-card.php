<?php
/**
 * The points card: a sign-in line for a guest, or for a customer the slider (its form or its applied statement)
 * beside the panels RedeemCard::PANELS_FILTER adds, as tabs when there are two.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the request.
 * Styled by assets/storefront/redeem.css alone: the Design tab's colours belong to the launcher.
 *
 * @var array $view RedeemCard::view() (or state `none`), heading_balance, panels, active, plus
 *                  endpoint, nonce, account_url and money.
 */

use YayLoyalty\Engine\Redemption\CouponProvider;
use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\RedeemCard;
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
// A guest's heading has no balance.
$yayloyalty_balance = 'guest' === $yayloyalty_state ? null : $view['heading_balance'];
// The added panels' tabs, then the slider's; a bar is drawn only when there are two to choose from.
$yayloyalty_tabs = array_map(
    static function ( array $panel ): string {
        return $panel['label'];
    },
    $view['panels']
);
if ( 'none' !== $yayloyalty_state ) {
    $yayloyalty_tabs[ RedeemCard::SLIDER_PANEL ] = CouponProvider::display_label();
}
$yayloyalty_has_tabs = count( $yayloyalty_tabs ) > 1;
$yayloyalty_uid      = wp_unique_id( 'yayloyalty-redeem-' );
// Opens one tab's panel, paired with its tab by id; hidden unless it is the open one.
$yayloyalty_open_panel = static function ( string $key ) use ( $yayloyalty_uid, $view ): void {
    printf(
        '<div class="yayloyalty-redeem__panel" role="tabpanel" id="%1$s" aria-labelledby="%2$s"%3$s>',
        esc_attr( $yayloyalty_uid . '-panel-' . $key ),
        esc_attr( $yayloyalty_uid . '-tab-' . $key ),
        $view['active'] === $key ? '' : ' hidden'
    );
};
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
    <h3 class="yayloyalty-redeem__heading"><?php echo RedeemCard::heading( $yayloyalty_balance ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- heading() escapes every value. ?></h3>

    <?php if ( $yayloyalty_has_tabs ) : ?>
        <div class="yayloyalty-redeem__tabs" role="tablist">
            <?php foreach ( $yayloyalty_tabs as $yayloyalty_key => $yayloyalty_tab ) : ?>
                <?php $yayloyalty_is_open = $view['active'] === $yayloyalty_key; ?>
                <button
                    type="button"
                    class="yayloyalty-redeem__tab"
                    role="tab"
                    data-yl-tab
                    id="<?php echo esc_attr( $yayloyalty_uid . '-tab-' . $yayloyalty_key ); ?>"
                    aria-controls="<?php echo esc_attr( $yayloyalty_uid . '-panel-' . $yayloyalty_key ); ?>"
                    aria-selected="<?php echo $yayloyalty_is_open ? 'true' : 'false'; ?>"
                    tabindex="<?php echo $yayloyalty_is_open ? '0' : '-1'; ?>"
                ><?php echo esc_html( $yayloyalty_tab ); ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php foreach ( $view['panels'] as $yayloyalty_key => $yayloyalty_panel ) : ?>
        <?php
        if ( $yayloyalty_has_tabs ) {
            $yayloyalty_open_panel( $yayloyalty_key );
        }
        ?>
        <?php echo $yayloyalty_panel['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the filter's contract: a panel's html is escaped by whoever adds it. ?>
        <?php if ( $yayloyalty_has_tabs ) : ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php
    if ( $yayloyalty_has_tabs ) {
        $yayloyalty_open_panel( RedeemCard::SLIDER_PANEL );
    }
    ?>

    <?php if ( 'guest' === $yayloyalty_state ) : ?>
        <p class="yayloyalty-redeem__text">
            <a class="yayloyalty-redeem__signin" href="<?php echo esc_url( $view['account_url'] ); ?>"><?php echo esc_html( RedemptionService::message_for( ProgramGate::REASON_NO_CUSTOMER ) ); ?></a>
        </p>
    <?php elseif ( 'form' === $yayloyalty_state ) : ?>
        <div class="yayloyalty-redeem__box">
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
            <?php if ( '' !== $yayloyalty_note ) : ?>
                <p class="yayloyalty-redeem__note" role="status"><?php echo esc_html( $yayloyalty_note ); ?></p>
            <?php endif; ?>
        </div>
    <?php elseif ( 'applied' === $yayloyalty_state ) : ?>
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

    <?php if ( $yayloyalty_has_tabs ) : ?>
        </div>
    <?php endif; ?>
</div>
