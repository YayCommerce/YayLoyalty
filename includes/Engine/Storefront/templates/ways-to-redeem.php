<?php
/**
 * "Ways to redeem" on the Rewards tab: the points discount, then the offers Pro adds.
 * Styled by assets/storefront/account/ways-to-redeem.css; each row by rule-list-item.php.
 *
 * @var array|null $yayloyalty_redeem LauncherPayload's `redeem`; null while the discount reward is not live.
 * @var array      $yayloyalty_offers LauncherPayload's `offers`.
 * @var string     $yayloyalty_label  The store's plural points label.
 */

use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

if ( null === $yayloyalty_redeem && [] === $yayloyalty_offers ) {
    return;
}

$yayloyalty_items = [];

if ( null !== $yayloyalty_redeem ) {
    $yayloyalty_items[] = [
        'icon'  => AccountRewardsIcon::get( 'coins' ),
        /* translators: %s: the store's points label, as "Points". */
        'name'  => sprintf( __( '%s discount', 'yayloyalty' ), $yayloyalty_label ),
        'url'   => '',
        'text'  => LauncherCopy::redeem_limits( $yayloyalty_redeem ),
        'value' => LauncherCopy::redeem_rate( $yayloyalty_redeem['points_per_unit'] ),
    ];
}

foreach ( $yayloyalty_offers as $yayloyalty_offer ) {
    $yayloyalty_items[] = [
        'icon'  => AccountRewardsIcon::get( 'percent' ),
        'name'  => $yayloyalty_offer['name'],
        'url'   => '',
        'text'  => $yayloyalty_offer['text'],
        'value' => SettingsHelper::points_text( $yayloyalty_offer['cost'] ),
    ];
}
?>
<section class="yayloyalty-account__section yayloyalty-account__redeem">
    <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Ways to redeem', 'yayloyalty' ); ?></h3>
    <ul class="yayloyalty-account__list">
        <?php
        foreach ( $yayloyalty_items as $yayloyalty_item ) {
            require __DIR__ . '/rule-list-item.php';
        }
        ?>
    </ul>
</section>
