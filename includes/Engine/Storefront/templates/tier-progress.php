<?php
/**
 * The launcher panel's tier card, under the points card: the same card body as My Points' "Your tier" section
 * (tier-summary.php), without the all-tiers table. Prints nothing while the member's `tier` is null
 * (LauncherPayload::MEMBER_TIER_FILTER has no answer).
 * Launcher only: points-card.php is shared with My Account and the placements, which don't show it.
 *
 * @var array $yayloyalty_member LauncherPayload's `member` ($view['member']); `tier` set by LauncherPayload::for_balance().
 */

defined( 'ABSPATH' ) || exit;

$yayloyalty_tier = $yayloyalty_member['tier'] ?? null;

if ( null === $yayloyalty_tier ) {
    return;
}
?>
<section class="yayloyalty-account__tiers-card">
    <?php require __DIR__ . '/tier-summary.php'; ?>
</section>
