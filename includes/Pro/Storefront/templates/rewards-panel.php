<?php
/**
 * The card's Rewards tab: a checkbox per reward, the selection line and one Apply/Update button.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the ticked ids.
 * The data- attributes carry every sentence redeem.js rewrites, so the script prints no English.
 *
 * @var array $view rows, is_applied, spent and balance, from RewardsPanel::panel().
 */

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Pro\Storefront\RewardsPanel;

defined( 'ABSPATH' ) || exit;

$yayloyalty_spent   = $view['spent'];
$yayloyalty_applied = $view['is_applied'];
/* translators: %s: number of rewards ticked. */
$yayloyalty_none = sprintf( __( '%s selected', 'yayloyalty' ), '0' );

if ( $yayloyalty_applied ) {
    /* translators: 1: points amount with the store's label, as "2,000 Points", 2: the number of points left, as "7,850". */
    $yayloyalty_line = sprintf( __( '%1$s will be used · %2$s remaining', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_spent ), SettingsHelper::format_number( $view['balance'] - $yayloyalty_spent ) );
} else {
    $yayloyalty_line = $yayloyalty_none;
}
?>
<div
    class="yayloyalty-redeem__rewards"
    data-yl-rewards
    data-balance="<?php echo esc_attr( (string) $view['balance'] ); ?>"
    data-short-text="<?php echo esc_attr( RewardsPanel::short_text() ); ?>"
    data-none-text="<?php echo esc_attr( $yayloyalty_none ); ?>"
    <?php /* translators: 1: number of rewards ticked, 2: their summed cost with the store's label, as "2,000 Points". */ ?>
    data-selection-template="<?php echo esc_attr( __( '%1$s selected · %2$s', 'yayloyalty' ) ); ?>"
    <?php /* translators: 1: number of points, 2: the store's points label, e.g. "Points". */ ?>
    data-points-template="<?php echo esc_attr( _x( '%1$s %2$s', 'points amount', 'yayloyalty' ) ); ?>"
    data-label-one="<?php echo esc_attr( SettingsHelper::points_label( 1 ) ); ?>"
    data-label-many="<?php echo esc_attr( SettingsHelper::points_label_plural() ); ?>"
    data-thousand-sep="<?php echo esc_attr( wc_get_price_thousand_separator() ); ?>"
>
    <div class="yayloyalty-redeem__options">
        <?php foreach ( $view['rows'] as $yayloyalty_row ) : ?>
            <?php
            $yayloyalty_is_off   = $yayloyalty_row['is_denied'] || $yayloyalty_row['is_short'];
            $yayloyalty_row_text = $yayloyalty_row['is_short'] ? RewardsPanel::short_text() : $yayloyalty_row['text'];
            ?>
            <label class="yayloyalty-redeem__option">
                <input
                    type="checkbox"
                    class="yayloyalty-redeem__check"
                    data-yl-reward
                    value="<?php echo esc_attr( (string) $yayloyalty_row['id'] ); ?>"
                    data-cost="<?php echo esc_attr( (string) $yayloyalty_row['cost'] ); ?>"
                    <?php echo $yayloyalty_row['is_denied'] ? 'data-denied' : ''; ?>
                    <?php checked( $yayloyalty_row['is_applied'] ); ?>
                    <?php disabled( $yayloyalty_is_off ); ?>
                >
                <span class="yayloyalty-redeem__option-body">
                    <span class="yayloyalty-redeem__option-head">
                        <span class="yayloyalty-redeem__name"><?php echo esc_html( $yayloyalty_row['name'] ); ?></span>
                        <?php if ( $yayloyalty_row['is_applied'] ) : ?>
                            <span class="yayloyalty-redeem__tag"><?php esc_html_e( 'Applied', 'yayloyalty' ); ?></span>
                        <?php endif; ?>
                        <span class="yayloyalty-redeem__cost"><?php echo esc_html( SettingsHelper::points_text( $yayloyalty_row['cost'] ) ); ?></span>
                    </span>
                    <span class="yayloyalty-redeem__desc" data-yl-desc data-text="<?php echo esc_attr( $yayloyalty_row['text'] ); ?>"><?php echo esc_html( $yayloyalty_row_text ); ?></span>
                    <?php if ( '' !== $yayloyalty_row['here'] ) : ?>
                        <span class="yayloyalty-redeem__here"><?php echo esc_html( $yayloyalty_row['here'] ); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="yayloyalty-redeem__footer">
        <p class="yayloyalty-redeem__selection" data-yl-selection data-text="<?php echo esc_attr( $yayloyalty_line ); ?>"><?php echo esc_html( $yayloyalty_line ); ?></p>
        <button type="button" class="yayloyalty-redeem__apply" data-yl-apply-rewards disabled><?php echo esc_html( $yayloyalty_applied ? __( 'Update', 'yayloyalty' ) : __( 'Apply', 'yayloyalty' ) ); ?></button>
    </div>
</div>
