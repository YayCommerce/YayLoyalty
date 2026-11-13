---
id: BL-1
title: Launcher Ways to redeem lists conditional rewards to every shopper
kind: deferred
status: open
from: P-1
affects: [R-1]
default:
decision:
created: 2026-11-13
---
# BL-1 Launcher Ways to redeem lists conditional rewards to every shopper

**What's left:** once P-1 enforces reward conditions, the redeem card on the cart and checkout hides a reward the shopper doesn't qualify for. The launcher's "Ways to redeem" list still shows every live fixed or percent coupon reward, whatever its conditions. `RewardsPanel::redeem_offers()` (`includes/Pro/Storefront/RewardsPanel.php:30-45`) gets only `$now`, with no customer, so it can't check them.

**Where it would go:** pass the signed-in user to `LauncherPayload::REDEEM_OFFERS_FILTER` (`includes/Engine/Storefront/LauncherPayload.php`) and skip rewards whose conditions fail for them, reusing P-1's `RewardConditionsEvaluator`. For a guest, either skip every conditional reward or label it ("for selected customers").

**Why it waited:** it's outside R-1's acceptance (which customers a reward *applies* to is enforced at the cart and checkout). It also needs a decision about what a guest sees, and the launcher payload may be cached per page rather than per user.
