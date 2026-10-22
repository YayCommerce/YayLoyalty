<?php
/**
 * The card's reward rows: a checkbox row per reward, listed after the Points discount row and ticked to apply.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the ticked ids.
 * The data- attributes carry every sentence redeem.js rewrites, so the script prints no English.
 *
 * @var array $view rows and balance, from RewardsPanel::rows().
 */

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Pro\Storefront\RewardsPanel;

defined( 'ABSPATH' ) || exit;
?>
<div
    class="yayloyalty-redeem__rewards"
    data-yl-rewards
    data-balance="<?php echo esc_attr( (string) $view['balance'] ); ?>"
    data-short-text="<?php echo esc_attr( RewardsPanel::short_text() ); ?>"
>
    <?php foreach ( $view['rows'] as $yayloyalty_row ) : ?>
        <?php
        $yayloyalty_is_off   = $yayloyalty_row['is_denied'] || $yayloyalty_row['is_short'];
        $yayloyalty_row_text = $yayloyalty_row['is_short'] ? RewardsPanel::short_text() : $yayloyalty_row['text'];
        ?>
        <label class="yayloyalty-redeem__option">
            <span class="yayloyalty-redeem__option-body">
                <span class="yayloyalty-redeem__name"><?php echo esc_html( $yayloyalty_row['name'] ); ?></span>
                <span class="yayloyalty-redeem__chip"><?php echo esc_html( SettingsHelper::points_text( $yayloyalty_row['cost'] ) ); ?></span>
                <span class="yayloyalty-redeem__desc" data-yl-desc data-text="<?php echo esc_attr( $yayloyalty_row['text'] ); ?>"><?php echo esc_html( $yayloyalty_row_text ); ?></span>
                <?php if ( '' !== $yayloyalty_row['here'] ) : ?>
                    <span class="yayloyalty-redeem__here"><?php echo esc_html( $yayloyalty_row['here'] ); ?></span>
                <?php endif; ?>
            </span>
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
        </label>
    <?php endforeach; ?>
</div>
