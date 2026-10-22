<?php
/**
 * One reward row of the card: its name, its cost and a checkbox, a list item beside the Points discount row, ticked
 * to apply. A row that can't be ticked is disabled; a refused one also has its name struck through.
 * No input has a `name`, so nothing here rides on the cart or checkout form it may sit in; redeem.js posts the ticked ids.
 *
 * @var array $view One row of RewardsPanel::row_views(): id, name, cost, is_applied, is_denied, is_short.
 */

use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;
?>
<div class="yayloyalty-redeem__item">
    <label class="yayloyalty-redeem__option">
        <span class="yayloyalty-redeem__option-body">
            <span class="yayloyalty-redeem__name"><?php echo esc_html( $view['name'] ); ?></span>
            <span class="yayloyalty-redeem__chip"><?php echo esc_html( SettingsHelper::points_text( $view['cost'] ) ); ?></span>
        </span>
        <input
            type="checkbox"
            class="yayloyalty-redeem__check"
            data-yl-reward
            value="<?php echo esc_attr( (string) $view['id'] ); ?>"
            data-cost="<?php echo esc_attr( (string) $view['cost'] ); ?>"
            <?php echo $view['is_denied'] ? 'data-denied' : ''; ?>
            <?php checked( $view['is_applied'] ); ?>
            <?php disabled( $view['is_denied'] || $view['is_short'] ); ?>
        >
    </label>
</div>
