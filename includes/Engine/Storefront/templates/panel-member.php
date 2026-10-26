<?php
/**
 * Signed-in panel body: the Rewards tab's own partials (points card with its expiry notice, ways to earn,
 * ways to redeem), then the recent activity launcher.js loads. No stylesheet of its own: each partial has its
 * file, and launcher.css fits them to the panel.
 *
 * @var array $view              Launcher::view(); `offers` are the extra Ways to redeem rows.
 * @var array $yayloyalty_member LauncherPayload's `member`.
 */

use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Engine\Storefront\LauncherPayload;

defined( 'ABSPATH' ) || exit;

// The names the shared partials read.
$yayloyalty_payload = $view;
$yayloyalty_card    = [ 'payload' => $view ];
$yayloyalty_earn    = LauncherEarnRules::with_daily_limit( LauncherEarnRules::member_rows( $view['earn'], $view['purchase_min'] ), $view['left_today'] );

require __DIR__ . '/points-card.php';
require __DIR__ . '/new-points-notice.php';

// Both lists print nothing when empty, so one card stands in for the pair.
if ( LauncherEarnRules::is_empty( $yayloyalty_earn ) && LauncherPayload::redeem_is_empty( $yayloyalty_payload ) ) {
    $yayloyalty_no_ways = 'both';
    require __DIR__ . '/no-ways-card.php';
} else {
    require __DIR__ . '/ways-to-earn.php';
    require __DIR__ . '/ways-to-redeem.php';
}
require __DIR__ . '/recent-activity.php';
