# Tier Rolling Mode (Tiers slice 4) Design

**Status:** accepted 2026-11-05
**Related:** [Tiers master design](2026-11-02-tiers-master-design.md) (Delivery slice 4), [slice 2 design](2026-11-04-tier-lifetime-evaluation-design.md), [slice 3 design](2026-11-04-tier-perks-design.md), [the evaluator decision record](../decisions/2026-11-04-tier-evaluator-sole-writer.md), `docs/backlog/2026-10-06-tiers-not-built.md`

This child spec refines the master for slice 4. Everything the master decides (D1–D22) and everything slices 2 and 3 fixed still holds. Where this doc departs from the master's wording, **Refinements of the master** says so.

## Goal

A Pro merchant can switch tier qualification from Lifetime to Rolling: customers qualify on the points they earned in the last 3, 6, 9, 12, 18 or 24 months, reaching a tier holds it for that many months, and at the hold's end they move straight to the tier their recent points qualify for. Staff see the hold and the rolling progress on the drawer's tier card.

## Context

- **Slices 1–3 (done, on `jason/feat/tiers`):**
  - `customers.tier_held_until` (with `KEY tier_held_until`) exists and is never written. `Models\Customer` doesn't map it.
  - Settings `tiers_mode` (`lifetime` | `rolling`, default `lifetime`) and `tiers_rolling_months` (default 6) exist in `SettingsHelper`, and `GET /tiers/settings` returns them. `PUT /tiers/settings` accepts only `tiers_enabled` (`TiersRestController::update_settings()`).
  - `Pro\Services\TierDecision` (pure): `target( $live, $total, $floor )`, `ledger_policy()`, `move()`, with `MOVE_UP` and `MOVE_TO_TARGET`. It knows only one total.
  - `Pro\Services\TierEvaluator::apply()` reads `lifetime_earned` as the total, writes columns through `CustomerRepository::set_tier_state()`, records `TIER_CHANGED`, pays the bonus, and fires `yayloyalty_tier_changed` after commit. Entry points: `on_ledger_entry()`, `sweep_one()`, `assign_floor()`, `clear_floor()`.
  - `Pro\Services\TierSweep`: option `yayloyalty_tier_sweep`, modes `placement` and `reevaluate`; `request()` keeps `placement` when either side is placement.
  - `Pro\Services\TierRunner::run()` on `yayloyalty_tier_run`: ending floors (`floor_ended_user_ids()`), then one sweep batch, 100 each, async continuation.
  - `Enums\TierChangeReason` (Lite) reserves `HOLD_END` (`hold_end`).
  - `Pro\Controllers\CustomerTierRestController::respond()`: `progress_value` from `tier_progress_value`, else `lifetime_earned`.
  - SPA: `pages/tiers/TiersPage.tsx` renders `TierSwitchCard` and `TierCatalog` from `@/pro`. `pro/customers/CustomerTierCard.tsx` shows TIER ("Since %s"), NEXT TIER ("%1$s pts to %2$s", bar, caption "%1$s / %2$s"), and the manual strip. `lib/schema/tier.schema.ts` already types `tiers_mode` and `tiers_rolling_months`.
- **Clawback rows** (`Services\LedgerService::refund_earn()`, `restore_refund()`, `reversal_draft()`): a REFUND_EARN row, and the ADJUST row with `reference_type` `refund` that restores it, copy the earn row's `user_id`, `campaign_id` and `order_id`, and carry their own `created_at`. A non-order clawback (a review) carries the earn's `reference_type`/`reference_id`. Nothing stores the reversed earn's id.
- **`LedgerRepository::sum_refunded_for( $earn )`** matches an earn's clawbacks by user + `order_id` + campaign (with refund-referencing ADJUST rows netted) for order earns, or by user + reference for the rest. `earned_since()` and `lifetime_earned_before()` sum `lifetime_earned_types()` by `created_at`.
- **Dates:** `Utils\Dates` has `now()`, `offset( seconds )`, `to_utc()`, `to_timestamp()`; no month arithmetic.
- **Precedent:** `ExpiryPolicy::ON_SHORTEN_KEEP` keeps stored dates when a window shortens (master D3 follows it).

## Scope

**In:**
- The Rolling qualifying total, with clawbacks attributed to the date of the earn row they reverse (D4), computed at query time.
- Holds: `tier_held_from` (new column) and `tier_held_until`, written by `TierEvaluator` only.
- The Rolling rules in **Evaluation rules**: upgrades, negative rows during and outside a hold, hold end and renewal, floors, sweeps.
- The hold-end step in `TierRunner`.
- Mode changes through two new sweep modes, `to_rolling` and `to_lifetime`, and months changes with no sweep (D3).
- `PUT /tiers/settings` accepting `tiers_mode` and `tiers_rolling_months`.
- `GET /customers/{id}/tier` returning the mode, the window, the hold, and the live window total in Rolling.
- The Tiers page "Qualification" card, the drawer card's hold line and rolling caption, and the `hold_end` and `mode_change` activity labels.

**Out:**
- **Slice 5:** the launcher badge and progress line. They will reuse `GET /customers/{id}/tier`'s live window total rule, but no storefront code changes here.
- A column, filter or sort for holds in the Customers list, and a hold field in saved segments.
- An activity row for a hold renewal (same tier at hold end), or for a hold started without a tier change.
- Tier emails (Tier ending soon included); `yayloyalty_tier_changed` is unchanged and fires for `hold_end` and `mode_change` moves too.
- A calendar-year mode, an inactivity downgrade, a one-step-per-period downgrade, a spend metric (master Out).
- Changing Lifetime mode's behaviour: every slice 2 rule stays as written.
- Changing `LedgerService` or any ledger write, or adding a ledger column.
- A plugin version bump: it stays `1.0.0`, as in slices 1–3.

## Evaluation rules

Lifetime mode is exactly slice 2's **Evaluation rules**. The rest of this section applies only while `tiers_mode` is `rolling`. N = `tiers_rolling_months`, read at evaluation time.

**Definitions**, computed under the customer-row lock:
- **Attributed date** of a ledger row: its `created_at`, except for a clawback row, which takes the `created_at` of the earn row it reverses. A clawback row is a REFUND_EARN row, or an ADJUST row with `reference_type` `refund`. Its earn row is the EARN row with the same `user_id` and:
  - when the clawback has an `order_id`: the same `order_id` and the same `campaign_id` (NULL-safe);
  - otherwise: the same `reference_type` and `reference_id`.

  The earliest matching EARN row is used. A clawback with no matching EARN row counts on its own `created_at`.
- **Points in [from, to]** = the signed SUM of `points` over the user's EARN, REFUND_EARN and ADJUST rows whose attributed date is ≥ from and ≤ to.
- **Window total** = points in [now − N months, now]. **Window tier** = the live tier with the highest threshold ≤ the window total, or none.
- **Hold** = `tier_held_from` → `tier_held_until`. It's **active** while `tier_id` is not NULL and `tier_held_until` > now.
- **Hold length L** = the whole months from `tier_held_from` to `tier_held_until` (`DateTimeImmutable::diff()`, y × 12 + m). L comes from the stored hold, so a months change never alters an existing hold's window (D3).
- **Qualifying total** of an active hold = points in [`tier_held_from` − L months, `tier_held_from`] + the signed SUM of the user's ADJUST rows whose `reference_type` isn't `refund` and whose `created_at` > `tier_held_from`. **Qualifying tier** = the live tier with the highest threshold ≤ the qualifying total, or none.
- **Floor**, **current** and the rank comparison are slice 2's.
- **Floor-held:** the customer has a floor and it is the reason for their tier (the floor's tier is live, not ended, and its rank ≥ the window tier's rank).
- **Start a hold:** `tier_held_from` = now, `tier_held_until` = now + N calendar months (`Dates::add_months()`).
- **Clear the hold:** both hold columns NULL.

| Trigger (Rolling) | Move | Hold afterwards | `reason` |
|---|---|---|---|
| Ledger row, `affects_lifetime_earned`, points > 0, source ≠ `tier_bonus` | up only, to max(window tier, floor) | an upgrade to the window tier starts a hold; an upgrade to the floor clears it; no change keeps it | slice 2's (`earn`, `refund`, `adjustment`) |
| Ledger row, `affects_lifetime_earned`, points < 0, **no active hold** | to max(window tier, floor), up or down | the result is the window tier and not floor-held → start a hold; the result is the floor → clear; none → clear | `refund` or `adjustment` |
| Ledger row, `affects_lifetime_earned`, points < 0, **active hold** | no change if the window tier or the qualifying tier ranks ≥ current. Otherwise to max(qualifying tier, window tier, floor) | the window tier ranks ≥ the qualifying tier and wins → start a hold; the qualifying tier wins → keep the hold dates; the floor wins → clear; none → clear | `refund` or `adjustment` |
| `tier_bonus` row, or a type that doesn't affect `lifetime_earned` | none (slice 2) | — | — |
| **Hold end:** daily step, `tier_held_until` ≤ now | to max(window tier, floor) exactly | the window tier and not floor-held → start a hold, also when the tier is unchanged (renewal); the floor → clear; none → clear | `hold_end` |
| Manual assign (`POST /customers/{id}/tier`) | set the floor; then up only (slice 2) | the floor raised the customer → clear; otherwise keep | `manual` |
| Floor ends (daily step) or `DELETE /customers/{id}/tier` | clear the floor; then to the window tier | a tier → start a hold; none → clear | `manual_end` |
| `placement` sweep | to max(window tier, floor) exactly | the window tier and not floor-held → start a hold; otherwise clear | `placement` |
| `reevaluate` sweep, current or floor tier deleted | slice 2's (clear that floor; to target, target = max(window tier, floor)) | as for a negative row with no active hold | `tier_deleted` |
| `reevaluate` sweep, any other customer | up only, to max(window tier, floor) | as for a positive ledger row | `threshold_change` |
| `to_rolling` sweep (Lifetime → Rolling) | none (slice 2's deleted-tier handling first, as in `reevaluate`) | a customer with a tier who isn't floor-held → start a hold; otherwise clear | `tier_deleted` only when that handling moves them |
| `to_lifetime` sweep (Rolling → Lifetime) | clear the hold; then up only by `lifetime_earned` (Lifetime rules; deleted-tier handling as in `reevaluate`) | cleared for everyone | `mode_change` (`tier_deleted` when that handling moves them) |

**On every evaluation that runs:**
- `tier_evaluated_at` = now.
- `tier_progress_value` = the window total in Rolling, or `lifetime_earned` in Lifetime. In both, the entry bonus paid in this evaluation is added, as in slice 2.

**When the tier changes:** slice 2's steps, unchanged: `tier_id`, `tier_achieved_at` = now, one `TIER_CHANGED` row, the entry bonus on an upgrade except in `placement` (once per tier, ever, D11), and `yayloyalty_tier_changed` after commit.

**A hold renewal** (hold end, same tier) writes only `tier_held_from`, `tier_held_until`, `tier_evaluated_at` and `tier_progress_value`. It writes no activity row and fires no action.

**Mode and months changes** (`PUT /tiers/settings`):

| Change | While tiers are on | While tiers are off |
|---|---|---|
| `tiers_mode` lifetime → rolling | stored; `TierSweep::request( MODE_TO_ROLLING )` | stored only |
| `tiers_mode` rolling → lifetime | stored; `TierSweep::request( MODE_TO_LIFETIME )` | stored only |
| `tiers_rolling_months` only | stored; no sweep. Holds keep their dates, and the new N applies from each customer's next evaluation (D3) | stored only |
| `tiers_enabled` false → true | `request( MODE_PLACEMENT )`, run in the stored mode (slice 2) | — |

- **A pending sweep refuses a mode change** with 409 `yayloyalty_tier_sweep_running`. Turning tiers off still cancels any sweep (slice 2).
- **Sweep merge** in `TierSweep::request()` (it still resets the cursor and recounts):
  - stored or requested `placement` → `placement`;
  - otherwise, stored or requested `to_rolling`/`to_lifetime` → that mode sweep;
  - otherwise `reevaluate`.

  A mode sweep's step also does `reevaluate`'s work, because the merge drops it.
- **A mode sweep runs the step named by its mode,** whatever `tiers_mode` holds when a batch runs. The mode can't change again until the sweep finishes.

Consequences that follow from these rules (accepted):
- A Lifetime → Rolling → Lifetime round trip clears holds, and a later switch back gives fresh holds of now + N.
- A raised threshold (D18) keeps Rolling members until hold end. A negative row during the hold compares the qualifying total against the new threshold, so a refund can then drop them (as slice 2 accepted for Lifetime).
- A customer whose window decays below their tier during a hold keeps it until hold end, unless a clawback or staff deduction pulls the qualifying total below the tier.

## Acceptance criteria

Setup unless stated:
- the wp-env Pro site;
- the seed tiers Bronze 0 / Silver 800 / Gold 3000, every bonus 0, multipliers all 1.00 (so the order math stays plain);
- tiers on, Rolling mode with 6 months, every sweep finished;
- the default purchase campaign at 1 pt per currency unit, rounding `down`.

"Backdate a row" means `UPDATE wp_yayloyalty_ledger SET created_at = … WHERE id = …` through `wp db query`, then `wp eval` calling `TierEvaluator::build()->sweep_one( $id, 'reevaluate' )` when an evaluation is needed. "Run the daily job" means `wp action-scheduler run --hooks=yayloyalty_tier_run`. T is the moment of the action.

1. **Settings validation.** Each of these returns 400 and changes no setting:
   - `PUT /tiers/settings {}`;
   - `{"tiers_rolling_months":5}`;
   - `{"tiers_mode":"yearly"}`.

   With a sweep pending, `{"tiers_mode":"lifetime"}` returns 409 `yayloyalty_tier_sweep_running` with the message "Wait for the current tier update to finish." `{"tiers_rolling_months":12}` returns 200 while a sweep is pending.
2. **Lifetime → Rolling keeps members.** In Lifetime mode, customer A is Silver (`lifetime_earned` 900, 100 of it in the last 6 months) and customer B is Gold with a Gold floor (No end).
   - `PUT /tiers/settings {"tiers_mode":"rolling"}` returns 200 with `sweep.mode` `to_rolling`.
   - When the sweep finishes:
     - A is still Silver, with `tier_held_from` = T and `tier_held_until` = T + 6 months;
     - A's `tier_progress_value` is 100;
     - B is Gold, with both hold columns NULL;
     - neither has a new `tier_changed` row.
3. **Upgrade on the window, not lifetime.** Customer C has `lifetime_earned` 2,000, of which 780 is in the last 6 months, and is Bronze with no hold. A 30-unit order completes:
   - C is Silver, reason `earn`, with `tier_progress_value` 810, `tier_held_from` = T and `tier_held_until` = T + 6 months.
   - Customer D, Bronze with 2,000 lifetime and 100 in the window, completes a 30-unit order and stays Bronze, with `tier_progress_value` 130.
4. **Hold and hold end** (master AC 14). Customer E is Gold with `tier_held_from` 2027-01-10 00:00:00 and `tier_held_until` 2027-07-10 00:00:00, set by reaching Gold. The hold is checked by backdating E's columns and rows to those dates.
   - A run of the daily job before `tier_held_until` leaves E Gold.
   - With `tier_held_until` set to the past and E's window total at 900, the daily job moves E to Silver:
     - one `tier_changed` row `{from_name: "Gold", to_name: "Silver", reason: "hold_end"}`;
     - `tier_held_from` = T and `tier_held_until` = T + 6 months;
     - `yayloyalty_tier_changed` fired once with reason `hold_end`.
5. **Hold end skips several tiers or clears.**
   - A Gold customer at hold end with a window total of 0 ends Bronze (the seed threshold is 0), with one row Gold → Bronze and a new hold.
   - With Bronze's threshold raised to 100, a Gold customer at hold end with a window total of 0 ends with `tier_id` NULL, both hold columns NULL, and reason `hold_end`.
6. **Renewal.** A Silver customer at hold end with a window total of 1,000 stays Silver. `tier_held_from` = T and `tier_held_until` = T + 6 months. No `tier_changed` row is written, and `yayloyalty_tier_changed` doesn't fire.
7. **Months change** (master AC 15). With 12 months, a customer holds Silver until 2027-12-01 00:00:00 (`tier_held_from` 2026-12-01 00:00:00).
   - `PUT /tiers/settings {"tiers_rolling_months":6}` returns 200 with `sweep` null and leaves both hold columns unchanged.
   - Their next 1-unit order writes `tier_progress_value` = their last-6-month total.
   - A negative row during the hold computes the qualifying total over [2025-12-01, 2026-12-01] (L = 12).
8. **Refund in the qualifying window** (D6, master AC 10). Customer F, tierless with 0 points, completes an order earning 3,000 and is Gold, with `tier_held_from` = T₁.
   - Refunding half the order (1,500) moves F to Silver (reason `refund`): the qualifying total and the window total are both 1,500.
   - The window tier ties the qualifying tier, so a new hold starts at the refund's time.
9. **Refund in another window** (master AC 16). Customer G's earn row of 900 is backdated 8 months, and a 100-point earn sits in the window. G is Bronze.
   - Fully refunding that older order writes a REFUND_EARN row of −900 dated now.
   - G's window total stays 100, `tier_id` stays Bronze, and `tier_evaluated_at` = T.
   - Deleting the refund in WooCommerce writes the restoring ADJUST row (+900), and the window total still reads 100.
10. **A refund after the hold started doesn't strip the hold.** Customer H holds Gold, with a qualifying total of 3,000 from earns before `tier_held_from`, and a window total that has decayed to 200.
    - A new 100-unit order completes (window 300), then is fully refunded.
    - H stays Gold with the hold dates unchanged, because the clawback is attributed after `tier_held_from`, outside the qualifying window.
11. **Staff deduction during a hold.** Customer I holds Gold with a qualifying total of 3,200 and a window total of 500.
    - An ADJUST of −100 leaves I Gold (qualifying 3,100).
    - A further ADJUST of −200 moves I to Silver (reason `adjustment`): the qualifying total is 2,900, which reaches Silver, and the window total is 200, which reaches Bronze. The hold dates stay unchanged.
    - An ADJUST of +400 then leaves I Silver: a positive row moves up only by the window tier, and the window total of 600 reaches Bronze.
12. **Floor replaces the hold.** Customer J holds Silver until T + 4 months, with a window total of 900.
    - Assigning Gold, Limited 30 days, makes J Gold (reason `manual`) with both hold columns NULL.
    - After the floor ends (set `tier_manual_until` in the past, run the daily job), J is Silver (reason `manual_end`), with `tier_held_from` = the run's time and `tier_held_until` 6 months later.
    - A low floor (assigning Bronze to a held Silver customer) leaves the hold columns unchanged.
13. **Rolling → Lifetime.** Customer K is Silver with `lifetime_earned` 3,500 and a hold, and customer L is Gold with `lifetime_earned` 2,500 and a hold (a raised or kept tier).
    - `PUT /tiers/settings {"tiers_mode":"lifetime"}` runs `to_lifetime`. When it finishes:
      - K is Gold, with one `tier_changed` row with reason `mode_change`;
      - L is still Gold;
      - both have both hold columns NULL.
    - With Gold's bonus set to 50 before the switch, K's move writes one 50-point `tier_bonus` row.
14. **Placement in Rolling.** With tiers off and the mode set to Rolling (stored with no sweep and no confirm), customer M has `lifetime_earned` 5,000 and a window total of 900.
    - Turning tiers on runs placement. M is Silver (reason `placement`), with a hold of T → T + 6 months and no bonus row.
15. **Clawback amounts unchanged.** In Lifetime mode, a 200-unit order refunded 50 and then 50 writes two REFUND_EARN rows of −50 each, exactly as before the `sum_refunded_for()` refactor.
16. **Drawer card** in Rolling, for customer A from AC 2 (Silver, held):
    - The TIER sub-line reads "Since {achieved date} · held until {T + 6 months}".
    - NEXT TIER reads "2,900 pts to Gold", with the caption "100 / 3,000 in the last 6 months".
    - After backdating A's 100 window points by 7 months (no evaluation), reopening the drawer shows "3,000 pts to Gold" and "0 / 3,000 in the last 6 months".
    - In Lifetime mode the card is exactly slice 2's.
17. **Activity labels.** The drawer's Tier changes filter shows the AC 4 row as "Gold → Silver" with the sub-line "Tier hold ended", and the AC 13 row as "Silver → Gold" with "Tier mode changed".
18. **Qualification card.**
    - The Tiers page shows "Qualification" with Lifetime and Rolling. Choosing Rolling shows the months select (3, 6, 9, 12, 18, 24), and Save is disabled until a value changes.
    - With tiers on:
      - Lifetime → Rolling at 6 asks "Switch to rolling qualification?";
      - Rolling → Lifetime asks "Switch to lifetime qualification?";
      - changing only the months asks "Change the rolling window?".
    - Accepting toasts "Qualification saved."; a mode sweep shows the slice 2 banner.
    - While a sweep runs, Save is disabled with "Wait for the current tier update to finish.".
    - With tiers off, saving needs no confirm and shows "Applies when you turn tiers on.".
19. **Lifetime unchanged.** In Lifetime mode, slice 2's ACs 3, 6, 7 and 10 pass as written, and no customer's hold columns are written.
20. **Lite.** On the Lite build over the same database:
    - `Installer::install()` keeps `tier_held_from`, `tier_held_until` and every tier column unchanged;
    - `tiers_mode` and `tiers_rolling_months` stay in `yayloyalty_settings`;
    - `./scripts/run.sh release lite --no-prompt` passes validation.
21. **End to end** on the wp-env Pro site, with the setup above but Lifetime mode, and a Silver customer at 900 lifetime points whose points are all older than 6 months (backdated):
    1. On Tiers, switch Qualification to Rolling, 6 months → the confirm names 6 months. The banner shows and clears, and the drawer card reads "Silver · held until {today + 6 months}" and "0 / 3,000 in the last 6 months".
    2. Set `tier_held_until` to yesterday and run the daily job → the customer is Bronze (window 0, Bronze's threshold 0). The activity tab shows "Silver → Bronze", "Tier hold ended".
    3. The customer completes an 800-unit order → they're Silver with a new hold to today + 6 months.
    4. Refund that order fully → they're Bronze again (reason `refund`).
    5. Switch back to Lifetime → the confirm says no one moves down. After the sweep the customer is Silver (lifetime 900 + 800 − 800 = 900), reason `mode_change`, with no hold line.
    6. The Chrome console shows no error or warning from `yayloyalty` on `#/tiers`, `#/customers` and the drawer.

## Constraints

- **PHP 7.4:** no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static`/`never` types, `catch` without a variable, `throw` as an expression, trailing comma after the last parameter, first-class callables or `$object::class` (hard rule 4, `.claude/rules/php-standards.md`).
- **Lite names Pro only in the guarded `YayLoyaltyPro::boot()` call.** The Lite-side additions don't name Pro in code: `CustomersTable`'s column, `Customer`'s mapping, `LedgerRepository::window_total()`/`staff_adjust_since()`, `CustomerRepository::hold_ended_user_ids()`, `Dates::add_months()`/`whole_months_between()`, and `TierChangeReason::MODE_CHANGE`.
- **SPA tier code** lives in `apps/admin/src/pro/`, is imported only through `apps/admin/src/pro/index.ts`, and renders only behind `IS_PRO_BUILD`. `TiersPage.tsx` gains one more `@/pro` import.
- **Single writers:**
  - `TierEvaluator` is the only writer of every tier column, the two hold columns included (D20).
  - `LedgerService` is unchanged, and no ledger row or column is added.
- **The evaluator runs after the ledger commit,** in its own transaction under the customer-row lock. The window queries run inside that transaction as locking-free reads of committed rows.
- **Dates** are stored in UTC through `Utils\Dates`. Month arithmetic uses `DateTimeImmutable` in UTC with PHP's month overflow (Aug 31 + 6 months = Mar 3 in a non-leap year).
- **SQL:** through `$wpdb->prepare()`; names through `esc_sql()`; type lists through `Sql::in_placeholders()`. A repository doesn't open a transaction or touch a second table, apart from the ledger self-join inside `LedgerRepository`.
- **REST:** callbacks via `exec_read`/`exec_write`, and `permission_callback` via `$this->requires( Capabilities::MANAGE_TIERS )` (settings) or as slice 2 (customer tier). Responses are snake_case and parsed with zod in `*.api.ts`.
- **Settings keys** keep their `SettingsHelper` defaults and schema. `PUT /tiers/settings` writes them through `SettingsHelper::update_settings()`, and `POST /settings` keeps dropping them (`TIER_KEYS`).
- **Strings** use the `yayloyalty` text domain, with a `translators:` comment on every placeholder.
- **Tests:** per CLAUDE.md § Tests. The window total (points math over ledger rows, with clawback attribution) and `TierDecision`'s Rolling rule qualify. yayplan proposes them with AskUserQuestion before writing them. Everything else ends in an exact manual check.
- **Branch:** all work on `jason/feat/tiers`; commit only when the user asks.

## Approach

**Chosen:** the Rolling total computed at query time from the existing ledger rows, with a stored hold start, decided by `TierDecision` and written by `TierEvaluator` (approach A).

**Architecture:**
- `TierEvaluator::apply()` reads `tiers_mode` once. In Lifetime it runs slice 2's path untouched.
- In Rolling it gathers three facts under the lock: the window total, the qualifying total when a hold is active, and the floor. It passes them to `TierDecision::rolling_move()`, which returns the move and the hold action. Then it writes everything through `set_tier_state()`.
- `TierRunner` gains a hold-end step. `TierSweep` gains two mode sweeps, which `TiersRestController` requests. No route writes a customer column.

**Components:**
- `includes/Database/Schema/CustomersTable.php` [edit, Lite]: `tier_held_from datetime DEFAULT NULL`, after `tier_held_until`, added to `expected_columns()`. No key. The docblock gains a line: the hold runs from `tier_held_from` to `tier_held_until`, written only by the tier evaluator.
- `includes/Models/Customer.php` [edit, Lite]: `get_tier_held_until(): ?string` and `get_tier_held_from(): ?string`.
- `includes/Repositories/CustomerRepository.php` [edit]:
  - `set_tier_state()` accepts `tier_held_from` and `tier_held_until`;
  - `hold_ended_user_ids( string $now, int $limit ): int[]` (`tier_id IS NOT NULL AND tier_held_until IS NOT NULL AND tier_held_until <= %s`, ordered by `tier_held_until`, using `KEY tier_held_until`).
- `includes/Repositories/LedgerRepository.php` [edit, Lite]:
  - `window_total( int $user_id, string $from, string $to ): int`: points in [from, to] with **Definitions**' attribution. A non-clawback row is summed by its `created_at`. A clawback row is summed by `COALESCE( the earliest matching EARN row's created_at, its own created_at )`.
  - `staff_adjust_since( int $user_id, string $after ): int`: the signed SUM of ADJUST rows with `reference_type` NULL or not `refund`, created strictly after `$after`.
  - A private builder for the clawback ↔ earn match predicate (order: user + `order_id` + campaign NULL-safe; otherwise user + `reference_type` + `reference_id`), used by both `window_total()` and `sum_refunded_for()`. `sum_refunded_for()`'s results don't change (AC 15).
- `includes/Utils/Dates.php` [edit]: `add_months( string $utc, int $months ): string` (negative months allowed) and `whole_months_between( string $from_utc, string $to_utc ): int`.
- `includes/Enums/TierChangeReason.php` [edit, Lite]: `MODE_CHANGE = 'mode_change'`, added to its list. `HOLD_END` is now written.
- `includes/Pro/Services/TierDecision.php` [edit]:
  - `HOLD_KEEP`, `HOLD_START`, `HOLD_CLEAR`;
  - `rolling_move( ?int $current_id, int $current_rank, ?Tier $window_tier, ?Tier $qualifying_tier, ?Tier $floor, bool $hold_active, string $trigger_kind ): array{changed, to, is_upgrade, hold}`, where `trigger_kind` is one of `up`, `down`, `hold_end`, `to_target`, `floor_up`. It stays pure.
  - `target()` is reused for each tier lookup.
- `includes/Pro/Services/TierEvaluator.php` [edit]:
  - the mode branch in `apply()`;
  - `end_hold( int $user_id ): void` (reason `hold_end`);
  - `sweep_one()` handles `MODE_TO_ROLLING` and `MODE_TO_LIFETIME`;
  - `tier_progress_value` in Rolling is the window total;
  - hold columns written per the rules;
  - `assign_floor()`/`clear_floor()` gain the hold handling.
- `includes/Pro/Services/TierSweep.php` [edit]: `MODE_TO_ROLLING = 'to_rolling'`, `MODE_TO_LIFETIME = 'to_lifetime'`, the merge order, and `is_pending(): bool`.
- `includes/Pro/Services/TierRunner.php` [edit]: between ending floors and the sweep batch, while `tiers_mode` is `rolling`: up to 100 ids from `hold_ended_user_ids( now )` → `end_hold()`. A full batch counts toward the continuation. The class docblock is updated.
- `includes/Pro/Controllers/TiersRestController.php` [edit]: `update_settings()` per **Integration points**, and its docblock no longer says only `tiers_enabled` is writable.
- `includes/Pro/Controllers/CustomerTierRestController.php` [edit]: `respond()` gains the Rolling fields and the live window total.
- `docs/backlog/2026-10-06-tiers-not-built.md` [edit]: remove the "Rolling mode and holds" bullet from Still missing.
- SPA (see **UI**).

**Data and storage:**
- **New column:** `customers.tier_held_from datetime DEFAULT NULL`. It's NULL for every row until a Rolling evaluation writes it. `tier_held_until` starts being written.
- **Settings:** `tiers_mode` and `tiers_rolling_months` keep their keys, defaults (`lifetime`, 6) and allowed values.
- **Sweep option** `yayloyalty_tier_sweep`: `mode` gains `to_rolling` and `to_lifetime`. Its shape is otherwise unchanged.
- **`TIER_CHANGED` payload:** unchanged. `reason` gains `hold_end` (now written) and `mode_change`.
- **Migration:** none. The column reaches sites through `Installer::install()` (dbDelta). Dev sites on this branch run `$WP option update yayloyalty_db_version 0` and load wp-admin once, as in slice 3.
- **Uninstall:** none, as today.

**Integration points:**
- **REST** (Pro, `yayloyalty/v1`):

  | Route | Capability | Request | Response / errors |
  |---|---|---|---|
  | `PUT /tiers/settings` | `yayloyalty_manage_tiers` | `{tiers_enabled?: bool, tiers_mode?: "lifetime"\|"rolling", tiers_rolling_months?: 3\|6\|9\|12\|18\|24}`, at least one key | The GET shape (slice 2). 400 `error_invalid_arguments` for no key or a bad value. 409 `yayloyalty_tier_sweep_running` "Wait for the current tier update to finish." for a `tiers_mode` change while `TierSweep::is_pending()`. When the response would start a sweep, `sweep` reports it at `done` 0, as slice 2 does for placement |
  | `GET/POST/DELETE /customers/{id}/tier` | as slice 2 | as slice 2 | Slice 2's shape plus `mode: "lifetime"\|"rolling"`, `window_months: int\|null` (null in Lifetime), `held_from: string\|null`, `held_until: string\|null` (null unless the hold is active). In Rolling, `progress_value` = `window_total( user, now − window_months, now )`, computed on read; in Lifetime it's slice 2's |

- **A request that changes `tiers_enabled` and `tiers_mode` together** applies `tiers_mode` first, then `tiers_enabled`. Turning on in the same request runs placement in the new mode, and no mode sweep is requested.
- **Hooks:** none new. `yayloyalty_tier_changed` fires for `hold_end` and `mode_change` moves. `yayloyalty_tier_run` gains the hold-end step.
- **Shared code changed, and who consumes it:**
  - `LedgerRepository::sum_refunded_for()`: the refactor keeps its results identical. `LedgerService::refund_earn()` and the email check that reads it are unaffected.
  - `Dates`, `Customer`, `CustomerRepository`, `TierChangeReason`: additive.

**UI** (all in `apps/admin/src/pro/`, behind `IS_PRO_BUILD`):
- **`pro/tiers/TierModeCard.tsx` [new],** exported from `@/pro`. `pages/tiers/TiersPage.tsx` [edit] renders it between `TierSwitchCard` and `TierCatalog`. Its confirm dialogs stay in the file (one caller each).
  - **Layout:** a `Card` with the title "Qualification" and the description "How customers qualify for a tier."
  - **Mode** is a `RadioGroup`:
    - "Lifetime": "Points earned since joining. Customers move down only after refunds or deductions."
    - "Rolling": "Points earned in a recent window. Reaching a tier holds it for the same number of months."
  - **Months:** a `Select` labelled "Window" with "3 months", "6 months", "9 months", "12 months", "18 months" and "24 months", shown only when Rolling is chosen.
  - **Save:** `LoadingButton` "Save", enabled only when a value differs from `TIER_QUERIES.settings()`.
    - While `sweep` is non-null it's disabled, with the muted line "Wait for the current tier update to finish."
    - While tiers are off, a muted line reads "Applies when you turn tiers on.", and Save writes with no confirm.
  - **Confirms** (`ConfirmDialog`), while tiers are on:
    - **Lifetime → Rolling:** title "Switch to rolling qualification?", description "Every current member keeps their tier for %1$s months. After that, they move to the tier their last %1$s months of points qualify for.", confirm "Switch".
    - **Rolling → Lifetime:** title "Switch to lifetime qualification?", description "Holds are cleared, and customers move up to the tier their lifetime points qualify for. No one moves down.", confirm "Switch".
    - **Months only:** title "Change the rolling window?", description "Current holds keep their end dates. The new window applies from each customer's next evaluation.", confirm "Change".
  - **After save:** the toast "Qualification saved." and invalidate `['tier']`. Errors go through `toastApiError()`. A started sweep shows slice 2's banner from `TierSwitchCard`.
- **`pro/tiers/tiers.api.ts` [edit]:** `updateTierSettings( body: Partial<{tiers_enabled, tiers_mode, tiers_rolling_months}> )`.
- **`lib/schema/tier.schema.ts` [edit]:**
  - `tiersRollingMonthsSchema` = the six values;
  - `customerTierSchema` gains `mode`, `window_months`, `held_from` and `held_until`, with its `Mirrors` comment updated.
- **`pro/customers/CustomerTierCard.tsx` [edit]:** when `mode` is `rolling`:
  - the TIER sub-line is "Since %1$s · held until %2$s" while `held_until` is set, or "Since %s" otherwise;
  - the NEXT TIER caption is "%1$s / %2$s in the last %3$s months".
  - Lifetime output is unchanged.
- **`pages/customers/drawer/customer-drawer.activity-rows.ts` [edit]:** the reason labels `hold_end` "Tier hold ended" and `mode_change` "Tier mode changed".

**Edge cases and failure modes:**
- **Hold ended, daily job not yet run:** the hold counts as inactive. A negative row gives the hold-end result (with reason `refund`/`adjustment`). A positive row moves up only, and the daily job then renews or moves them.
- **Clawback with no matching EARN row:** it counts on its own `created_at`.
- **An order with several earn rows** (one per trigger) is matched per campaign, so each clawback takes its own earn's date.
- **An unmatched restore** (ADJUST `refund`) follows the same rule.
- **Month-end overflow:** a hold from Aug 31 at 6 months ends Mar 3 (non-leap). `whole_months_between()` returns 6, so L stays right.
- **A hold whose stored dates are out of order** (`tier_held_from` NULL or ≥ `tier_held_until`, from a hand edit): L counts as N, and `tier_held_from` NULL counts as `tier_held_until` − N months.
- **Tiers off:** nothing is written (slice 2). Holds freeze. Turning on runs placement, which restarts holds from the window tiers.
- **A tier deleted under a hold:** the `reevaluate` step moves the customer to max(window tier, floor), starting or clearing the hold.
- **A mode sweep cancelled by turning tiers off:** turning back on runs placement in the stored mode, which sets or clears holds for everyone.
- **WooCommerce inactive (no Action Scheduler):** hold ends wait. An evaluation already treats an ended hold as inactive.
- **Performance:**
  - In Rolling, an evaluation runs `window_total()` once, plus once more and `staff_adjust_since()` when a hold is active. These are per-user SUMs on `idx_user_created`, and the clawback rows' earn lookup uses `order_id` or the user index.
  - `GET /customers/{id}/tier` adds one `window_total()`.
  - Lifetime adds no query.
- **Lite downgrade:** the column and settings stay. Nothing reads holds, `tier_id` freezes as in slice 2, and earning is unaffected.

**Decision record:** none. The approach reads existing rows and changes no architectural choice. D20 (the evaluator as sole writer) covers the hold columns.

## Refinements of the master

| Master | This doc | Why |
|---|---|---|
| D6 "can downgrade during a hold when the clawed-back points counted toward the current tier" | During a hold, a negative row is checked against the **qualifying total**: points attributed in [`tier_held_from` − L, `tier_held_from`] plus staff adjustments since. The customer drops only when neither it nor the window total reaches the tier | Makes "counted toward the current tier" exact, and a decayed window doesn't let a small refund strip a hold |
| D13 "Staff lower a customer by deducting lifetime points with Adjust" | In Rolling, a staff ADJUST during a hold counts against the qualifying total | Deducting enough lowers the customer, and a small deduction doesn't |
| D5 "Re-qualifying for the same tier renews the hold" | Renewal happens only at hold end | Predictable dates, and one write per customer per hold |
| D3 "Lifetime → Rolling gives every current member a hold" | Except a floor-held member, whose floor protects them | A floor replaces a hold |
| Master reason list | Adds `mode_change`, written by the Rolling → Lifetime sweep | D3's "re-evaluates, which can only raise" needs a reason, and none of the nine fits |
| Master data list | Adds `customers.tier_held_from` | The qualifying window needs the hold's start, and `tier_achieved_at` doesn't move on renewal |
| "a tier step in `yayloyalty_expiry_run`" | A step in Pro's `yayloyalty_tier_run`, as slice 2 already decided | Consistency with slice 2 |

## Alternatives considered

| Option | Why rejected |
|---|---|
| A `ledger.reverses_id` column stamped by `LedgerService` (approach B) | Changes the Lite single writer, and old refund rows still need a backfill using the same match rule |
| A `ledger.counts_at` attribution date on every row (approach C) | A full-table backfill, a new index and a writer change for one reader |
| During a hold, downgrade on the current window total | By mid-hold the window has often decayed, so any small refund would strip the tier |
| Holds absorb refunds until hold end | Contradicts D6 and master AC 10 in Rolling |
| A staff Adjust ignores the hold | A 10-point deduction would drop a customer whose window decayed |
| A hold protects against staff Adjust | Leaves staff no way to lower a Rolling customer (D13) |
| Renew the hold on every qualifying earn | The date keeps moving, with a column write on most earns |
| Show the stored `tier_progress_value` in the drawer | Can be weeks stale in Rolling |
| Keep the hold running under a floor | Needs a held-tier column, because `tier_id` names the floor tier |
| No hold after a floor ends | Treats a floor ending more harshly than a hold ending |
| Derive the hold start from `tier_held_until` − N | A months change (D3) would shift every existing hold's qualifying window |
| Set holds with one bulk UPDATE on Lifetime → Rolling | Bypasses `TierEvaluator` (D20) and the 100-per-batch rule |
| Merge a mode change into a pending sweep | A half-run mode sweep and a new mode would leave customers with mixed hold states; refusing with 409 is simpler |

## Rollout

- **Defaults:** `tiers_mode` stays `lifetime`, so nothing changes until a merchant picks Rolling.
- **Dev sites on `jason/feat/tiers`:** reset `yayloyalty_db_version` (or deactivate and reactivate) so `Installer::install()` adds `tier_held_from`.
- **Version:** stays `1.0.0` on this branch. The release that ships tiers bumps it, as slices 1–3 note.
- **Release note** (with the tiers release): "Tiers can qualify on a rolling window: Loyalty → Tiers → Qualification. Switching keeps every current member in their tier for the window's length."

## Open questions

None.
