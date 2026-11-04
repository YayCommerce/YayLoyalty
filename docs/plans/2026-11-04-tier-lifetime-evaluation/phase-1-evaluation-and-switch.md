# Phase 1: Evaluation, sweeps and the switch

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** slice 1 (`docs/plans/2026-11-03-tier-catalogue.md`), already on `jason/feat/tiers`.
**Delivers:**
- The Tiers page's "Enable tiers" switch, with its confirms and the progress banner.
- Turning tiers on places every customer, and each ledger write moves them per the spec's Evaluation rules.
- Upgrades pay the entry bonus once per tier.
- Tier create, threshold change and delete re-evaluate customers.

**Exit criterion:**
1. The tests pass:
   - `composer test -- --filter TierDecisionTest` → OK;
   - `composer test:integration -- --filter TierEvaluatorTest` → OK, with the tests counted in the summary line.
2. On wp-env, open http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers and turn "Enable tiers" on, then confirm. The banner "Updating customer tiers… X of N" shows. After `RUN`, the toast "Customer tiers updated." shows and the member counts are non-zero.

### Task 1: Lite vocabulary, read model and repository methods

**Done when:** the new constants compose a bonus key, `Customer` exposes the tier columns read-only, and `CustomerRepository` has the tier reads and the one tier write.

**Files:**
- Create: `includes/Enums/TierChangeReason.php`
- Modify: `includes/Enums/LedgerSource.php`, `includes/Enums/ReferenceType.php`, `includes/Engine/Earning/IdempotencyKey.php`, `includes/Services/CustomerActivityService.php`, `includes/Models/Customer.php`, `includes/Repositories/CustomerRepository.php`, `includes/Repositories/TierRepository.php`

**Interfaces:**
- Consumes: slice 1's `Tier` and `TierRepository`.
- Produces: see the README contract, PHP, Lite: `LedgerSource::TIER_BONUS`, `ReferenceType::TIER`, `IdempotencyKey::TIER_BONUS`, `TierChangeReason`, `record_tier_changed()`, the `Customer` getters, the five `CustomerRepository` methods, and `TierRepository::find_many()`.

- [ ] **Step 1: Map the tier columns read-only in `Models\Customer`**

Add six optional constructor params after `$birthday_day`, in column order: `?int $tier_id`, `?string $tier_evaluated_at`, `?string $tier_progress_value`, `?string $tier_achieved_at`, `?int $tier_manual_id`, `?string $tier_manual_until`. Fill them in `from_row()`; `tier_progress_value` keeps its decimal string. Add the six getters.

Leave `to_row()`, `get_format()` and `to_array()` unchanged. The docblock says the tier columns are read-only here, because `TierEvaluator` writes them through `CustomerRepository::set_tier_state()`.

- [ ] **Step 2: Add the enum constants and `TierChangeReason`**

- `LedgerSource::TIER_BONUS = 'tier_bonus'`, added to `all()`.
- `ReferenceType::TIER = 'tier'`, added to its `all()`.
- `TierChangeReason`: the constants in the README contract, with `use StringEnumMethods` and `noun()` 'tier change reason'.

- [ ] **Step 3: Accept the bonus qualifier in `IdempotencyKey`**

Add `public const TIER_BONUS = 'tier_bonus';`. In `compose()`, a `TIER_BONUS` qualifier is valid only when `$type` is `LedgerType::EARN` and `$reference_type` is `ReferenceType::TIER`. Add that branch to `is_earn_qualifier()`, whose signature gains `string $reference_type`. Update the `$qualifier` param docblock.

- [ ] **Step 4: Add `CustomerActivityService::record_tier_changed()`** (signature in the README contract)

It inserts `CustomerMeta::event( $user_id, ActivityEvent::TIER_CHANGED, $data, $actor_kind, $actor_name, true, $at )`, with `$data` = `{from_tier_id, to_tier_id, from_name, to_name, reason, actor_id}`, and `[ $actor_kind, $actor_name ] = $this->actor( $actor_id )`, as `record_points_adjusted()` does. Update `ActivityEvent`'s class docblock: `tier_changed` is now written.

- [ ] **Step 5: Add the `CustomerRepository` methods and `TierRepository::find_many()`**

- `set_tier_state( int $user_id, array $columns ): void`
  - Accepts only the keys `tier_id`, `tier_evaluated_at`, `tier_progress_value`, `tier_achieved_at`, `tier_manual_id` and `tier_manual_until`; any other key throws `\InvalidArgumentException`.
  - A null value writes SQL NULL. Formats are `%d` for the ids and `%s` otherwise.
  - Docblock: the only tier-column write, called only by `Pro\Services\TierEvaluator`.
- `count_all(): int`.
- `user_ids_after( int $after_user_id, int $limit ): int[]`: `WHERE user_id > %d ORDER BY user_id ASC LIMIT %d`.
- `count_up_to( int $user_id ): int`: `WHERE user_id <= %d`.
- `floor_ended_user_ids( string $now, int $limit ): int[]`: `WHERE tier_manual_id IS NOT NULL AND tier_manual_until IS NOT NULL AND tier_manual_until <= %s ORDER BY user_id LIMIT %d`.
- `TierRepository::find_many( array $ids ): array`: `[]` for an empty input, `Sql::in_placeholders()`, deleted rows included, keyed by id.

- [ ] **Step 6: Verify**

Run: `$WP eval 'echo \YayLoyalty\Engine\Earning\IdempotencyKey::compose("earn", 7, "tier", 3, "tier_bonus"), " "; try { \YayLoyalty\Engine\Earning\IdempotencyKey::compose("earn", 7, "order", 3, "tier_bonus"); } catch (\InvalidArgumentException $e) { echo "rejected "; } $r = new \YayLoyalty\Repositories\CustomerRepository(); echo $r->count_all(), " ", count($r->user_ids_after(0, 100)), " ", wp_json_encode($r->find_by_user_id(1) ? $r->find_by_user_id(1)->get_tier_id() : "no-row");'`
Expected: `earn:7:tier:3:tier_bonus rejected <n> <min(n,100)> null` (or `"no-row"` when admin has no customer row).

Then: `$WP eval '(new \YayLoyalty\Repositories\CustomerRepository())->set_tier_state(1, ["balance" => 5]);'`. Expected: an `InvalidArgumentException` fatal naming the key.

- [ ] **Step 7: Lint**

Run: `composer lint -- includes/Enums/TierChangeReason.php includes/Enums/LedgerSource.php includes/Enums/ReferenceType.php includes/Engine/Earning/IdempotencyKey.php includes/Services/CustomerActivityService.php includes/Models/Customer.php includes/Repositories/CustomerRepository.php includes/Repositories/TierRepository.php`
Expected: no error or warning in these files.

- [ ] **Step 8: Commit**

Stage: the files in this task's Files list.
Message: `feat(tiers): add the tier vocabulary and the customer tier reads`

### Task 2: `TierDecision`, `TierEvaluator` and the ledger trigger

**Done when:** a ledger write moves the customer per the Evaluation rules and pays the bonus once, as both accepted tests prove.

**Files:**
- Create: `includes/Pro/Services/TierDecision.php`, `includes/Pro/Services/TierEvaluator.php`, `includes/Pro/Services/TierLedgerListener.php`
- Modify: `includes/Services/LedgerService.php` (docblock), `includes/Pro/YayLoyaltyPro.php`
- Test: `tests/unit/Pro/TierDecisionTest.php`, `tests/integration/Services/TierEvaluatorTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: `TierDecision`, `TierEvaluator`, and the action `yayloyalty_tier_changed` (README contract).

- [ ] **Step 1: Write the failing unit test `tests/unit/Pro/TierDecisionTest.php`**

```php
// Bronze 0 rank 1, Silver 800 rank 2, Gold 3000 rank 3; ids 1, 2, 3. tier() builds a live Tier.
public function test_target_is_highest_threshold_reached(): void {
    $this->assertSame( 2, TierDecision::target( $this->live(), 810, null )->get_id() );
    $this->assertSame( 2, TierDecision::target( $this->live(), 800, null )->get_id() );
    $this->assertSame( 1, TierDecision::target( $this->live(), 799, null )->get_id() );
}
public function test_target_is_null_below_lowest_threshold(): void {
    $live = [ $this->tier( 2, 800, 1 ), $this->tier( 3, 3000, 2 ) ];
    $this->assertNull( TierDecision::target( $live, 500, null ) );
}
public function test_floor_wins_when_it_outranks_qualify(): void {
    $this->assertSame( 3, TierDecision::target( $this->live(), 100, $this->tier( 3, 3000, 3 ) )->get_id() );
    $this->assertSame( 3, TierDecision::target( $this->live(), 3500, $this->tier( 2, 800, 2 ) )->get_id() );
}
public function test_ledger_policy(): void {
    $this->assertSame( [ 'move' => 'up', 'reason' => 'earn' ], TierDecision::ledger_policy( 'earn', 'order', 30 ) );
    $this->assertSame( [ 'move' => 'up', 'reason' => 'adjustment' ], TierDecision::ledger_policy( 'adjust', 'manual', 10 ) );
    $this->assertSame( [ 'move' => 'to_target', 'reason' => 'refund' ], TierDecision::ledger_policy( 'refund_earn', 'order', -30 ) );
    $this->assertSame( [ 'move' => 'to_target', 'reason' => 'adjustment' ], TierDecision::ledger_policy( 'adjust', 'manual', -10 ) );
    $this->assertSame( [ 'move' => 'up', 'reason' => 'refund' ], TierDecision::ledger_policy( 'refund_earn', 'order', 30 ) );
    $this->assertNull( TierDecision::ledger_policy( 'earn', 'tier_bonus', 100 ) );
    $this->assertNull( TierDecision::ledger_policy( 'redeem', 'order', -50 ) );
    $this->assertNull( TierDecision::ledger_policy( 'correction', 'manual', 50 ) );
    $this->assertNull( TierDecision::ledger_policy( 'expire', 'expiry', -50 ) );
}
public function test_up_only_never_lowers(): void {
    $this->assertFalse( TierDecision::move( 2, 2, $this->tier( 1, 0, 1 ), 'up' )['changed'] );
    $this->assertFalse( TierDecision::move( 2, 2, null, 'up' )['changed'] );
    $up = TierDecision::move( 1, 1, $this->tier( 2, 800, 2 ), 'up' );
    $this->assertSame( [ true, 2, true ], [ $up['changed'], $up['to']->get_id(), $up['is_upgrade'] ] );
}
public function test_to_target_lowers_and_clears(): void {
    $down = TierDecision::move( 2, 2, $this->tier( 1, 0, 1 ), 'to_target' );
    $this->assertSame( [ true, 1, false ], [ $down['changed'], $down['to']->get_id(), $down['is_upgrade'] ] );
    $none = TierDecision::move( 2, 2, null, 'to_target' );
    $this->assertSame( [ true, null, false ], [ $none['changed'], $none['to'], $none['is_upgrade'] ] );
    $this->assertFalse( TierDecision::move( 2, 2, $this->tier( 2, 800, 2 ), 'to_target' )['changed'] );
}
public function test_deleted_current_counts_as_rank_zero(): void {
    // Current id 9 is a deleted tier: rank 0, so the move to Bronze is a change and an upgrade by rank.
    $move = TierDecision::move( 9, 0, $this->tier( 1, 0, 1 ), 'to_target' );
    $this->assertSame( [ true, 1, true ], [ $move['changed'], $move['to']->get_id(), $move['is_upgrade'] ] );
}
public function test_null_current_to_null_target_is_no_change(): void {
    $this->assertFalse( TierDecision::move( null, 0, null, 'to_target' )['changed'] );
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `composer test -- --filter TierDecisionTest`
Expected: FAIL, class `YayLoyalty\Pro\Services\TierDecision` not found.

- [ ] **Step 3: Implement `TierDecision`** (signatures in the README contract)

It's pure: no WordPress calls, no repository. `ledger_policy()`:
- returns null for 0 points, for source `tier_bonus`, and for a type outside `LedgerType::affects_lifetime_earned()`;
- the move is `up` for points > 0 and `to_target` for points < 0;
- the reason is `earn` for EARN, `refund` for REFUND_EARN and `adjustment` for ADJUST.

`move()` returns `changed` = the resulting id differs from `$current_id`.

- [ ] **Step 4: Run the unit test to confirm it passes**

Run: `composer test -- --filter TierDecisionTest`
Expected: OK (8 tests).

- [ ] **Step 5: Write the failing integration test `tests/integration/Services/TierEvaluatorTest.php`**

Setup:
- skip when `YayLoyaltyPro` is absent, as `DailyEarningLimitTest` does;
- pin settings with `pre_option_yayloyalty_settings` to `tiers_enabled` true;
- seed tiers through `TierRepository::seed_defaults( Dates::now() )` and `rerank()`;
- create real users with `wp_insert_user()`, because the evaluator skips ids with no WordPress user;
- give points through `$this->ledger_service()->adjust( $user, $n, 'seed', 1 )`, whose recorded hook runs the listener after commit.

Helpers, private to the test:
- `customer(): int`: a new `wp_insert_user()` customer.
- `tier_id( string $name ): int`.
- `set_bonus( string $name, int $points )` and `set_threshold( string $name, int $points )`: write through `TierRepository`, then `rerank()`.
- `tier_of( int $user ): ?string`: the tier name via `customer_row()`.
- `bonus_rows( int $user ): array`: ledger rows with source `tier_bonus`.
- `changes( int $user ): array`: the decoded `tier_changed` meta values, oldest first.
- `last_change( int $user, string[] $keys ): array`: those keys' values from the newest change.
- `with_tiers_off( callable $fn )`: runs `$fn` with the pinned `tiers_enabled` false, then restores it.

```php
public function test_upgrade_pays_bonus_once_with_its_key(): void {
    $this->set_bonus( 'Silver', 100 );
    $user = $this->customer();
    $this->ledger_service()->adjust( $user, 780, 'seed', 1 );
    $this->earn( $user, 1001, 30 );                 // EARN row, order 1001
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    $bonus = $this->bonus_rows( $user );
    $this->assertCount( 1, $bonus );
    $this->assertSame( 100, (int) $bonus[0]['points'] );
    $this->assertSame( 'earn:' . $user . ':tier:' . $this->tier_id( 'Silver' ) . ':tier_bonus', $bonus[0]['idempotency_key'] );
    $this->assertSame( [ 'tier', $this->tier_id( 'Silver' ) ], [ $bonus[0]['reference_type'], (int) $bonus[0]['reference_id'] ] );
    $this->assertSame( 910, (int) $this->customer_row( $user )['lifetime_earned'] );
    $this->assertSame( [ 'Bronze', 'Silver', 'earn' ], $this->last_change( $user, [ 'from_name', 'to_name', 'reason' ] ) );
}
public function test_reaching_the_tier_again_pays_no_second_bonus(): void {
    $this->set_bonus( 'Silver', 100 );
    $user = $this->customer();
    $this->ledger_service()->adjust( $user, 900, 'seed', 1 );   // Silver + 100 bonus = 1000
    $this->ledger_service()->adjust( $user, -300, 'undo', 1 );  // 700 → Bronze
    $this->assertSame( 'Bronze', $this->tier_of( $user ) );
    $this->ledger_service()->adjust( $user, 200, 'again', 1 );  // 900 → Silver
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    $this->assertCount( 1, $this->bonus_rows( $user ) );
}
public function test_bonus_row_does_not_cascade(): void {
    $this->set_threshold( 'Gold', 850 );
    $this->set_bonus( 'Silver', 100 );
    $user = $this->customer();
    $this->ledger_service()->adjust( $user, 780, 'seed', 1 );
    $this->earn( $user, 1002, 30 );                  // 810 → Silver, bonus → 910 ≥ 850
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    $this->earn( $user, 1003, 1 );
    $this->assertSame( 'Gold', $this->tier_of( $user ) );
}
public function test_refund_downgrades_and_keeps_the_bonus(): void {
    $this->set_bonus( 'Silver', 100 );
    $user = $this->customer();
    $this->ledger_service()->adjust( $user, 780, 'seed', 1 );
    $earn = $this->earn( $user, 1004, 130 );          // 910 → Silver, bonus → 1010
    $this->ledger_service()->refund_earn( $earn, 130, 'refund', 1 ); // 880: still ≥ 800
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    $this->ledger_service()->adjust( $user, -100, 'down', 1 );     // 780
    $this->assertSame( 'Bronze', $this->tier_of( $user ) );
    $this->assertCount( 1, $this->bonus_rows( $user ) );
}
public function test_placement_pays_no_bonus(): void {
    $this->set_bonus( 'Silver', 100 );
    $user = $this->customer();
    $this->with_tiers_off( fn() => $this->ledger_service()->adjust( $user, 900, 'seed', 1 ) );
    $this->assertNull( $this->tier_of( $user ) );
    TierEvaluator::build()->sweep_one( $user, TierSweep::MODE_PLACEMENT );
    $this->assertSame( 'Silver', $this->tier_of( $user ) );
    $this->assertCount( 0, $this->bonus_rows( $user ) );
    $this->assertSame( 'placement', $this->last_change( $user, [ 'reason' ] )[0] );
}
public function test_tiers_off_writes_nothing(): void {
    $user = $this->customer();
    $this->with_tiers_off( fn() => $this->ledger_service()->adjust( $user, 900, 'seed', 1 ) );
    $this->assertNull( $this->customer_row( $user )['tier_evaluated_at'] );
    $this->assertSame( [], $this->changes( $user ) );
}
```

`earn( $user, $order_id, $points ): LedgerEntry` writes `$this->ledger_service()->earn( $this->earn_draft( $user, $order_id, $points ) )->get_entry()`. `TierSweep` comes from Task 3, so `test_placement_pays_no_bonus` is written now and passes once Task 3 lands; mark it `@group tier-sweep`.

- [ ] **Step 6: Run it to confirm it fails**

Run: `composer test:integration -- --filter TierEvaluatorTest`
Expected: FAIL, the evaluator is missing (the tier stays NULL), with the tests counted in the summary line.

- [ ] **Step 7: Implement `TierEvaluator`** (signatures in the README contract)

- **`build()`** constructs `new LedgerService( new LedgerRepository(), $customers, new CampaignRepository() )`, `CustomerRepository`, `TierRepository` and `CustomerActivityService( new CustomerMetaRepository(), $customers, new LedgerRepository() )`.
- **`is_enabled()`** returns `true === SettingsHelper::get_settings()['tiers_enabled']`.
- **Shared core:** every public method funnels into one private `apply( int $user_id, callable $plan, string $reason, ?int $actor_id, bool $pays_bonus ): void`. It runs `Transaction::run()` and, inside it:
  1. `$customer = $customers->ensure_and_lock( $user_id )`, then `$live = $tiers->live()`.
  2. `$plan( Customer $customer, Tier[] $live ): array{floor_clear: bool, floor_set: ?array{id: int, until: ?string}, move: string}` decides the floor change and the move.
  3. Compute the floor (live and unexpired, after the floor change) and `TierDecision::target()`. For `current_rank`, find the current tier among `$live` (0 when absent).
  4. Call `TierDecision::move()`.
  5. `set_tier_state()` with `tier_progress_value` = `lifetime_earned` plus any bonus paid in step 7 (so progress matches points earned, since the bonus row's own evaluation writes nothing), `tier_evaluated_at` = now, any floor columns, and, on a change, `tier_id` and `tier_achieved_at` = now.
  6. On a change, call `record_tier_changed()`. The from name comes from `TierRepository::find_tier()` (deleted included); a NULL side reads ''.
  7. On a change, when `$pays_bonus`, `is_upgrade` and `entry_bonus > 0`, call `LedgerService::earn()` with the spec's bonus draft:
     `new LedgerEntry( null, $user_id, $bonus, 0, LedgerType::EARN, LedgerSource::TIER_BONUS, ReferenceType::TIER, $tier_id, null, null, null, $name . ' tier bonus', null, Dates::now(), IdempotencyKey::compose( LedgerType::EARN, $user_id, ReferenceType::TIER, $tier_id, IdempotencyKey::TIER_BONUS ) )`.
  8. On a change, register `Transaction::after_commit()` for `do_action( 'yayloyalty_tier_changed', $user_id, $from_id, $to_id, $reason )`, with its docblock.
- **Entry points:**

  | Method | Plan | Reason | Bonus |
  |---|---|---|---|
  | `on_ledger_entry()` | `TierDecision::ledger_policy()`; null → return before any transaction | from the policy | paid |
  | `sweep_one( placement )` | `to_target` | `placement` | none |
  | `sweep_one( reevaluate )` | when the current tier or the floor tier isn't live: clear the floor, `to_target` | `tier_deleted` | paid |
  | `sweep_one( reevaluate )` | otherwise: `up` | `threshold_change` | paid |
  | `assign_floor()` | sets the floor (until = `Dates::offset( $days * DAY_IN_SECONDS )` or null), then `up` | `manual`, actor | paid |
  | `clear_floor()` | clears it, then `to_target` | `manual_end`, actor | paid |

- **Early return:** every public method returns early while `! is_enabled()` or `false === get_userdata( $user_id )`.

- [ ] **Step 8: Wire the trigger and name the construction site**

`TierLedgerListener::register()` adds `add_action( 'yayloyalty_ledger_recorded', [ $this, 'on_recorded' ], 20 )`. `on_recorded( $entry )` returns unless `$entry instanceof LedgerEntry`, then calls `ListenerGuard::run( 'TierLedgerListener', 'tier evaluation failed for user #' . $entry->get_user_id(), fn() => TierEvaluator::build()->on_ledger_entry( $entry ) )`.

In `YayLoyaltyPro::boot()`, add `( new TierLedgerListener() )->register();` beside `BirthdayRunner`. In `LedgerService`'s class docblock, change "exactly five places" to six and add `Pro\Services\TierEvaluator::build()`.

- [ ] **Step 9: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter TierEvaluatorTest --exclude-group tier-sweep` and `composer test -- --filter TierDecisionTest`
Expected: both OK, with the integration summary counting 5 tests.

- [ ] **Step 10: Lint**

Run: `composer lint -- includes/Pro/Services/TierDecision.php includes/Pro/Services/TierEvaluator.php includes/Pro/Services/TierLedgerListener.php includes/Pro/YayLoyaltyPro.php includes/Services/LedgerService.php`
Expected: no error or warning in these files.

- [ ] **Step 11: Commit**

Stage: the files in this task's Files list, including both tests.
Message: `feat(tiers): evaluate tiers after each ledger write and pay the entry bonus`

### Task 3: Sweeps, the daily runner and the settings routes

**Done when:**
- `PUT /tiers/settings` turns tiers on and runs placement to completion.
- Tier create, threshold change and delete re-evaluate customers.
- Expired floors clear on the daily run.

**Files:**
- Create: `includes/Pro/Services/TierSweep.php`, `includes/Pro/Services/TierRunner.php`
- Modify: `includes/Pro/Controllers/TiersRestController.php`, `includes/Pro/YayLoyaltyPro.php`

**Interfaces:**
- Consumes: Task 1's `count_all()`, `user_ids_after()`, `count_up_to()` and `floor_ended_user_ids()`; Task 2's `TierEvaluator`.
- Produces: `TierSweep`, `TierRunner`, `GET/PUT /tiers/settings` (README contract).

- [ ] **Step 1: Implement `TierSweep`** (signatures in the README contract)

- Option writes use `update_option( self::OPTION, …, false )`.
- `request()` defers all of its work, the option write and `TierRunner::queue_now()`, to one `Transaction::after_commit()` callback, because a rollback doesn't undo the options object cache. Inside that callback it follows the spec's merge rule: a missing option becomes a new sweep; an existing one resets `after_user_id` to 0, recounts `total`, and keeps `placement` when either side is `placement`. `cancel()` deletes the option at once; that's safe, because a deleted sweep only means nothing runs.
- `progress()` returns `done` = `min( total, count_up_to( after_user_id ) )`.
- `current()` returns null for an absent or malformed option.

- [ ] **Step 2: Implement `TierRunner`** (signatures in the README contract)

- `register()` calls `ExpiryRunner::register_daily( self::RUN_ACTION, [ $this, 'schedule' ], [ $this, 'run' ] )`; `schedule()` calls `ExpiryRunner::keep_daily( self::RUN_ACTION )`.
- `queue_now()` calls `as_enqueue_async_action( self::RUN_ACTION, [], ExpiryRunner::GROUP )` when that function exists.
- `run()`, which returns at once unless `TierEvaluator::is_enabled()`:
  1. `floor_ended_user_ids( Dates::now(), BATCH_SIZE )` → `clear_floor( $id, null )` each.
  2. If `TierSweep::current()` exists, take `user_ids_after( after_user_id, BATCH_SIZE )` → `sweep_one( $id, mode )` each, then `advance( last id )`. Fewer than `BATCH_SIZE` ids calls `finish()`.
  3. If either step had `BATCH_SIZE` ids, call `queue_now()`.
  4. Each evaluation is in `try/catch ( \Throwable $e )`, logged with `Log::error( 'TierRunner', sprintf( 'tier evaluation for user %d failed: %s', $id, $e->getMessage() ) )`.

Register it in `YayLoyaltyPro::boot()`: `( new TierRunner() )->register();`.

- [ ] **Step 3: Add `GET/PUT /tiers/settings` to `TiersRestController`**

- **Route:** register `/tiers/settings` before `/tiers/(?P<id>[\d]+)`. Both methods need `Capabilities::MANAGE_TIERS`. PUT's args are `tiers_enabled`: `type` boolean, `required` true.
- **`get_settings()`** returns `tiers_enabled`, `tiers_mode` and `tiers_rolling_months` from `SettingsHelper::get_settings()`, plus `customer_count` = `$this->customers->count_all()` and `sweep` = `TierSweep::progress()`.
- **`update_settings()`:**
  1. Compare with the stored value.
  2. Call `SettingsHelper::update_settings( [ 'tiers_enabled' => $value ] )`.
  3. On false → true, call `TierSweep::request( TierSweep::MODE_PLACEMENT )`. On true → false, call `TierSweep::cancel()`.
  4. Return `get_settings()`. After a false → true change, override `sweep` with `{mode: 'placement', done: 0, total: customer_count}`, because the option is written only after commit.

- [ ] **Step 4: Request re-evaluation from the catalogue writes**

In `create_item()`, in `update_item()` when the stored `threshold` differs from the saved one, and in `delete_item()` after `soft_delete()`: when `TierEvaluator::is_enabled()`, call `TierSweep::request( TierSweep::MODE_REEVALUATE )`. Update the class docblock: catalogue writes never touch customer columns, they request a sweep.

- [ ] **Step 5: Verify the sweeps and the floor end**

Run, with tiers off, seed tiers, four customers A–D at 0, 900, 2,500 and 3,500 lifetime points (README Verification setup) and Silver's bonus 100 (`REST PUT /tiers/<Silver> '{"entry_bonus":100}'`):
1. `REST PUT /tiers/settings '{"tiers_enabled":true}'` → 200, `"sweep":{"mode":"placement","done":0,"total":4}` (more if the site has other customers).
2. `RUN`, then `$WP db query "SELECT user_id, tier_id FROM wp_yayloyalty_customers"` and `$WP db query "SELECT COUNT(*) FROM wp_yayloyalty_ledger WHERE source='tier_bonus'"`.
   Expected: A Bronze, B Silver, C Silver, D Gold; count 0; `REST GET /tiers/settings` shows `"sweep":null`.
3. `REST PUT /tiers/<Gold> '{"threshold":2000,"entry_bonus":50}'`, then `RUN`.
   Expected: C is Gold, with one 50-point `tier_bonus` row and a `tier_changed` row with reason `threshold_change`. D, already Gold, gets no row.
4. **Overlap:** `REST PUT /tiers/<Silver> '{"threshold":900}'`, then `REST PUT /tiers/settings '{"tiers_enabled":false}'`, then `$WP option get yayloyalty_tier_sweep`. Expected: the option doesn't exist. Then `RUN` changes nothing (`tier_evaluated_at` unchanged). Turn tiers back on and `RUN`.
5. **Rollback:** note `$WP eval 'echo count(as_get_scheduled_actions(["hook"=>"yayloyalty_tier_run","status"=>"pending"]));'`. Then `$WP eval 'try { \YayLoyalty\Database\Transaction::run(function () { \YayLoyalty\Pro\Services\TierSweep::request("reevaluate"); throw new \RuntimeException("x"); }); } catch (\RuntimeException $e) {} var_export(get_option("yayloyalty_tier_sweep"));'`, then the count again.
   Expected: `false`, and the pending count is unchanged.
6. **Floor end:** turn tiers on and `RUN`. Then `$WP db query "UPDATE wp_yayloyalty_customers SET tier_manual_id=<Gold>, tier_manual_until=UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id=<the 0-point customer>"` and `RUN`. Expected: both floor columns are NULL; the tier is unchanged (Bronze), because the Gold floor never moved `tier_id` when set by SQL. The manual path is verified in Phase 2.
7. **Raised threshold** (spec AC 7): restore Silver to 800 and `RUN`. Add a customer E at 850 (now Silver through the ledger trigger). `REST PUT /tiers/<Silver> '{"threshold":1000}'`, `RUN` → E is still Silver. Adjust E +10 → still Silver. Adjust E −10 → Bronze, with reason `adjustment`.
8. **Delete:** delete Silver (no reward references it), then `RUN`. Expected: its members move down, with reason `tier_deleted`.

Then: `composer test:integration -- --filter TierEvaluatorTest`. Expected: OK, 6 tests, `test_placement_pays_no_bonus` included.

- [ ] **Step 6: Lint**

Run: `composer lint -- includes/Pro/Services/TierSweep.php includes/Pro/Services/TierRunner.php includes/Pro/Controllers/TiersRestController.php includes/Pro/YayLoyaltyPro.php`
Expected: no error or warning in these files.

- [ ] **Step 7: Commit**

Stage: the files in this task's Files list.
Message: `feat(tiers): add the tier sweeps, the daily tier run and the enable switch route`

### Task 4: The switch card, progress banner and `TierBadge`

**Done when:** a Pro admin turns tiers on and off from `#/tiers` with the confirms, sees the banner while a sweep runs, and sees "—" members while tiers are off.

**Files:**
- Create: `apps/admin/src/pro/tiers/TierSwitchCard.tsx`, `apps/admin/src/pro/tiers/TierBadge.tsx`
- Modify: `apps/admin/src/lib/schema/tier.schema.ts`, `apps/admin/src/pro/tiers/tiers.api.ts`, `apps/admin/src/pro/tiers/tiers.queries.ts`, `apps/admin/src/pro/tiers/TierCatalog.tsx`, `apps/admin/src/pro/index.ts`, `apps/admin/src/pages/tiers/TiersPage.tsx`

**Interfaces:**
- Consumes: Task 3's `GET/PUT /tiers/settings`.
- Produces: `tierSettingsSchema`, `getTierSettings`, `updateTierSettings`, `TIER_QUERIES.settings()`, `useTierSettingsQuery()`, `useUpdateTierSettingsMutation()`, `TierBadge`, `TierSwitchCard`, and `useTiersEnabled()` (README contract).

- [ ] **Step 1: Schema, api and queries**

- `tierSettingsSchema` is in the README contract; its doc comment mirrors `TiersRestController::get_settings()`.
- `TIER_QUERIES.settings()` uses `refetchInterval: (query) => (query.state.data?.sweep ? 5000 : false)`.
- `useUpdateTierSettingsMutation()` invalidates `['tier']` and `SETTINGS_QUERIES.main.queryKey`.
- `useTiersEnabled()` (in `tiers.queries.ts`) returns `useSettingsQuery().data?.tiers_enabled === true`.

- [ ] **Step 2: `TierBadge`**

The slice‑1 swatch markup (`size-3 shrink-0 rounded-full border`, `backgroundColor: color`, `aria-hidden`) plus the name in a `flex items-center gap-2` span. Replace the inline swatch in `TierCatalog`'s `TierRow` with it.

- [ ] **Step 3: `TierSwitchCard`**

- **Layout:** `ProgramStatusCard`'s layout and Switch (`w-11!`), with the spec's title and description.
- **The Switch is controlled** by the query data. `onCheckedChange` opens a `ConfirmDialog` with the spec's on or off copy (`customer_count` through `formatStoreNumber`). Only confirm calls the mutation; errors go through `toastApiError( error, __( 'Could not save the tier setting.', 'yayloyalty' ) )`.
- **Banner:** `Alert variant="outline"` + `InfoIcon` + `AlertTitle` "Updating customer tiers… %1$s of %2$s" (`formatStoreNumber`), shown while `sweep` is set.
- **Sweep finished:** when `sweep` goes from non-null to null, invalidate `['tier']` and toast "Customer tiers updated." Track the previous value with a `useRef`.
- **Loading and error:** a `Skeleton` while loading; `ErrorState` on error.

- [ ] **Step 4: Wire it**

- Export `TierSwitchCard` and `useTiersEnabled` from `pro/index.ts`.
- `TiersPage`: `<div className="flex flex-col gap-4"><TierSwitchCard /><TierCatalog /></div>` inside `PageLayout`.
- `TierCatalog`: the Members cell renders "—" when `! useTiersEnabled()`.

- [ ] **Step 5: Type-check and lint**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` and `pnpm --filter @yayloyalty/admin-page exec eslint src/lib/schema/tier.schema.ts src/pro/tiers src/pro/index.ts src/pages/tiers`
Expected: tsc exits 0; eslint reports no error in these files.

- [ ] **Step 6: Verify in the admin (phase exit)**

Run: `./scripts/run.sh build pro`. Set up three customers at 0, 900 and 3,500, and tiers off (`REST PUT /tiers/settings '{"tiers_enabled":false}'`). Open http://localhost:8889/wp-admin/admin.php?page=yayloyalty#/tiers.

1. The Members column shows "—" in every row.
2. Turn the switch on → "Turn on tiers?" names the customer count. Cancel → the switch stays off and `REST GET /tiers/settings` shows `false`.
3. Turn it on and confirm → the banner "Updating customer tiers… 0 of N" shows.
4. In a shell, `RUN` → within 5 s the banner goes, the toast "Customer tiers updated." shows, and Members reads Bronze 1, Silver 1, Gold 1.
5. Turn it off and confirm ("Turn off tiers?", a destructive button) → Members shows "—".
6. Chrome console (Claude, through claude-in-chrome): no error or warning from `yayloyalty` during 1–5.

Then run the phase exit tests: `composer test -- --filter TierDecisionTest` and `composer test:integration -- --filter TierEvaluatorTest`. Expected: both OK.

- [ ] **Step 7: Commit**

Stage: the files in this task's Files list.
Message: `feat(tiers): add the enable switch and sweep progress to the Tiers page`
