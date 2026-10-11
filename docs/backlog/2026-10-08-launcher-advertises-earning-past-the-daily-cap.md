# The launcher advertises earning to a shopper who has reached the daily cap

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-05 review of the storefront launcher (#41), which asked for this item once the Pro daily cap merged. The cap merged in #71.

## Today

Fixed on `trace/fix/launcher-earning-gates`, waiting on the hand check below. Pro's `DailyEarningLimit::left_today()` answers Lite's `EarningService::POINTS_LEFT_TODAY_FILTER`, and `EarningService::points_left_today()` reads it. `LauncherPayload::for_balance()` carries it as `left_today`, and `LauncherCopy::daily_limit_line()` prints "You can earn N more today." below the cap, or the reached line in place of the rates at 0, in the launcher (`panel-member.php`) and on My Account (`account-rewards.php`). `ProductEarnMessage::member_points()` caps the product figure at the same number. A `wp eval` render with a rolled-back earn row showed each state; no real order has run through it yet.

## Why it matters

A member who has earned the day's maximum still sees the full rates in the launcher, and the next order earns nothing. The shopper reads that as a broken promise.

## Suggested fix

Have `LauncherPayload::for_balance()` learn, through a filter that Pro's `DailyEarningLimit` answers, how many points the member can still earn today. At 0, show a "daily limit reached" line in place of the ways to earn. The filter keeps Lite free of Pro names.

## Done when

- [ ] With Pro, `max_points_per_day` set, and a member whose EARN today has reached it, the launcher panel shows no ways-to-earn rates for that member and says the daily limit is reached. Hand check: set the cap to 10, earn 10 with an order, reload the shop.
- [x] The `DailyEarningLimit` docblock no longer says storefront figures stay uncapped. — `includes/Pro/Services/DailyEarningLimit.php` class docblock: "the storefront reads left_today() instead."
