# The launcher advertises earning to a shopper who has reached the daily cap

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-05 review of the storefront launcher (#41), which asked for this item once the Pro daily cap merged. The cap merged in #71.

## Today

Pro's `Pro\Services\DailyEarningLimit` caps automatic earning at `max_points_per_day` (`SettingsHelper::get_default_settings()`, null means no limit). It subscribes to `EarningService::AWARD_POINTS_FILTER` and lowers an award inside `LedgerService::earn()`, reading today's EARN total from `LedgerRepository::points_earned_today()`. It is not an earning gate, and `EarningService::preview()` doesn't apply it; its docblock says storefront figures stay the uncapped best case. `LauncherPayload::for_balance()` builds the ways to earn from `LauncherEarnRules::advertise()` and never reads the cap.

## Why it matters

A member who has earned the day's maximum still sees the full rates in the launcher, and the next order earns nothing. The shopper reads that as a broken promise.

## Suggested fix

Have `LauncherPayload::for_balance()` learn, through a filter that Pro's `DailyEarningLimit` answers, how many points the member can still earn today. At 0, show a "daily limit reached" line in place of the ways to earn. The filter keeps Lite free of Pro names.

## Done when

- [ ] With Pro, `max_points_per_day` set, and a member whose EARN today has reached it, the launcher panel shows no ways-to-earn rates for that member and says the daily limit is reached. Hand check: set the cap to 10, earn 10 with an order, reload the shop.
- [ ] The `DailyEarningLimit` docblock no longer says storefront figures stay uncapped.
