<?php
/**
 * Signed-in panel body: the Rewards tab's own partials (points card with its expiry notice, ways to earn,
 * ways to redeem), then the recent activity launcher.js loads. No stylesheet of its own: each partial has its
 * file, and launcher.css fits them to the panel.
 *
 * @var array $view              Launcher::view(); `offers` are the extra Ways to redeem rows.
 * @var array $yayloyalty_text   The Design tab's storefront_text.
 * @var array $yayloyalty_member LauncherPayload's `member`.
 */

use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

// The names the shared partials read.
$yayloyalty_card   = [
    'payload'    => $view,
    'expires_at' => $yayloyalty_member['expires_at'],
];
$yayloyalty_earn   = LauncherEarnRules::with_daily_limit( LauncherEarnRules::member_rows( $view['earn'], $yayloyalty_text['earn_rate_line'], $view['purchase_min'] ), $view['left_today'] );
$yayloyalty_redeem = $view['redeem'];
$yayloyalty_offers = $view['offers'];
// 0 picks the plural label.
$yayloyalty_label = SettingsHelper::points_label( 0 );

require __DIR__ . '/points-card.php';
require __DIR__ . '/new-points-notice.php';
require __DIR__ . '/ways-to-earn.php';
require __DIR__ . '/ways-to-redeem.php';
require __DIR__ . '/recent-activity.php';
