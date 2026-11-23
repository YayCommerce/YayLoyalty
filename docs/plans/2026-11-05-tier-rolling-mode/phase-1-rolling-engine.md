# Phase 1: Rolling engine and the settings route

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** slices 1–3, already on `jason/feat/tiers` (`TierDecision`, `TierEvaluator`, `TierSweep`, `TierRunner`, `GET/PUT /tiers/settings`).
**Delivers:**
- `PUT /tiers/settings` switches between Lifetime and Rolling and sets the months.
- In Rolling, customers qualify on their window total, reaching a tier starts a hold, refunds and staff deductions follow the qualifying-window rule, and the daily job ends holds (moving or renewing).
- Switching back to Lifetime clears holds and only raises.

**Exit criterion:**
1. The tests pass, with the tests counted in each summary line:
   - `composer test -- --filter TierDecisionTest` → OK;
   - `composer test:integration -- --filter LedgerWindowTotalTest` → OK;
   - `composer test:integration -- --filter TierEvaluatorTest` → OK, slice 2's cases included.
2. On wp-env, spec AC 1, 2, 3, 4, 6, 13, 14 and 15 pass by the checks in Task 2 Step 5, Task 4 Step 8 and Task 5 Step 5.

### Task 1: Lite reads, the hold column and month arithmetic

**Done when:** `tier_held_from` exists on the dev site, `Customer` reads both hold columns, `set_tier_state()` accepts them, and the `Dates` helpers handle overflow.

**Files:**
- Modify: `includes/Database/Schema/CustomersTable.php`, `includes/Models/Customer.php`, `includes/Repositories/CustomerRepository.php`, `includes/Utils/Dates.php`, `includes/Enums/TierChangeReason.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: see the README contract, PHP, Lite: the column, the `Customer` getters, `TIER_COLUMNS`, `hold_ended_user_ids()`, the `Dates` helpers and `MODE_CHANGE`.

- [ ] **Step 1: Add `tier_held_from` to `CustomersTable`**

Add `tier_held_from datetime DEFAULT NULL,` after `tier_held_until` in `definition()`, and `'tier_held_from'` after `'tier_held_until'` in `expected_columns()`. Add no key. In the class docblock, add: "The rolling hold runs from tier_held_from to tier_held_until; only the tier evaluator writes them." Follow the dbDelta rules in `AbstractTableSchema`'s docblock.

- [ ] **Step 2: Map both hold columns in `Customer`**

Add the constructor tail `?string $tier_held_until = null, ?string $tier_held_from = null`, fill it in `from_row()` with `self::cast_datetime()`, and add the getters. Leave `to_row()`, `get_format()` and `to_array()` unchanged.

- [ ] **Step 3: Extend `CustomerRepository`**

- `TIER_COLUMNS` gains `'tier_held_until' => '%s'` and `'tier_held_from' => '%s'`.
- Add `hold_ended_user_ids( string $now, int $limit ): int[]`: `WHERE tier_id IS NOT NULL AND tier_held_until IS NOT NULL AND tier_held_until <= %s ORDER BY tier_held_until, user_id LIMIT %d`, written like `floor_ended_user_ids()`.

- [ ] **Step 4: Add the `Dates` helpers and the reason**

- `Dates::add_months( string $utc, int $months ): string`: `( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->modify( sprintf( '%+d months', $months ) )`, formatted with `MYSQL_FORMAT`.
- `Dates::whole_months_between( string $from_utc, string $to_utc ): int`: `y × 12 + m` of `diff()`, or 0 when `$to_utc` ≤ `$from_utc`.
- `TierChangeReason::MODE_CHANGE = 'mode_change'`, with the docblock "Tier qualification switched from rolling to lifetime.", added to `all()`. Update `HOLD_END`'s docblock to say it is written.

- [ ] **Step 5: Install the column and verify**

Run: `$WP option update yayloyalty_db_version 0`, load http://localhost:8889/wp-admin/ once, then `$WP eval 'var_export( \YayLoyalty\Database\Installer::verify() ); echo " ", \YayLoyalty\Utils\Dates::add_months("2026-08-31 10:00:00", 6), " ", \YayLoyalty\Utils\Dates::whole_months_between("2026-08-31 10:00:00", "2027-03-03 10:00:00"), " ", \YayLoyalty\Utils\Dates::add_months("2027-01-10 00:00:00", -6), " ", \YayLoyalty\Utils\Dates::whole_months_between("2027-01-10 00:00:00", "2026-01-10 00:00:00"), " ", wp_json_encode((new \YayLoyalty\Repositories\CustomerRepository())->hold_ended_user_ids(\YayLoyalty\Utils\Dates::now(), 100));'`
Expected: `Installer::verify()`'s passing result (as slice 3's check printed), then `2027-03-03 10:00:00 6 2026-07-10 00:00:00 0 []`.

- [ ] **Step 6: Lint**

Run: `composer lint -- includes/Database/Schema/CustomersTable.php includes/Models/Customer.php includes/Repositories/CustomerRepository.php includes/Utils/Dates.php includes/Enums/TierChangeReason.php`
Expected: no error or warning in these files.

- [ ] **Step 7: Commit**

Stage: the files in this task's Files list.
Message: `feat(tiers): add the hold start column and month arithmetic for rolling tiers`

### Task 2: The window total with clawback attribution

**Done when:** `window_total()` dates every clawback by its earn, `sum_refunded_for()` uses the same match rule with unchanged results, and the accepted integration test passes.

**Files:**
- Modify: `includes/Repositories/LedgerRepository.php`
- Test: `tests/integration/Repositories/LedgerWindowTotalTest.php` (new)

**Interfaces:**
- Consumes: nothing new.
- Produces: `window_total()` and `staff_adjust_since()` (README contract).

The match rule, written once as `private function clawback_match_sql( string $claw, string $earn ): string`. It returns a predicate over two aliases of the ledger table, with the type literals taken from `LedgerType` and `ReferenceType` constants through `esc_sql()`:

```
( {claw}.type = 'refund_earn' OR ( {claw}.type = 'adjust' AND {claw}.reference_type = 'refund' ) )
AND {earn}.type = 'earn' AND {claw}.user_id = {earn}.user_id
AND (
  ( {earn}.order_id IS NOT NULL AND {claw}.order_id = {earn}.order_id AND {claw}.campaign_id <=> {earn}.campaign_id )
  OR ( {earn}.order_id IS NULL AND {claw}.reference_type = {earn}.reference_type AND {claw}.reference_id = {earn}.reference_id )
)
```

- [ ] **Step 1: Write the failing test** `LedgerWindowTotalTest`

Use the fixtures `TierEvaluatorTest` uses (`customer()`, `earn()`), write rows through `LedgerService`, and backdate with `$wpdb->update`. Window = [`2026-05-05 00:00:00`, `2026-11-05 00:00:00`].

```php
public function test_refund_of_an_old_earn_counts_in_the_earn_window(): void {
    // EARN 900 on order A backdated to 2026-03-01; EARN 100 on order B at 2026-10-01.
    // refund_earn( A's row, 900, 'refund', <refund id> ) → a −900 REFUND_EARN dated 2026-11-01.
    $this->assertSame( 100, $this->ledger->window_total( $user, '2026-05-05 00:00:00', '2026-11-05 00:00:00' ) );
    $this->assertSame( 0, $this->ledger->window_total( $user, '2026-02-01 00:00:00', '2026-04-01 00:00:00' ) ); // 900 − 900
}

public function test_restore_adjust_follows_its_earn(): void {
    // As above, then restore_refund( the −900 row, <refund id> ) → +900 ADJUST reference 'refund' dated now.
    $this->assertSame( 100, $this->ledger->window_total( $user, '2026-05-05 00:00:00', '2026-11-05 00:00:00' ) );
    $this->assertSame( 900, $this->ledger->window_total( $user, '2026-02-01 00:00:00', '2026-04-01 00:00:00' ) );
}

public function test_each_trigger_of_an_order_takes_its_own_earn_date(): void {
    // Order C: EARN 300 campaign X backdated 2026-03-01, EARN 50 campaign Y at 2026-10-01; claw back 300 of X and 50 of Y.
    $this->assertSame( 0, $this->ledger->window_total( $user, '2026-05-05 00:00:00', '2026-11-05 00:00:00' ) ); // 50 − 50
    $this->assertSame( 0, $this->ledger->window_total( $user, '2026-02-01 00:00:00', '2026-04-01 00:00:00' ) ); // 300 − 300
}

public function test_review_clawback_follows_its_earn(): void {
    // EARN 20 reference comment 77 backdated 2026-03-01; refund_earn( it, 20, 'comment', 77 ) dated now.
    $this->assertSame( 0, $this->ledger->window_total( $user, '2026-05-05 00:00:00', '2026-11-05 00:00:00' ) );
}

public function test_unmatched_clawback_counts_on_its_own_date(): void {
    // A REFUND_EARN −40 inserted directly with order_id 999999 (no earn), created 2026-10-01.
    $this->assertSame( -40, $this->ledger->window_total( $user, '2026-05-05 00:00:00', '2026-11-05 00:00:00' ) );
}

public function test_staff_adjust_since_skips_refund_restores(): void {
    // ADJUST −100 (staff) at 2026-10-02, ADJUST +30 (staff) at 2026-10-03, restore ADJUST +900 reference 'refund' at 2026-10-04.
    $this->assertSame( -70, $this->ledger->staff_adjust_since( $user, '2026-10-01 00:00:00' ) );
    $this->assertSame( 30, $this->ledger->staff_adjust_since( $user, '2026-10-02 00:00:00' ) ); // exclusive bound
}

public function test_sum_refunded_for_is_unchanged(): void {
    // EARN 200 on order D; refund_earn( earn, 50, 'refund', r1 ) then refund_earn( earn, 100, 'refund', r2 ).
    $this->assertSame( 100, $this->ledger->sum_refunded_for( $earn ) );
    // restore_refund( the r2 row, r2 ) → +50 ADJUST.
    $this->assertSame( 50, $this->ledger->sum_refunded_for( $earn, false ) );
    // Review earn E 20 reference comment 78, clawed back 20.
    $this->assertSame( 20, $this->ledger->sum_refunded_for( $review_earn ) );
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter LedgerWindowTotalTest`
Expected: FAIL, `window_total()` undefined.

- [ ] **Step 3: Implement in `LedgerRepository`**

- `clawback_match_sql()` as above.
- `window_total( int $user_id, string $from, string $to ): int`: the user's `lifetime_earned_types()` rows `r`, summed where `COALESCE( CASE WHEN <r is a clawback> THEN ( SELECT MIN( e.created_at ) FROM <ledger> e WHERE <clawback_match_sql( 'r', 'e' )> ) END, r.created_at ) BETWEEN %s AND %s`. 0 for `$user_id < 1`. No lock: it's read after the ledger commit, inside the evaluator's own transaction.
- `staff_adjust_since( int $user_id, string $after ): int`: `user_id = %d AND type = 'adjust' AND ( reference_type IS NULL OR reference_type <> 'refund' ) AND created_at > %s`, through `sum_points()`.
- `sum_refunded_for( LedgerEntry $earn, bool $is_locking = true ): int`: rewrite it as `SELECT COALESCE( SUM( c.points ), 0 ) FROM <ledger> c JOIN <ledger> e ON <clawback_match_sql( 'c', 'e' )> WHERE e.id = %d` (plus `LOCK IN SHARE MODE` when locking), returning `max( 0, -sum )`. Keep its docblock's meaning, and add that the match rule is shared with `window_total()`. An unsaved `$earn` (null id) returns 0.

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'LedgerWindowTotalTest|LedgerRefundRedeemTest|TierEvaluatorTest'`
Expected: PASS, with every existing case still green.

- [ ] **Step 5: Verify a real refund on wp-env**

Create a customer, complete a 200-unit order, then refund 50 and refund 50 again in WooCommerce → Orders.
Expected: `$WP db query "SELECT type, points FROM wp_yayloyalty_ledger WHERE order_id=<id> ORDER BY id"` lists `earn 200`, `refund_earn -50` and `refund_earn -50` (spec AC 15).

- [ ] **Step 6: Lint**

Run: `composer lint -- includes/Repositories/LedgerRepository.php tests/integration/Repositories/LedgerWindowTotalTest.php`
Expected: no error or warning in these files.

- [ ] **Step 7: Commit**

Stage: `includes/Repositories/LedgerRepository.php`, `tests/integration/Repositories/LedgerWindowTotalTest.php`
Message: `feat(ledger): sum points earned in a window, dating clawbacks by their earn`

### Task 3: `TierDecision::rolling_move()`

**Done when:** the pure Rolling rule returns the spec's move and hold action for every case, as the accepted unit test proves.

**Files:**
- Modify: `includes/Pro/Services/TierDecision.php`
- Test: `tests/unit/Pro/TierDecisionTest.php`

**Interfaces:**
- Consumes: `Tier`, and the existing `move()`.
- Produces: `rolling_move()` and its constants (README contract).

The rule. Ranks count a null tier as 0. "Best" is the highest-ranked of the candidates, and on a tie the precedence is floor, then window, then qualifying.

| `$kind` | Result tier | `hold` |
|---|---|---|
| `up` | best of (window, floor), if it outranks current; otherwise no change | an upgrade from the window → `start`; an upgrade from the floor → `clear`; no change → `keep` |
| `down`, `$hold_active` false | same as `to_target` | same as `to_target` |
| `down`, `$hold_active` true | no change if the window's or the qualifying tier's rank ≥ current rank (`keep`). Otherwise the best of (floor, window, qualifying) | the window wins → `start`; the qualifying tier wins → `keep`; the floor wins → `clear`; none → `clear` |
| `to_target` | best of (window, floor), up or down | the window wins → `start` (also when unchanged); the floor wins → `clear`; none → `clear` |
| `hold_only` | never changes | current not null and the floor doesn't win over the window (floor rank < window rank, or no floor) → `start`; otherwise `clear` |

`is_upgrade` is computed as in `move()`.

- [ ] **Step 1: Write the failing tests** in `TierDecisionTest`, with the existing `live()`/`tier()` helpers (Bronze 0 rank 1, Silver 800 rank 2, Gold 3000 rank 3)

```php
public function test_rolling_up_starts_a_hold_from_the_window(): void {
    $r = TierDecision::rolling_move( $bronze_id, 1, $silver, null, null, false, TierDecision::KIND_UP );
    $this->assertSame( [ true, $silver_id, true, TierDecision::HOLD_START ], [ $r['changed'], $r['to']->get_id(), $r['is_upgrade'], $r['hold'] ] );
}

public function test_rolling_up_to_the_floor_clears_the_hold(): void {
    $r = TierDecision::rolling_move( $bronze_id, 1, $bronze, null, $gold, false, TierDecision::KIND_UP );
    $this->assertSame( [ $gold_id, TierDecision::HOLD_CLEAR ], [ $r['to']->get_id(), $r['hold'] ] );
}

public function test_rolling_up_without_upgrade_keeps(): void {
    $r = TierDecision::rolling_move( $gold_id, 3, $silver, null, null, true, TierDecision::KIND_UP );
    $this->assertSame( [ false, TierDecision::HOLD_KEEP ], [ $r['changed'], $r['hold'] ] );
}

public function test_rolling_down_in_hold_keeps_while_qualifying_reaches(): void {
    $r = TierDecision::rolling_move( $gold_id, 3, $bronze, $gold, null, true, TierDecision::KIND_DOWN );
    $this->assertSame( [ false, TierDecision::HOLD_KEEP ], [ $r['changed'], $r['hold'] ] );
}

public function test_rolling_down_in_hold_drops_to_qualifying_and_keeps_dates(): void {
    $r = TierDecision::rolling_move( $gold_id, 3, $bronze, $silver, null, true, TierDecision::KIND_DOWN );
    $this->assertSame( [ $silver_id, TierDecision::HOLD_KEEP ], [ $r['to']->get_id(), $r['hold'] ] );
}

public function test_rolling_down_in_hold_window_tie_restarts(): void {
    $r = TierDecision::rolling_move( $gold_id, 3, $silver, $silver, null, true, TierDecision::KIND_DOWN );
    $this->assertSame( [ $silver_id, TierDecision::HOLD_START ], [ $r['to']->get_id(), $r['hold'] ] );
}

public function test_rolling_down_without_hold_goes_to_window(): void {
    $r = TierDecision::rolling_move( $gold_id, 3, null, null, null, false, TierDecision::KIND_DOWN );
    $this->assertSame( [ true, null, TierDecision::HOLD_CLEAR ], [ $r['changed'], $r['to'], $r['hold'] ] );
}

public function test_rolling_to_target_renews_unchanged(): void {
    $r = TierDecision::rolling_move( $silver_id, 2, $silver, null, null, false, TierDecision::KIND_TO_TARGET );
    $this->assertSame( [ false, TierDecision::HOLD_START ], [ $r['changed'], $r['hold'] ] );
}

public function test_rolling_to_target_skips_several_tiers(): void {
    $r = TierDecision::rolling_move( $gold_id, 3, $bronze, null, null, false, TierDecision::KIND_TO_TARGET );
    $this->assertSame( [ $bronze_id, false, TierDecision::HOLD_START ], [ $r['to']->get_id(), $r['is_upgrade'], $r['hold'] ] );
}

public function test_rolling_to_target_floor_tie_clears(): void {
    $r = TierDecision::rolling_move( $silver_id, 2, $silver, null, $silver, false, TierDecision::KIND_TO_TARGET );
    $this->assertSame( [ false, TierDecision::HOLD_CLEAR ], [ $r['changed'], $r['hold'] ] );
}

public function test_rolling_hold_only(): void {
    $this->assertSame( TierDecision::HOLD_START, TierDecision::rolling_move( $silver_id, 2, $bronze, null, null, false, TierDecision::KIND_HOLD_ONLY )['hold'] );
    $this->assertSame( TierDecision::HOLD_CLEAR, TierDecision::rolling_move( $gold_id, 3, $bronze, null, $gold, false, TierDecision::KIND_HOLD_ONLY )['hold'] );
    $this->assertSame( TierDecision::HOLD_CLEAR, TierDecision::rolling_move( null, 0, null, null, null, false, TierDecision::KIND_HOLD_ONLY )['hold'] );
    $this->assertFalse( TierDecision::rolling_move( $silver_id, 2, $gold, null, null, false, TierDecision::KIND_HOLD_ONLY )['changed'] );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test -- --filter TierDecisionTest`
Expected: FAIL, `rolling_move` undefined.

- [ ] **Step 3: Implement `rolling_move()` and the constants in `TierDecision`**

The class docblock now says "The tier move rules, in Lifetime and Rolling mode".

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `composer test -- --filter TierDecisionTest`
Expected: PASS, slice 2's cases included.

- [ ] **Step 5: Lint**

Run: `composer lint -- includes/Pro/Services/TierDecision.php tests/unit/Pro/TierDecisionTest.php`
Expected: no error or warning in these files.

- [ ] **Step 6: Commit**

Stage: `includes/Pro/Services/TierDecision.php`, `tests/unit/Pro/TierDecisionTest.php`
Message: `feat(tiers): decide rolling tier moves and holds`

### Task 4: `TierEvaluator` in Rolling, and the hold-end step

**Done when:** in Rolling, every trigger writes the spec's tier and hold columns, `TierRunner` ends holds daily, Lifetime is unchanged, and the accepted integration test passes.

**Files:**
- Modify: `includes/Pro/Services/TierEvaluator.php`, `includes/Pro/Services/TierRunner.php`
- Test: `tests/integration/Services/TierEvaluatorTest.php`

**Interfaces:**
- Consumes: Task 1 (`Customer` getters, `TIER_COLUMNS`, `hold_ended_user_ids()`, `Dates`), Task 2 (`window_total()`, `staff_adjust_since()`), Task 3 (`rolling_move()`).
- Produces: `TierEvaluator::mode()`, `end_hold()`, and `sweep_one()` accepting the mode sweeps (README contract). The `TierSweep` constants come from Task 5; this task references them as literals through `TierSweep::MODE_TO_ROLLING`/`MODE_TO_LIFETIME`, added to `TierSweep` in Step 3 here with no other `TierSweep` change.

The evaluator in Rolling, inside `apply()` under the lock:
- `$n` = `tiers_rolling_months`, `$now` = `Dates::now()`.
- `$window` = `window_total( $user_id, Dates::add_months( $now, -$n ), $now )`. The window tier is `TierDecision::target( $live, $window, null )`.
- `$hold_active` = `tier_id` not null and `tier_held_until` > `$now`.
- **When the hold is active:**
  - `L` = `whole_months_between( held_from, held_until )`, or `$n` when `held_from` is null or L is 0. `held_from` null reads as `add_months( held_until, -$n )`.
  - The qualifying total is `window_total( $user_id, add_months( held_from, -L ), held_from ) + staff_adjust_since( $user_id, held_from )`.
  - The qualifying tier is `target( $live, qualifying, null )`.
- The floor comes from slice 2's rule.
- The result comes from `rolling_move()`. Hold `start` writes `tier_held_from` = `$now` and `tier_held_until` = `add_months( $now, $n )`; `clear` writes both NULL; `keep` writes neither.
- `tier_progress_value` = `$window` + the bonus paid. The tier change, activity, bonus and announce steps are slice 2's.

Plan kinds per entry point. The `TierLedgerListener` → `on_ledger_entry()` policy is reused: `MOVE_UP` → `up`, and `MOVE_TO_TARGET` → `down`.

| Entry point | Lifetime | Rolling `$kind` | reason |
|---|---|---|---|
| `on_ledger_entry()` | unchanged | `up` / `down` | unchanged |
| `sweep_one( placement )` | unchanged | `to_target` | `placement` |
| `sweep_one( reevaluate )`, a tier or floor deleted | unchanged | `to_target` (floor cleared) | `tier_deleted` |
| `sweep_one( reevaluate )`, otherwise | unchanged | `up` | `threshold_change` |
| `assign_floor()` | unchanged | `up` with the new floor | `manual` |
| `clear_floor()` | unchanged | `to_target` (floor cleared) | `manual_end` |
| `end_hold()` [new] | returns without writing | `to_target` | `hold_end` |
| `sweep_one( to_rolling )` | — | the deleted-tier case as `reevaluate`; otherwise `hold_only` | `tier_deleted` |
| `sweep_one( to_lifetime )` | the Lifetime `reevaluate` rules (deleted → to target, `tier_deleted`; otherwise `MOVE_UP`, reason `mode_change`), plus `tier_held_from` and `tier_held_until` NULL | — | `mode_change` |

`to_rolling` and `to_lifetime` run the path the table names whatever `tiers_mode` holds. Every other entry point reads `mode()`.

- [ ] **Step 1: Write the failing tests** in `TierEvaluatorTest`

Add a `with_rolling( int $months, callable $body )` helper that sets `tiers_mode` `rolling` and `tiers_rolling_months` through `SettingsHelper::update_settings()`, then restores Lifetime. Add a `hold_of( int $user_id ): array` helper returning `[tier_held_from, tier_held_until]`. Backdating goes through `$wpdb->update` on the ledger and customers tables.

```php
public function test_rolling_upgrade_starts_a_hold(): void {
    // Rolling 6. Customer: 2,000 lifetime, 1,220 of it backdated 8 months, 780 in the window; earn 30.
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    [ $from, $until ] = $this->hold_of( $user );
    $this->assertSame( Dates::add_months( $from, 6 ), $until );
    $this->assertSame( '810.0000', $this->progress_of( $user ) );
}

public function test_rolling_refund_in_qualifying_window_drops(): void {
    // Rolling 6. Earn 3,000 on order A → Gold with a hold; refund_earn( A, 1,500 ).
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    $this->assertSame( 'refund', $this->last_change( $user, [ 'reason' ] )['reason'] );
}

public function test_rolling_refund_after_hold_start_keeps(): void {
    // Rolling 6. Gold hold with held_from = now − 1 month; qualifying earns backdated before held_from; window decayed to 200.
    // Earn 100 on order B (after held_from), then refund_earn( B, 100 ).
    $this->assertSame( 'Gold', $this->tier_of( $user ) );
    $this->assertSame( $hold_before, $this->hold_of( $user ) );
}

public function test_rolling_staff_deduction_counts_against_the_hold(): void {
    // Gold hold, qualifying total 3,200, window 500. ADJUST −100 → Gold; ADJUST −200 → Silver, hold dates unchanged.
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    $this->assertSame( $hold_before, $this->hold_of( $user ) );
}

public function test_hold_end_moves_and_renews(): void {
    // Customer 1: Gold, held_until in the past, window 900 → end_hold → Silver, reason hold_end, new hold from now.
    // Customer 2: Silver, held_until in the past, window 1,000 → end_hold → Silver, hold renewed, no new tier_changed row.
    // Customer 3: Gold, held_until in the past, window 0 → Bronze (threshold 0) with a new hold.
    // Customer 4: Bronze's threshold set to 100; Gold, held_until in the past, window 0 → tier NULL, both hold columns NULL.
}

public function test_floor_replaces_hold_and_its_end_starts_one(): void {
    // Silver with a hold; assign_floor( Gold, 30 ) → Gold, both hold columns NULL.
    // tier_manual_until set to the past; clear_floor( user, null ) → Silver (window 900), reason manual_end, hold from now.
}

public function test_months_change_keeps_the_hold_and_its_length(): void {
    // Rolling 12: Gold hold held_from = now − 2 months, held_until = held_from + 12 months; qualifying earn 3,000 backdated
    // to held_from − 10 months. Switch the setting to 6. A −10 ADJUST → still Gold (L = 12 reaches the earn), hold columns unchanged.
}

public function test_bad_hold_dates_use_the_setting(): void {
    // Gold hold with tier_held_from NULL and held_until in the future; a −10 ADJUST is evaluated without error, using L = 6.
}

public function test_mode_sweeps(): void {
    // A: Silver, no floor; B: Gold with a Gold floor. sweep_one( to_rolling ) each →
    //   A keeps Silver with a hold from now; B keeps Gold with both hold columns NULL; no tier_changed rows.
    // C: Silver with 3,500 lifetime and a hold. sweep_one( to_lifetime ) → Gold, reason mode_change, both hold columns NULL.
}

public function test_lifetime_writes_no_hold(): void {
    // Lifetime: earn 30 at 780 → Silver; both hold columns NULL.
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter TierEvaluatorTest`
Expected: the new cases FAIL, and slice 2's cases pass.

- [ ] **Step 3: Implement the Rolling path in `TierEvaluator`, and add `TierSweep::MODE_TO_ROLLING`/`MODE_TO_LIFETIME` as constants only**

Per the tables above. Extend `plan()` with `kind` (the Rolling kind) and `clear_hold` (true only for `to_lifetime`). Add `public static function mode(): string`. The class docblock now says it covers both modes. No method outside this class writes the hold columns.

- [ ] **Step 4: Add the hold-end step to `TierRunner::run()`**

Between ending floors and the sweep batch, only when `TierEvaluator::mode()` is `rolling`: take `hold_ended_user_ids( Dates::now(), self::BATCH_SIZE )`, call `end_hold()` on each in `guarded()`, and let a full batch set `$has_more`. Update the class docblock.

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter TierEvaluatorTest`
Expected: PASS, every case counted.

- [ ] **Step 6: Run the unit suite**

Run: `composer test`
Expected: OK.

- [ ] **Step 7: Lint**

Run: `composer lint -- includes/Pro/Services/TierEvaluator.php includes/Pro/Services/TierRunner.php includes/Pro/Services/TierSweep.php tests/integration/Services/TierEvaluatorTest.php`
Expected: no error or warning in these files.

- [ ] **Step 8: Verify the hold end on wp-env** (spec AC 3, 4, 6)

Set Rolling 6 with `$WP eval '\YayLoyalty\Helpers\SettingsHelper::update_settings(["tiers_mode"=>"rolling","tiers_rolling_months"=>6]);'` (the route lands in Task 5).
- Seed customer C at 780 with an ADJUST (lifetime), then complete a 30-unit order. Expected: `$WP db query "SELECT tier_id, tier_progress_value, tier_held_from, tier_held_until FROM wp_yayloyalty_customers WHERE user_id=<C>"` shows Silver's id, `810.0000`, and held_until = held_from + 6 months.
- Set `tier_held_until` to `2026-01-01 00:00:00` and run `RUN` once. Expected: C stays Silver with a new hold from now (renewal), and `REST GET /customers/<C>/activity?group=tiers` has no new row.
- Backdate C's earn rows 8 months (`BACKDATE`), set `tier_held_until` to the past again, and `RUN`. Expected: Bronze's id with a new hold from now (window 0 reaches Bronze's threshold 0), and one new `tier_changed` row Silver → Bronze with reason `hold_end`.

- [ ] **Step 9: Commit**

Stage: `includes/Pro/Services/TierEvaluator.php`, `includes/Pro/Services/TierRunner.php`, `includes/Pro/Services/TierSweep.php`, `tests/integration/Services/TierEvaluatorTest.php`
Message: `feat(tiers): evaluate tiers on a rolling window with holds`

### Task 5: Mode sweeps and `PUT /tiers/settings`

**Done when:** the route switches mode and months per the spec, a mode change runs its sweep to completion through `RUN`, and a pending sweep refuses a mode change.

**Files:**
- Modify: `includes/Pro/Services/TierSweep.php`, `includes/Pro/Controllers/TiersRestController.php`, `apps/admin/src/lib/schema/tier.schema.ts`

**Interfaces:**
- Consumes: Task 4 (`sweep_one()` for the mode sweeps).
- Produces: `TierSweep::is_pending()`, the merge order, and the route (README contract), plus `tierSettingsSchema.sweep.mode` with four values.

- [ ] **Step 1: Extend `TierSweep`**

- `current()` accepts all four modes.
- `request()` merges: stored or requested `placement` → `placement`; otherwise stored or requested `to_rolling`/`to_lifetime` → that mode (when both are mode sweeps, the requested one); otherwise `reevaluate`.
- `is_pending(): bool` = `null !== current()`.
- Update the class docblock, saying a mode sweep also does re-evaluation's work.

- [ ] **Step 2: Rework `TiersRestController::update_settings()` and its args**

- **Args:** all optional.
  - `tiers_enabled` boolean;
  - `tiers_mode` string, `enum` `[ 'lifetime', 'rolling' ]`;
  - `tiers_rolling_months` integer, `enum` `[ 3, 6, 9, 12, 18, 24 ]`.
- **In the callback, in order:**
  1. None of the three present → `error_invalid_arguments( __( 'Send at least one tier setting.', 'yayloyalty' ) )`.
  2. `tiers_mode` present, differs from the stored one, and `TierSweep::is_pending()` → `new \WP_Error( 'yayloyalty_tier_sweep_running', __( 'Wait for the current tier update to finish.', 'yayloyalty' ), [ 'status' => 409 ] )`.
  3. One `SettingsHelper::update_settings()` call with the present keys.
  4. Then:
     - off → on: `request( placement )`;
     - on → off: `cancel()`;
     - otherwise, while on and the mode changed: `request( MODE_TO_ROLLING )` or `request( MODE_TO_LIFETIME )`.
     - A months-only change requests nothing.
  5. The response pre-reports the requested sweep (mode, `done` 0, `total` = `customer_count`), generalising today's placement-only branch.
- **Docblock:** replace "Only `tiers_enabled` is writable until rolling mode exists."

- [ ] **Step 3: Widen the SPA's sweep mode enum**

In `tierSettingsSchema`, `sweep.mode` becomes `z.enum(['placement', 'reevaluate', 'to_rolling', 'to_lifetime'])`. Update its comment.

- [ ] **Step 4: Verify the Tiers page during a mode sweep**

With a mode sweep pending (Step 5's `to_rolling` request before `RUN`), open http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers.
Expected: the page renders with the banner "Updating customer tiers… 0 of N", and the console has no zod error.

- [ ] **Step 5: Verify the route** (spec AC 1, 2, 13)

- `REST PUT /tiers/settings '{}'` → `[400, …]`. `'{"tiers_rolling_months":5}'` → 400. `'{"tiers_mode":"yearly"}'` → 400.
- In Lifetime, with customer A Silver (900 lifetime, 100 of it in the last 6 months after `BACKDATE`) and customer B Gold with a Gold floor:
  - `REST PUT /tiers/settings '{"tiers_mode":"rolling"}'` → `[200, {…"sweep":{"mode":"to_rolling","done":0,…}}]`.
  - At once, `'{"tiers_mode":"lifetime"}'` → `[409, …]` with code `yayloyalty_tier_sweep_running`.
  - `'{"tiers_rolling_months":12}'` → 200, then `'{"tiers_rolling_months":6}'` → 200.
- `RUN`. Expected: `"sweep":null`; A Silver with held_until = held_from + 6 months and `tier_progress_value` `100.0000`; B Gold with both hold columns NULL; no new `tier_changed` rows.
- **Placement in Rolling** (spec AC 14):
  - `REST PUT /tiers/settings '{"tiers_enabled":false}'`, then `'{"tiers_mode":"rolling"}'` → 200 with `"sweep":null`.
  - Seed customer M at 5,000 lifetime, with all but 900 backdated 8 months.
  - `'{"tiers_enabled":true}'` → `sweep.mode` `placement`, then `RUN`. Expected: M Silver (reason `placement`), with held_until = held_from + 6 months, and no `tier_bonus` row for M.
- Seed customer K with 3,500 lifetime, then backdate their rows 8 months.
  - `$WP eval 'do_action("yayloyalty_tier_run");'` with K's hold set to the past moves K to Bronze (`hold_end`).
  - `REST PUT /tiers/settings '{"tiers_mode":"lifetime"}'`, then `RUN`. Expected: K Gold with reason `mode_change`, and both hold columns NULL for every customer (`$WP db query "SELECT COUNT(*) FROM wp_yayloyalty_customers WHERE tier_held_until IS NOT NULL OR tier_held_from IS NOT NULL"` → 0).

- [ ] **Step 6: Lint and type-check**

Run: `composer lint -- includes/Pro/Services/TierSweep.php includes/Pro/Controllers/TiersRestController.php`, `pnpm --filter @yayloyalty/admin-page exec eslint src/lib/schema/tier.schema.ts` and `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: no error or warning in these files; tsc exits 0.

- [ ] **Step 7: Commit**

Stage: `includes/Pro/Services/TierSweep.php`, `includes/Pro/Controllers/TiersRestController.php`, `apps/admin/src/lib/schema/tier.schema.ts`
Message: `feat(tiers): switch tier qualification mode and months through the settings route`
