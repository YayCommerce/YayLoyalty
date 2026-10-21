<?php
/**
 * My Account → Rewards: the points card, ways to earn and redeem, then the points history.
 * Styled by assets/storefront/account/account-rewards.css; each partial by the CSS file named after it.
 *
 * @var array $view AccountRewards::view().
 */

use YayLoyalty\Engine\Storefront\AccountRewards;

defined( 'ABSPATH' ) || exit;

// The names the partials read.
$yayloyalty_payload = $view['payload'];
$yayloyalty_card    = $view;
$yayloyalty_earn    = $view['earn'];
$yayloyalty_history = $view['history'];
?>
<div class="yayloyalty-account">
    <p class="yayloyalty-account__intro"><?php echo esc_html( AccountRewards::intro() ); ?></p>
    <?php require __DIR__ . '/points-card.php'; ?>
    <?php require __DIR__ . '/ways-to-earn.php'; ?>
    <?php require __DIR__ . '/ways-to-redeem.php'; ?>
    <?php require __DIR__ . '/points-history.php'; ?>
</div>
