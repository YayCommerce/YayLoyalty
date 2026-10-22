<?php
/**
 * The card's reward rows: one line per reward, its name, its cost and a checkbox, listed after the Points discount
 * row and ticked to apply. A row that can't be ticked is disabled; a refused one also has its name struck through.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the ticked ids.
 *
 * @var array $view rows and balance, from RewardsPanel::rows().
 */

use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;
?>
<div
    class="yayloyalty-redeem__rewards"
    data-yl-rewards
    data-balance="<?php echo esc_attr( (string) $view['balance'] ); ?>"
>
    <?php foreach ( $view['rows'] as $yayloyalty_row ) : ?>
        <label class="yayloyalty-redeem__option">
            <span class="yayloyalty-redeem__option-body">
                <span class="yayloyalty-redeem__name"><?php echo esc_html( $yayloyalty_row['name'] ); ?></span>
                <span class="yayloyalty-redeem__chip"><?php echo esc_html( SettingsHelper::points_text( $yayloyalty_row['cost'] ) ); ?></span>
            </span>
            <input
                type="checkbox"
                class="yayloyalty-redeem__check"
                data-yl-reward
                value="<?php echo esc_attr( (string) $yayloyalty_row['id'] ); ?>"
                data-cost="<?php echo esc_attr( (string) $yayloyalty_row['cost'] ); ?>"
                <?php echo $yayloyalty_row['is_denied'] ? 'data-denied' : ''; ?>
                <?php checked( $yayloyalty_row['is_applied'] ); ?>
                <?php disabled( $yayloyalty_row['is_denied'] || $yayloyalty_row['is_short'] ); ?>
            >
        </label>
    <?php endforeach; ?>
</div>
