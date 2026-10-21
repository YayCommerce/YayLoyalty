<?php
/**
 * My Account → Rewards: the points card, ways to earn and redeem, then the points history.
 * Styled by assets/storefront/account/account-rewards.css; each partial by the CSS file named after it.
 *
 * @var array $view AccountRewards::view().
 */

use YayLoyalty\Engine\Storefront\AccountRewards;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_payload = $view['payload'];
// Null while the discount reward is not live: worth, its redeem row and the checkout link stay hidden with it.
$yayloyalty_redeem  = $yayloyalty_payload['redeem'];
$yayloyalty_offers  = $yayloyalty_payload['offers'];
$yayloyalty_card    = $view;
$yayloyalty_earn    = $view['earn'];
$yayloyalty_history = $view['history'];
// 0 picks the plural label.
$yayloyalty_label = SettingsHelper::points_label( 0 );
?>
<div class="yayloyalty-account">
    <p class="yayloyalty-account__intro"><?php echo esc_html( AccountRewards::intro() ); ?></p>
    <?php require __DIR__ . '/points-card.php'; ?>
    <?php require __DIR__ . '/ways-to-earn.php'; ?>
    <?php require __DIR__ . '/ways-to-redeem.php'; ?>
    <?php require __DIR__ . '/points-history.php'; ?>
</div>
