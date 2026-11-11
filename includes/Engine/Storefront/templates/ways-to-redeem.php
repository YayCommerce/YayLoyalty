<?php
/**
 * "Ways to redeem": the My Points tab's list, also in the launcher panel and placed by [yayloyalty_ways_to_redeem] and its block.
 * The points discount, then the offers Pro adds.
 * No stylesheet of its own: the list and its rows are rule-list-item.php and rule-list-item.css.
 *
 * @var array $yayloyalty_payload LauncherPayload::for_user(), or Launcher::view() in the panel: its `redeem` (null
 *                                 while the discount reward is not live) and `offers`, some maybe `locked`.
 */

use YayLoyalty\Engine\Storefront\AccountMyPointsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_redeem = $yayloyalty_payload['redeem'];
$yayloyalty_offers = $yayloyalty_payload['offers'];

if ( LauncherPayload::redeem_is_empty( $yayloyalty_payload ) ) {
    return;
}

$yayloyalty_items = [];

if ( null !== $yayloyalty_redeem ) {
    $yayloyalty_items[] = [
        'icon'  => AccountMyPointsIcon::design( 'discount' ),
        'name'  => $yayloyalty_redeem['name'],
        'text'  => LauncherCopy::redeem_limits( $yayloyalty_redeem ),
        'value' => LauncherCopy::redeem_rate( $yayloyalty_redeem['points_per_unit'] ),
    ];
}

// A locked offer is one the viewer's conditions rule out: its teaser says what it takes, in place of the description.
foreach ( $yayloyalty_offers as $yayloyalty_offer ) {
    $yayloyalty_locked  = ! empty( $yayloyalty_offer['locked'] );
    $yayloyalty_items[] = [
        'icon'  => AccountMyPointsIcon::design( 'offer' ),
        'name'  => $yayloyalty_offer['name'],
        'text'  => $yayloyalty_locked ? $yayloyalty_offer['teaser'] : $yayloyalty_offer['text'],
        'value' => SettingsHelper::points_text( $yayloyalty_offer['cost'] ),
        'tone'  => $yayloyalty_locked ? 'locked' : '',
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
