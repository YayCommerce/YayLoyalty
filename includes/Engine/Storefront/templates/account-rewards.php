<?php
/**
 * My Account → Rewards: the points card, ways to earn and redeem, then the points history.
 * Styled by assets/storefront/account/account-rewards.css; each partial by the CSS file named after it.
 *
 * @var array $view AccountRewards::view().
 */

use YayLoyalty\Engine\Storefront\AccountRewards;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Engine\Storefront\LauncherPayload;

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
    <?php // Both lists print nothing when empty, so one card stands in for the pair. ?>
    <?php if ( LauncherEarnRules::is_empty( $yayloyalty_earn ) && LauncherPayload::redeem_is_empty( $yayloyalty_payload ) ) : ?>
        <?php
        $yayloyalty_no_ways = 'both';
        require __DIR__ . '/no-ways-card.php';
        ?>
    <?php else : ?>
        <?php require __DIR__ . '/ways-to-earn.php'; ?>
        <?php require __DIR__ . '/ways-to-redeem.php'; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/points-history.php'; ?>
</div>
