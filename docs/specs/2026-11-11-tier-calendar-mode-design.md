# Tier Calendar Mode Design

**Status:** accepted 2026-11-11
**Related:** [Tiers master design](2026-11-02-tiers-master-design.md), [Rolling mode design](2026-11-05-tier-rolling-mode-design.md), [Tier storefront design](2026-11-05-tier-storefront-design.md), [the evaluator decision record](../decisions/2026-11-04-tier-evaluator-sole-writer.md)

This spec adds a third qualification mode beside Lifetime and Rolling. It builds on the Rolling design. Every Rolling rule holds unless this doc says otherwise, and Lifetime and Rolling behaviour don't change. **Refinements of the master** lists where this doc departs from the master design.

## Goal

A Pro merchant can set tier qualification to **Calendar**. Customers then qualify on the points they earn in fixed calendar periods (month, quarter or year, picked by the merchant), the same for every customer whatever their join date. A tier reached during a period is kept for the rest of that period and all of the next one. At each review date, customers move to the tier that the period just ended qualifies them for. Staff and customers see the period and the hold end in the drawer, the launcher and My Account.

Worked example (yearly, thresholds Bronze 0 / Silver 500 / Gold 1,000):
- On 10 Mar 2025 the customer has 600 pts in 2025, so they reach Silver.
- On 20 Oct 2025 they have 1,100 pts in 2025, so they reach Gold, kept until 31 Dec 2026.
- In 2026 they earn only 100 pts but stay Gold all year.
- On 1 Jan 2027, the review date, their 2026 total is 100 pts, so they drop to Bronze.

## Context

- **Rolling mode (done, on `jason/feat/tiers`):**
  - `TierMode` (`includes/Enums/TierMode.php`, Lite) has `LIFETIME` and `ROLLING`, plus `ROLLING_MONTHS`.
  - `SettingsHelper` has `tiers_mode` and `tiers_rolling_months`, both in `TIER_KEYS`, validated as `tier_mode` and `tier_rolling_months`.
  - `customers.tier_held_from` and `tier_held_until` hold the hold. Only `TierEvaluator` writes them.
  - `LedgerRepository::window_total( $user_id, $from, $to )` sums the points with clawbacks attributed to the earn they reverse. `staff_adjust_since()` sums staff ADJUST rows created after a date.
  - `TierDecision::rolling_move()` (pure) returns the move and `HOLD_START`/`HOLD_KEEP`/`HOLD_CLEAR`. `KIND_HOLD_ONLY` serves the `to_rolling` sweep.
  - `TierEvaluator::apply()` branches on the mode. `rolling_decision()` computes the qualifying total over `[held_from − L, held_from]`, with L taken from the stored hold.
  - `TierSweep` has the modes `placement`, `reevaluate`, `to_rolling` and `to_lifetime`. `is_mode_sweep()` drives the merge. `is_pending()` refuses a mode change with 409.
  - `TierRunner::run()` ends due floors. In Rolling it then runs `end_hold()` on up to 100 `hold_ended_user_ids()`, then a batch of the sweep.
  - `TierStanding::of()` returns `mode`, `window_months`, `held_from`, `held_until`, and the progress (the live window total in Rolling).
  - Its consumers are `CustomerTierRestController::respond()`, `Pro\Storefront\TierProgress` and `AccountTier`, then `LauncherPayload` and `AccountMyPoints` (Lite) and the templates `tier-progress.php` and `account-tier.php`. The templates use `LauncherCopy::tier_window_caption()`.
  - SPA:
    - `pro/tiers/TierModeCard.tsx` is the Qualification card.
    - `pro/customers/CustomerTierCard.tsx` is the drawer card.
    - `lib/schema/tier.schema.ts` holds the zod schemas.
- **The master design** listed "Calendar-year reset" as rejected ("a cliff edge on one date") and "a calendar-year mode" as Out. This design reverses that decision at the user's request (see **Refinements of the master**).
- **Manual tier assignment** was removed from the admin UI in `1effb638`. The floor and its REST route remain, so floors still exist on stored data and through `POST /customers/{id}/tier`.
- **Dates** are stored and sent as UTC. The site time zone is used only to group by site-calendar dates (`docs/conventions.md`). `Dates::site_day_start()`/`site_month_start()` use `wp_timezone()`.

## Scope

**In:**
- `tiers_mode` value `calendar` and the setting `tiers_calendar_period` (`month` | `quarter` | `year`, default `year`).
- The Calendar rules in **Evaluation rules**: window tier, hold dates, qualifying total, triggers, the hold end at period boundaries.
- A new sweep mode `to_calendar`, used for Lifetime/Rolling → Calendar and for a period change while in Calendar.
- `TierRunner`'s hold-end step in Calendar.
- `GET`/`PUT /tiers/settings` with the new value and key. `GET /customers/{id}/tier` gains `period`.
- The Qualification card's third option and Period select, and the drawer card's Calendar caption.
- The storefront captions in the launcher tier line and My Account → My Points, through a new `period` key.

**Out:**
- A spend metric. Calendar counts points earned, like the other modes.
- A start month the merchant can pick (fiscal years, shifted quarters).
- Tier emails, including "tier ending soon". `yayloyalty_tier_changed` is unchanged.
- A holds column, filter or sort in the Customers list, and a hold field in segments.
- A new `TierChangeReason`. Calendar reuses `hold_end`, `mode_change` and the existing reasons.
- Any change to Lifetime or Rolling behaviour, to `LedgerService`, or to a ledger or customer column.
- Re-adding manual tier assignment to the UI.
- A plugin version bump: it stays `1.0.0` on this branch.

## Evaluation rules

The rest of this section applies only while `tiers_mode` is `calendar`. P is `tiers_calendar_period`, read at evaluation time, and m(P) = 1, 3 or 12 months.

**Periods.**
- Periods are aligned to the calendar in the site time zone (`wp_timezone()`), and every boundary is at 00:00:00 local time:
  - a month starts on day 1;
  - quarters start on 1 Jan, 1 Apr, 1 Jul and 1 Oct;
  - a year starts on 1 Jan.
- Boundaries are computed in the site time zone and stored in UTC.
- For a UTC moment t:
  - **start(t)** = the UTC instant of the start of the period holding t;
  - **next(t)** = start(t) + m(P) months, in site-time-zone wall-clock time;
  - a period covers [start, next − 1 s].
- A row whose attributed date is exactly a boundary belongs to the period that starts there.

**Definitions**, computed under the customer-row lock. "Points in [a, b]" is Rolling's `window_total( user, a, b )`, where a clawback takes the date of the earn it reverses.
- **Current total** = points in [start(now), now]. **Current tier** = the live tier with the highest threshold ≤ current total, or none.
- **Previous total** = points in [start(now) − m(P) months, start(now) − 1 s] + `staff_adjust_since( user, start(now) − 1 s )`, that is, the previous period plus the staff adjustments made so far in the current period (the same rule as the qualifying total). **Previous tier** = likewise. Without the second term, a staff deduction dated now would never lower a customer holding a tier from the previous period. A staff gift made now also counts toward the previous period's tier (decided with the user on 2026-11-11).
- **Window tier** = the better-ranked of current tier and previous tier. On a tie the current tier wins, because it gives the longer hold. The source of the window tier is `current` or `previous`.
- **Hold dates** when a hold starts. They depend on the window tier's source:
  - `current`: `tier_held_from` = next(now), `tier_held_until` = next(now) + m(P) months − 1 s. The tier is kept for the rest of this period and all of the next.
  - `previous`: `tier_held_from` = start(now), `tier_held_until` = next(now) − 1 s. The tier is kept to the end of this period.
- **Hold** is active while `tier_id` isn't NULL and `tier_held_until` > now, as in Rolling.
- **Hold length L** = the months between `tier_held_from` and `tier_held_until` + 1 s, counted in the site time zone and rounded to the nearest whole month. Rounding keeps L at 1, 3 or 12 across DST and a site time-zone change. L comes from the stored hold, so changing the setting never moves a stored hold's qualifying window.
- **Qualifying total** of an active hold = points in [`tier_held_from` − L months (site time zone), `tier_held_from` − 1 s] + `staff_adjust_since( user, tier_held_from )`. This is the points of the period that earned the tier, plus staff adjustments made during the kept period. **Qualifying tier** = the live tier with the highest threshold ≤ that total, or none.
- **Floor**, **floor-held**, current rank, and starting and clearing a hold are Rolling's, using the Calendar window tier above.

**Triggers.** These are Rolling's table with the Calendar window tier, and they are decided by the unchanged `TierDecision::rolling_move()`. "Start a hold" uses **Hold dates** above.

| Trigger (Calendar) | Move | Hold afterwards | `reason` |
|---|---|---|---|
| Ledger row, `affects_lifetime_earned`, points > 0, source ≠ `tier_bonus` | up only, to max(window tier, floor) | an upgrade to the window tier starts a hold, an upgrade to the floor clears it, no change keeps it | `earn`, `refund`, `adjustment` (as today) |
| Ledger row, points < 0, **no active hold** | to max(window tier, floor), up or down | the window tier and not floor-held → start; the floor → clear; none → clear | `refund` or `adjustment` |
| Ledger row, points < 0, **active hold** | no change if the window tier or the qualifying tier ranks ≥ current. Otherwise to max(qualifying tier, window tier, floor) | as Rolling: the window tier wins → start; the qualifying tier wins → keep; the floor → clear; none → clear | `refund` or `adjustment` |
| `tier_bonus` row, or a type that doesn't affect `lifetime_earned` | none | — | — |
| **Hold end:** daily step, `tier_held_until` + 1 s ≤ now | to max(window tier, floor) exactly | the window tier and not floor-held → start (also on the same tier: a silent renewal); the floor → clear; none → clear | `hold_end` |
| Floor ends (daily step) or `DELETE /customers/{id}/tier` | clear the floor; then to the window tier | a tier → start; none → clear | `manual_end` |
| `POST /customers/{id}/tier` (REST only) | as Rolling | as Rolling | `manual` |
| `placement` sweep | to max(window tier, floor) exactly, no bonus | the window tier and not floor-held → start; otherwise clear | `placement` |
| `reevaluate` sweep | as Rolling, with the Calendar window tier | as Rolling | `tier_deleted` / `threshold_change` |
| `to_calendar` sweep | none, after the deleted-tier handling that `reevaluate` does | a customer with a tier who isn't floor-held → `tier_held_from` = start(now), `tier_held_until` = next(now) − 1 s; otherwise clear | `tier_deleted` only when that handling moves them |

**On every evaluation:**
- `tier_evaluated_at` = now.
- `tier_progress_value` = the current total. This includes the entry bonus paid in this evaluation, as in the other modes.
- A tier change runs the existing steps: one `TIER_CHANGED` row, the entry bonus on an upgrade except in `placement` (once per tier, ever), and `yayloyalty_tier_changed` after commit.
- A renewal writes only the hold columns, `tier_evaluated_at` and `tier_progress_value`. It writes no activity row and fires no action.

**Mode and period changes** (`PUT /tiers/settings`):

| Change | Tiers on | Tiers off |
|---|---|---|
| `tiers_mode` lifetime → calendar, or rolling → calendar | stored; `TierSweep::request( MODE_TO_CALENDAR )` | stored only |
| `tiers_calendar_period` changed while `tiers_mode` is (or becomes) `calendar` | stored; `request( MODE_TO_CALENDAR )` (one request covers both changes) | stored only |
| `tiers_calendar_period` changed while the mode stays `lifetime` or `rolling` | stored only | stored only |
| `tiers_mode` calendar → rolling | stored; `request( MODE_TO_ROLLING )` (unchanged: a hold from now for N months) | stored only |
| `tiers_mode` calendar → lifetime | stored; `request( MODE_TO_LIFETIME )` (unchanged: holds cleared, up only, `mode_change`) | stored only |
| `tiers_rolling_months` only | stored only | stored only |
| `tiers_enabled` false → true | `request( MODE_PLACEMENT )`, run in the stored mode and period | — |

- A pending sweep refuses a change to `tiers_mode` or to `tiers_calendar_period` with 409 `yayloyalty_tier_sweep_running` "Wait for the current tier update to finish.". A change to `tiers_rolling_months` alone is still accepted.
- In one request, the mode and period apply first, then `tiers_enabled`. Turning tiers on in that request runs placement and requests no mode sweep (as Rolling).
- **Sweep merge:** `placement` beats the mode sweeps (`to_rolling`, `to_lifetime`, `to_calendar`), which beat `reevaluate`. A mode sweep also does `reevaluate`'s work. A mode sweep runs its own step whatever `tiers_mode` holds when a batch runs.

**Daily job:**
- `TierRunner` runs the hold-end step while `tiers_mode` is `rolling` or `calendar`, with 100 per batch and async continuation as today.
- `end_hold()` acts in both modes. In Calendar it leaves the customer untouched while `tier_held_until` + 1 s > now, so a run inside the last second of a period doesn't review on the wrong period.

Accepted consequences:
- A refund of an order from the qualifying period can drop a held customer. A refund of an order from the kept period can't, because its clawback is attributed outside the qualifying window.
- Most Calendar holds end on the same boundary. The hold-end step works through them over several batches that day.
- A period change gives every member a hold to the end of the current period under the new setting, whatever their old hold said. A Yearly → Monthly change on 11 Nov reviews everyone on 1 Dec, on November's points.

## Acceptance criteria

Setup unless stated:
- the wp-env Pro site with the site time zone set to `Asia/Ho_Chi_Minh` (UTC+7, no DST);
- tiers Bronze 0 / Silver 500 / Gold 1,000, every bonus 0, multipliers 1.00;
- tiers on, Calendar mode with period `year`, every sweep finished;
- the default purchase campaign at 1 pt per currency unit, rounding `down`.

T is the moment of the action. Today is in 2026.
- **Local→UTC:** 2026-01-01 00:00:00 local = 2025-12-31 17:00:00 UTC, and 2026-12-31 23:59:59 local = 2026-12-31 16:59:59 UTC.
- **Backdate a row:** `UPDATE wp_yayloyalty_ledger SET created_at = … WHERE id = …` through `wp db query`, then `wp eval` calling `TierEvaluator::build()->sweep_one( $id, 'reevaluate' )` when an evaluation is needed.
- **Run the daily job:** `wp action-scheduler run --hooks=yayloyalty_tier_run`.

1. **Settings.**
   - `GET /tiers/settings` returns `tiers_calendar_period` `"year"` on a site that never stored it.
   - Each of these returns 400 and changes nothing: `PUT {"tiers_mode":"weekly"}`, `{"tiers_calendar_period":"week"}`, `{"tiers_calendar_period":3}`.
   - With a sweep pending, `{"tiers_calendar_period":"quarter"}` and `{"tiers_mode":"calendar"}` each return 409 `yayloyalty_tier_sweep_running`.
2. **Lifetime → Calendar keeps members.** In Lifetime mode with period `year`, customer A is Silver, and customer B is Gold with a Gold floor (No end, set through REST).
   - `PUT {"tiers_mode":"calendar"}` returns 200 with `sweep.mode` `to_calendar`.
   - After the sweep:
     - A is Silver, with `tier_held_from` 2025-12-31 17:00:00 and `tier_held_until` 2026-12-31 16:59:59 (UTC);
     - B is Gold with both hold columns NULL;
     - neither has a new `tier_changed` row.
3. **Rolling → Calendar replaces holds.** Customer C holds Silver in Rolling until T + 5 months.
   - `PUT {"tiers_mode":"calendar"}` runs `to_calendar`.
   - C ends Silver with the hold of AC 2's dates.
4. **Upgrade on the current period.** Customer D, Bronze, has 480 pts dated in 2026 and none in 2025. A 30-unit order completes.
   - D is Silver, reason `earn`;
   - `tier_progress_value` is 510;
   - `tier_held_from` is 2026-12-31 17:00:00 and `tier_held_until` is 2027-12-31 16:59:59.
5. **The worked example's review.** Customer E is Gold, with these earn rows:
   - 600 backdated to 2024-03-10 and 500 to 2024-10-20 (local);
   - 100 backdated to 2025-06-01;
   - no rows in 2026.

   E's hold is `tier_held_from` 2024-12-31 17:00:00, `tier_held_until` 2025-12-31 16:59:59.
   - Running the daily job moves E to Bronze:
     - one `tier_changed` row `{from_name: "Gold", to_name: "Bronze", reason: "hold_end"}`;
     - the previous total (2025) is 100 and the current total (2026) is 0, so both give Bronze and the tie goes to current;
     - the new hold is `tier_held_from` 2026-12-31 17:00:00, `tier_held_until` 2027-12-31 16:59:59;
     - `yayloyalty_tier_changed` fires once with `hold_end`.
   - A repeat run while E's hold is in the future changes nothing.
6. **Renewal.** A Gold customer whose hold ended at 2025-12-31 16:59:59, with 1,200 pts dated in 2025, stays Gold after the daily job.
   - The hold becomes 2025-12-31 17:00:00 → 2026-12-31 16:59:59.
   - No `tier_changed` row is written, and no action fires.
7. **Monthly and quarterly holds.**
   - With period `month`, a customer reaching Silver on T (11 Nov 2026) gets `tier_held_from` = 2026-11-30 17:00:00 and `tier_held_until` = 2026-12-31 16:59:59.
   - With period `quarter`, they get 2026-12-31 17:00:00 and 2027-03-31 16:59:59.
8. **Period change resets holds.** Customer F holds Silver until 2027-12-31 16:59:59 (period `year`).
   - `PUT {"tiers_calendar_period":"quarter"}` returns 200 with `sweep.mode` `to_calendar`.
   - After the sweep:
     - F is Silver with `tier_held_from` 2026-09-30 17:00:00 and `tier_held_until` 2026-12-31 16:59:59;
     - no `tier_changed` row is written.
   - With tiers off, the same PUT returns `sweep` null and leaves F's hold unchanged.
9. **Refund in the qualifying period.** Customer G, tierless with 0 pts, completes an order earning 1,200 and is Gold.
   - Refunding 300 moves G to Silver (reason `refund`). The qualifying total and the current total are both 900.
   - The current tier wins, so the hold restarts with AC 4's dates.
10. **Refund outside the qualifying period.** Customer H holds Gold from 2025, with 1,100 pts dated in 2025 and a hold to 2026-12-31 16:59:59.
    - H completes a 50-unit order in 2026, then it is fully refunded. H stays Gold with the hold unchanged.
    - Refunding 700 of a 2025 order (its clawback is dated now, but attributed to 2025) moves H to Bronze, reason `refund`:
      - the qualifying total is 400, the previous total is 400 and the current total is 0, so every candidate is Bronze;
      - the window tier wins the tie over the qualifying tier, and current wins over previous, so the hold becomes 2026-12-31 17:00:00 → 2027-12-31 16:59:59.
11. **Staff deduction during a hold.** Customer I holds Gold with a qualifying total of 1,100 and a current total of 0.
    - An ADJUST of −50 leaves I Gold (1,050).
    - A further −100 moves I to Silver (reason `adjustment`):
      - the qualifying total and the previous total are both 950, and the current total is −150;
      - the window tier (previous) ties the qualifying tier and wins, so a previous-source hold starts. Its dates equal the ones I already had.
12. **Placement in Calendar.** With tiers off, mode `calendar`, period `year`, customer J has 700 pts dated in 2025 and 200 in 2026.
    - Turning tiers on makes J Silver, reason `placement`, with no bonus row.
    - The hold is 2025-12-31 17:00:00 → 2026-12-31 16:59:59 (previous source).
13. **Floor ends.** Customer K has a Gold floor set through REST for 30 days and 600 pts in 2026.
    - After `tier_manual_until` is set in the past and the daily job runs, K is Silver, reason `manual_end`.
    - The hold is 2026-12-31 17:00:00 → 2027-12-31 16:59:59.
14. **Leaving Calendar.**
    - `PUT {"tiers_mode":"lifetime"}` runs `to_lifetime`: holds are cleared and customers move up only (reason `mode_change`), as Rolling's AC 13.
    - `PUT {"tiers_mode":"rolling"}` runs `to_rolling`: customers who aren't floor-held get a hold from T to T + `tiers_rolling_months`.
15. **Site time zone with DST** (unit-checked, see Constraints § Tests).
    - In `America/New_York` with period `quarter`, a hold whose kept period is Q1 2027 has these dates:
      - `tier_held_from` 2027-01-01 05:00:00 UTC;
      - `tier_held_until` 2027-04-01 03:59:59 UTC.
    - L for that hold is 3.
16. **Drawer card.** Customer D from AC 4 is shown:
    - The TIER sub-line reads "Since {T's date} · held until 31 Dec 2027".
    - NEXT TIER reads "490 pts to Gold" with the caption "510 / 1,000 this year".
    - With period `quarter` the caption ends "this quarter", and with `month` it ends "this month".
    - Lifetime and Rolling output is unchanged.
17. **Qualification card.**
    - The card shows Lifetime, Rolling and Calendar in three columns from `sm`.
    - Choosing Calendar shows the "Period" select (Monthly, Quarterly, Yearly), with Yearly preselected on a fresh site.
    - With tiers on:
      - Lifetime/Rolling → Calendar (Yearly) asks "Switch to calendar qualification?", with "Every current member keeps their tier until the end of this year. Then each is reviewed on the points they earned in it." and the button "Switch".
      - Changing only the period to Quarterly asks "Change the calendar period?" with the quarter sentence and the button "Change".
      - Calendar → Rolling and Calendar → Lifetime show today's confirms.
    - Saving toasts "Qualification saved.", and a sweep shows the existing banner.
    - With tiers off, saving needs no confirm.
18. **Storefront.** Customer D from AC 4, logged in:
    - **Launcher tier line:** "Counts Points earned this year." followed by "Silver kept until 31 Dec 2027." ("Points" is the store's points label).
    - **My Account → My Points:**
      - the caption "Counts Points earned this year.";
      - a "kept until" row reading 31 Dec 2027.
    - **Rolling:** still shows "Counts Points earned in the last 6 months.".
19. **Unchanged modes.** In Lifetime, the Lifetime slice's ACs 3, 6, 7 and 10 pass. In Rolling, Rolling ACs 2–16 pass as written.
20. **Lite.** On the Lite build over the same database:
    - `tiers_mode` `calendar` and `tiers_calendar_period` stay in `yayloyalty_settings`, and the customer tier columns are unchanged;
    - `./scripts/run.sh release lite --no-prompt` passes validation.
21. **End to end** on the wp-env Pro site, with the setup above but Lifetime mode. The test customer is Silver at 600 lifetime pts, all backdated to 2025.
    1. On Tiers, choose Calendar, Yearly, and confirm.
       - The banner shows, then clears.
       - The drawer reads "Silver", "held until 31 Dec 2026", "1,000 pts to Gold" and "0 / 1,000 this year".
    2. Set `tier_held_until` to 2025-12-31 16:59:59 and run the daily job.
       - The previous total (2025) is 600, so the customer stays Silver, a silent renewal.
       - The hold is to 2026-12-31 16:59:59.
    3. Backdate the 600 pts to 2024, reset `tier_held_until` to the past, and run the daily job.
       - The customer is Bronze, reason `hold_end`.
       - The activity tab shows "Silver → Bronze", "Tier hold ended".
    4. The customer completes a 1,000-unit order. They are Gold, held until 31 Dec 2027.
    5. Change the period to Quarterly and confirm.
       - After the sweep the customer is Gold, held until 31 Dec 2026.
       - The drawer caption ends "this quarter".
    6. Switch back to Lifetime. The customer is Gold (lifetime 1,600) with no hold line.
    7. The Chrome console shows no error or warning from `yayloyalty` on `#/tiers`, `#/customers`, the drawer, the storefront launcher and My Account → My Points.

## Constraints

- **PHP 7.4:** none of the following are allowed:
  - enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments;
  - union, `mixed`, `static` or `never` types;
  - `catch` without a variable, `throw` as an expression;
  - a trailing comma after the last parameter, first-class callables, `$object::class`.

  (Hard rule 4, `.claude/rules/php-standards.md`.)
- **Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call.** The Lite-side changes name no Pro code: `TierMode`, `SettingsHelper`, `LauncherPayload`, `AccountMyPoints`, `LauncherCopy`, and the two templates.
- **SPA tier code** stays in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`.
- **Single writers:**
  - `TierEvaluator` is the only writer of every tier and hold column (D20).
  - `LedgerService` is unchanged, and no ledger row, ledger column or customer column is added.
- **The evaluator** runs after the ledger commit, in its own transaction under the customer-row lock, as today.
- **Dates:**
  - Store UTC `Y-m-d H:i:s`.
  - Period boundaries are computed with `DateTimeImmutable` in `wp_timezone()` (wall-clock month arithmetic from day 1, so there's no month overflow) and converted to UTC.
  - Display uses the existing site-date formatting (`Dates::to_site_date()` in PHP, `formatStoredDate()` in the SPA).
- **Settings keys** go in the `SettingsHelper` defaults, the schema map and `TIER_KEYS`, and in zod. `POST /settings` keeps dropping `TIER_KEYS`.
- **REST:**
  - callbacks through `exec_read`/`exec_write`;
  - `permission_callback` `$this->requires( Capabilities::MANAGE_TIERS )` for settings, and the customer tier route as today;
  - responses in snake_case, parsed with zod in `*.api.ts`.
- **Templates:** `tier-progress.php` and `account-tier.php` are a public contract. The `period` key is additive, and no existing key changes meaning.
- **Strings:**
  - the `yayloyalty` text domain, with a `translators:` comment on every placeholder;
  - a full sentence per period, never a period noun spliced into a sentence.
- **Tests:** per CLAUDE.md § Tests. Two pieces qualify as points math that decides tiers:
  - `TierCalendar`'s boundaries, hold dates and L rounding, including AC 15's DST case;
  - the window-tier pick between current and previous.

  yayplan proposes them (`tests/unit/Pro/TierCalendarTest.php`) with AskUserQuestion before writing them. Everything else ends in an exact manual check.
- **Branch:** all work on `jason/feat/tiers`. Commit only when the user asks.

## Approach

**Chosen:** Calendar as a third mode that reuses Rolling's hold machinery (approach A). Only the window bounds and the hold dates are new.

**Architecture:**
- `TierEvaluator::apply()` gains a `calendar` branch next to Rolling's. It asks a new pure helper, `TierCalendar`, for the period bounds.
- It reads the current and previous totals through `window_total()` and lets `TierCalendar` pick the window tier and its source. During an active hold it computes the qualifying total over the window `TierCalendar` derives from the stored hold.
- It then calls the unchanged `TierDecision::rolling_move()`. A `HOLD_START` is written with `TierCalendar`'s dates for the winning source.
- `TierSweep` gains `to_calendar`, `TierRunner` runs the hold-end step in Calendar too, and `TiersRestController` requests the sweeps.

**Components:**
- `includes/Enums/TierMode.php` [edit, Lite]:
  - `CALENDAR = 'calendar'`, added to `all()`;
  - `CALENDAR_MONTH = 'month'`, `CALENDAR_QUARTER = 'quarter'`, `CALENDAR_YEAR = 'year'`, `CALENDAR_PERIODS`, `DEFAULT_CALENDAR_PERIOD = 'year'`;
  - `is_calendar_period( $value ): bool`, `calendar_period_months( string $period ): int` (1, 3, 12);
  - the class docblock names the three modes.
- `includes/Helpers/SettingsHelper.php` [edit, Lite]:
  - default `tiers_calendar_period` → `TierMode::DEFAULT_CALENDAR_PERIOD`;
  - schema type `tier_calendar_period`, validated by `TierMode::is_calendar_period()` and sanitized to the string;
  - added to `TIER_KEYS`.
- `includes/Pro/Services/TierCalendar.php` [new]: pure, with static methods only, and every date-returning method takes a `\DateTimeZone`:
  - `bounds( string $now_utc, string $period, \DateTimeZone $tz ): array{current_start, next_start, previous_start}`, all UTC;
  - `pick( ?Tier $current, ?Tier $previous ): array{0: ?Tier, 1: string}`: the window tier and its source `current`|`previous`, with ties going to current;
  - `hold_dates( string $source, array $bounds, string $period, \DateTimeZone $tz ): array{from, until}`;
  - `qualifying_window( string $held_from, string $held_until, string $period, \DateTimeZone $tz ): array{from, to}`, where L is rounded to the nearest whole month, and a NULL or out-of-order start reads as `held_until` + 1 s − m(P).
- `includes/Pro/Services/TierEvaluator.php` [edit]:
  - `mode()` returns `calendar` when stored;
  - `calendar_period(): string`;
  - the `calendar` branch in `apply()` and a private `calendar_decision()`;
  - `sweep_one()` handles `MODE_TO_CALENDAR` (`KIND_HOLD_ONLY` with Calendar hold dates, `plan['mode']` = `calendar`);
  - `end_hold()` acts in Rolling and Calendar, with Calendar's `+ 1 s` guard;
  - the class docblock is updated.
- `includes/Pro/Services/TierDecision.php`: unchanged.
- `includes/Pro/Services/TierSweep.php` [edit]: `MODE_TO_CALENDAR = 'to_calendar'` with a docblock, in `is_mode_sweep()` and in `current()`'s mode list.
- `includes/Pro/Services/TierRunner.php` [edit]: the hold-end step runs while the mode is `rolling` or `calendar`. The docblock is updated.
- `includes/Pro/Controllers/TiersRestController.php` [edit]:
  - `tiers_calendar_period` arg with `enum` `TierMode::CALENDAR_PERIODS`;
  - `get_settings` returns it;
  - `update_settings()` follows **Mode and period changes**, with the 409 covering the period;
  - the docblocks are updated.
- `includes/Pro/Services/TierStanding.php` [edit]:
  - in Calendar, `progress` = the current total read now;
  - `period` = the period (null otherwise);
  - `window_months` null.
- `includes/Pro/Controllers/CustomerTierRestController.php` [edit]: `respond()` adds `period`.
- `includes/Pro/Storefront/TierProgress.php`, `AccountTier.php` [edit]: pass `period`. `TierProgress::line()` gains a `?string $period` parameter after `$window_months`.
- `includes/Engine/Storefront/LauncherPayload.php`, `AccountMyPoints.php` [edit, Lite]:
  - `period` in the key list, the validation (null or `TierMode::is_calendar_period()`) and the filter docblocks;
  - "period: ?string, the calendar period whose points count; null outside Calendar".
- `includes/Engine/Storefront/LauncherCopy.php` [edit, Lite]: `tier_period_caption( string $period ): string`.
- `includes/Engine/Storefront/templates/tier-progress.php`, `account-tier.php` [edit, Lite]: show `tier_period_caption()` when `period` isn't null, alongside the existing `window_months` branch.
- SPA: see **UI**.

**Data and storage:**
- **Settings:**
  - `tiers_mode` gains the value `calendar`;
  - new key `tiers_calendar_period` in `yayloyalty_settings`, default `year`.
- **Sweep option** `yayloyalty_tier_sweep`: `mode` gains `to_calendar`.
- **Hold columns:** existing columns with new date values (period boundaries). No schema change, no migration, no new index.
- **`TIER_CHANGED` payload:** unchanged.
- **Uninstall:** nothing new.

**Integration points:**
- **REST** (Pro, `yayloyalty/v1`):

  | Route | Capability | Change |
  |---|---|---|
  | `GET /tiers/settings` | `yayloyalty_manage_tiers` | adds `tiers_calendar_period: "month"\|"quarter"\|"year"`; `sweep.mode` may be `to_calendar` |
  | `PUT /tiers/settings` | `yayloyalty_manage_tiers` | accepts `tiers_mode: "lifetime"\|"rolling"\|"calendar"` and `tiers_calendar_period`. 400 `error_invalid_arguments` for a bad value. 409 `yayloyalty_tier_sweep_running` for a mode or period change while a sweep is pending |
  | `GET/POST/DELETE /customers/{id}/tier` | as today | adds `period: "month"\|"quarter"\|"year"\|null` (null outside Calendar). In Calendar, `progress_value` = the current total computed on read, `window_months` null, and `held_from`/`held_until` as stored while the hold is active |

- **Hooks:**
  - none new;
  - `yayloyalty_tier_changed` fires for Calendar moves with the existing reasons;
  - the filters behind `LauncherPayload` and `AccountMyPoints` gain the `period` key (additive).
- **Shared code changed:** `TierMode`, `SettingsHelper`, the storefront payloads and the templates. All changes are additive, and their Lite consumers ignore the new value.

**UI** (SPA in `apps/admin/src/pro/`, behind `IS_PRO_BUILD`):
- **`lib/schema/tier.schema.ts` [edit]:**
  - `tierModeSchema` gains `'calendar'`;
  - `tiersCalendarPeriodSchema = z.enum(['month', 'quarter', 'year'])` and `TIERS_CALENDAR_PERIODS`;
  - `tierSettingsSchema` gains `tiers_calendar_period`;
  - the sweep `mode` enum gains `'to_calendar'`;
  - `TierSettingsInput` adds `tiers_calendar_period`;
  - `customerTierSchema` gains `period: tiersCalendarPeriodSchema.nullable()`;
  - the `Mirrors` comments are updated.
- **`pro/tiers/TierModeCard.tsx` [edit]:**
  - **Calendar option:** `MODES` gains `calendar`, labelled "Calendar" with the description "Points earned in each calendar month, quarter or year. A tier is kept for the rest of that period and all of the next."
  - **Layout:** the RadioGroup class becomes `grid gap-3 sm:grid-cols-3`.
  - **Period select:** shown only for Calendar, labelled "Period" (`id` `yayloyalty-tiers-calendar-period`, width `w-48`), with the items "Monthly", "Quarterly" and "Yearly".
  - **Payload:** `tiers_calendar_period` goes in the input only when the mode is `calendar` and it differs from the stored value.
  - **`Change`:** becomes `'to_rolling' | 'to_lifetime' | 'to_calendar' | 'months' | 'period'`. When `tiers_mode` changes, the target mode picks the change. Otherwise `months` (Rolling) or `period` (Calendar) applies.
  - **Confirms:**
    - **`to_calendar`:** title "Switch to calendar qualification?", confirm "Switch".
    - **`period`:** title "Change the calendar period?", confirm "Change".
    - Both descriptions use the sentence for the chosen period:
      - "Every current member keeps their tier until the end of this month. Then each is reviewed on the points they earned in it."
      - "Every current member keeps their tier until the end of this quarter. Then each is reviewed on the points they earned in it."
      - "Every current member keeps their tier until the end of this year. Then each is reviewed on the points they earned in it."
    - `to_rolling`, `to_lifetime` and `months` are unchanged.
  - **Unchanged:** the toast, the error handling, the sweep-disabled state, and the tiers-off line.
  - **Docblock:** the component's docblock names the three modes.
- **`pro/customers/CustomerTierCard.tsx` [edit]:**
  - **Hold sub-line:** shown whenever `mode` is `rolling` or `calendar` (today it is Rolling only).
  - **Caption** when `mode` is `calendar`:
    - "%1$s / %2$s this month";
    - "%1$s / %2$s this quarter";
    - "%1$s / %2$s this year".

    Each is a full string with the translators comment "1: points earned this period, 2: points the next tier needs."
  - Lifetime and Rolling output is unchanged.
- **`pro/tiers/tiers.api.ts`:** no change beyond the widened input type.
- **Storefront captions** (`LauncherCopy::tier_period_caption()`), with the translators comment "%s: the store's points label, as "Points"":
  - "Counts %s earned this month."
  - "Counts %s earned this quarter."
  - "Counts %s earned this year."

**Edge cases and failure modes:**
- **A row at exactly a boundary** belongs to the new period.
- **Month overflow** can't occur, because boundaries are day 1.
- **DST:** wall-clock boundaries in `wp_timezone()` (AC 15).
- **Site time-zone change:** stored holds keep their UTC dates. L rounds to the nearest whole month, so the qualifying window stays 1, 3 or 12 months long, give or take the hours of the offset change.
- **Daily job late** (Action Scheduler or WooCommerce down):
  - An ended hold counts as inactive in the meantime, as in Rolling. A negative row gives the hold-end result with its own reason, and a positive row moves up only.
  - When the job runs, "previous" is the last full period before now. A job two or more periods late reviews on the most recent full period.
- **Inside the last second of a period:** `end_hold()` waits until `tier_held_until` + 1 s ≤ now.
- **Hand-edited hold** (`tier_held_from` NULL or ≥ `tier_held_until`): L = m(P) of the current setting, and a missing start is `tier_held_until` + 1 s − L months.
- **Tiers off:** nothing is written and holds freeze. Turning tiers on runs placement in Calendar.
- **Mode sweep cancelled by turning tiers off:** turning back on runs placement in the stored mode and period.
- **Tier deleted or thresholds changed:** `reevaluate` as Rolling, with the Calendar window tier.
- **Performance:**
  - A Calendar evaluation runs `window_total()` twice (current and previous) and `staff_adjust_since()` once. With an active hold it adds one more of each. These are per-user SUMs on `idx_user_created`.
  - `GET /customers/{id}/tier` and the storefront tier line add one `window_total()`.
  - Lifetime and Rolling add no query.
  - A boundary's hold ends are spread over 100-customer batches.
- **Lite downgrade:** the settings stay, nothing reads holds, `tier_id` freezes as today, and earning is unaffected.

**Decision record:** none. The approach reuses the evaluator, the hold columns and the ledger reads, and changes no architectural choice. D20 covers the hold writes. The product-level reversal is recorded in **Refinements of the master**.

## Refinements of the master

| Master | This doc | Why |
|---|---|---|
| Rejected: "Calendar-year reset … a cliff edge on one date"; Out: "a calendar-year mode" | Adds Calendar mode (month, quarter, year). The cliff is softened: a tier reached during a period is kept through the whole next period | The user wants a review date that is the same for every member. Merchants pick the mode, and Lifetime remains the default |
| D3 (mode switches) | Lifetime/Rolling → Calendar, and a period change, keep every member's tier to the end of the current period, then review | Same spirit as Lifetime → Rolling: switching never drops anyone on the day |
| Rolling: a months change keeps hold dates (no sweep) | A Calendar **period** change resets holds to the current period's end through `to_calendar` | User decision. A period change re-aligns everyone to the new calendar at once |

## Alternatives considered

| Option | Why rejected |
|---|---|
| B: tier = best of previous/current period, with no holds and a full sweep at every boundary | A sweep of every customer each month in monthly mode. "Keep to period end" on a switch needs stored state anyway, and the qualifying-period refund protection would have to be rebuilt |
| C: Calendar as a Rolling variant (`tiers_rolling_anchor`) | The Window select would mix months and periods, and every `rolling` branch would need a sub-check |
| Spend as the metric | A new tier metric. The master put spend out of scope, and points keep one meaning across modes |
| A start month the merchant picks | An extra setting and more date math for an unrequested case |
| Keep the tier only to the end of the period it was reached in | A tier reached late in a period would barely be enjoyed |
| Re-place everyone at once when switching to Calendar | Many members could drop on the day of the switch |
| Keep through the next period on a switch | Delays the first real review by up to two periods |
| Holds absorb every refund and deduction | A large refunded order would keep an unearned tier for up to two periods |
| During a hold, last period counts only through the qualifying total (window = current period) | A staff gift wouldn't touch last period's tier, but `rolling_move()` would need a change so that a lowered threshold still raises held customers |
| Staff deductions wait for the review date | Staff couldn't demote a member who holds last period's tier for up to a whole period |
| A period change keeps hold end dates | User chose to reset holds to the new period's end |
| A period change re-reviews everyone now | A Yearly → Monthly change would drop members on the day of the change |
| `tier_held_until` at the next boundary (00:00:00) | Every display would show the day after the real last day. The last second reads "31 Dec" with no special casing |
| Boundary math in Lite `Dates` | Only Pro uses it, and a pure Pro helper that takes a `\DateTimeZone` is unit-testable without WordPress |

## Rollout

- **Defaults:** `tiers_mode` stays `lifetime`, and `tiers_calendar_period` defaults to `year`. Nothing changes until a merchant picks Calendar.
- **Existing installs:** no migration. The new key reads its default until it is saved.
- **Version:** stays `1.0.0` on this branch. The release that ships tiers bumps it.
- **Release note** (with the tiers release): "Tiers can qualify on calendar periods: Loyalty → Tiers → Qualification → Calendar (monthly, quarterly or yearly). A tier reached in a period is kept for the rest of it and all of the next, and switching keeps every member's tier to the end of the current period."

## Open questions

None.
