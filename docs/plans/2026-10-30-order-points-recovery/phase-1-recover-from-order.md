# Phase 1: Recover from the order screen

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** the "Loyalty points" box on the order edit screen, for HPOS and legacy storage, gives missing points back once per order, by confirming the known figure in one click. Every other manual grant goes through a link to SPA Adjust points. The points behave like purchase points afterwards.
**Amended:** 2026-11-03 (spec amendment of the same day): no Manual mode and no reason field. The POST takes no body, `MISSING_UNKNOWN` answers 409 `yayloyalty_not_eligible`, and `EARNED`, `RECOVERED` and `MISSING_UNKNOWN` boxes link to `admin.php?page=yayloyalty#/customers/{customer_id}?adjust=1`, which opens the Adjust points dialog (Task 4, Step 2b).
**Exit criterion:** on the local site:

1. Spec acceptance 11 steps 1, 2, 4, 5 and 6. Skip step 3 (the column, Phase 2) and open the order directly. Expected:
   - the box offers **Give N points**;
   - the customer's balance rises by N;
   - re-completing the order adds nothing;
   - cancelling the order takes N back.
2. `composer test:integration` with `YAYLOYALTY_TEST_SOCKET` set prints `OK`, and its test count includes the 7 `OrderPointsRecoveryTest` methods.

### Task 0: Commit the spec and plan

The current branch `paul/improve/missing-points` is the work branch. Its base is `dev/edd`.

- [ ] **Step 1: Confirm the branch**

Run: `git branch --show-current && git merge-base --is-ancestor dev/edd HEAD && echo based-on-dev-edd`
Expected: `paul/improve/missing-points` then `based-on-dev-edd`

- [ ] **Step 2: Commit**

Stage:
- `docs/specs/2026-10-30-order-points-recovery-design.md`
- `docs/plans/2026-10-30-order-points-recovery/README.md`
- `docs/plans/2026-10-30-order-points-recovery/phase-1-recover-from-order.md`
- `docs/plans/2026-10-30-order-points-recovery/phase-2-orders-list-column.md`

Message: `docs(earning): design and plan order points recovery`

### Task 1: Ledger: the recovery row and the double-award guard

**Files:**
- Modify: `includes/Engine/Earning/IdempotencyKey.php`
- Modify: `includes/Repositories/LedgerRepository.php`
- Modify: `includes/Services/LedgerService.php`
- Test: `tests/integration/Earning/OrderPointsRecoveryTest.php`

**Interfaces:**
- Consumes: nothing new
- Produces (see the README contract): `IdempotencyKey::RECOVERY`, `LedgerRepository::order_points_summary()`, `LedgerRepository::find_recovery_for_order()`, `LedgerService::REASON_RECOVERED`, `LedgerService::REASON_ORDER_HAS_POINTS`, `LedgerService::recover_order()`

- [ ] **Step 1: Write the failing tests**

Create `OrderPointsRecoveryTest extends IntegrationTestCase`, `use WooCommerceOrdersFixture`, with `setUp()` and `tearDown()` as in `ReversalFlowTest`. Add a private helper `recovery_key( int $user_id, int $order_id ): string` that returns `"earn:{$user_id}:order:{$order_id}:recovery"`.

```php
public function test_recovery_writes_one_keyed_earn_row(): void {
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ], 'processing' );

    $outcome = $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 120, 'Server error', 1 );

    $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
    $row = $this->ledger_rows( $user_id )[0];
    $this->assertSame( [ 120 ], $this->ledger_points( $user_id ) );
    $this->assertSame( LedgerType::EARN, $row['type'] );
    $this->assertSame( LedgerSource::ORDER, $row['source'] );
    $this->assertSame( ReferenceType::ORDER, $row['reference_type'] );
    $this->assertSame( (int) $order->get_id(), (int) $row['order_id'] );
    $this->assertNull( $row['campaign_id'] );
    $this->assertSame( 1, (int) $row['actor_id'] );
    $this->assertSame( 'Server error', $row['note'] );
    $this->assertSame( $this->recovery_key( $user_id, (int) $order->get_id() ), $row['idempotency_key'] );
    $this->assertSame( 120, (int) $this->customer_row( $user_id )['balance'] );
    $this->assertSame( 120, (int) $this->customer_row( $user_id )['lifetime_earned'] );
}

public function test_second_recovery_is_a_duplicate(): void {
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ], 'processing' );
    $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 120, 'First', 1 );

    $outcome = $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 120, 'Second', 1 );

    $this->assertSame( Outcome::DUPLICATE, $outcome->get_status() );
    $this->assertSame( [ 120 ], $this->ledger_points( $user_id ) );
    $this->assertSame( 120, (int) $this->customer_row( $user_id )['balance'] );
}

public function test_recovery_is_refused_when_the_order_has_live_points(): void {
    $this->create_campaign();
    $user_id = $this->create_customer();
    $order   = $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );

    $outcome = $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 40, 'Again', 1 );

    $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
    $this->assertSame( LedgerService::REASON_ORDER_HAS_POINTS, $outcome->get_reason() );
    $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
}

public function test_later_completion_is_skipped_after_recovery(): void {
    $this->create_campaign();
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ], 'processing' );
    $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 40, 'Stuck', 1 );

    $this->transition( $order, 'completed' );

    $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
}

public function test_cancel_claws_back_the_recovery(): void {
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ], 'processing' );
    $this->ledger_service()->recover_order( $user_id, (int) $order->get_id(), 40, 'Stuck', 1 );

    $this->transition( $order, 'cancelled' );

    $rows = $this->ledger_rows( $user_id );
    $this->assertSame( [ 40, -40 ], $this->ledger_points( $user_id ) );
    $this->assertSame( LedgerType::REFUND_EARN, $rows[1]['type'] );
    $this->assertSame( 'refund_earn:' . $user_id . ':order:' . $order->get_id() . ':recovery', $rows[1]['idempotency_key'] );
    $this->assertSame( 0, (int) $this->customer_row( $user_id )['balance'] );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter OrderPointsRecoveryTest`
Expected: 5 errors, `Call to undefined method YayLoyalty\Services\LedgerService::recover_order()`

- [ ] **Step 3: Add the `recovery` qualifier to `IdempotencyKey`**

Add `public const RECOVERY = 'recovery';`. In `compose()`, accept it as the qualifier when `$type` is `LedgerType::EARN` or `LedgerType::REFUND_EARN`, alongside `CampaignTrigger::is_known()`. For any other type it stays an unknown qualifier. Update the `$qualifier` docblock line to say so.

- [ ] **Step 4: Add the two `LedgerRepository` reads**

- `order_points_summary( int $order_id, bool $is_locking = false ): array`: one query with two conditional sums. `earned` sums `type = earn`, and `net` sums `type IN (earn, refund_earn) OR (type = adjust AND reference_type = refund)`, both over `order_id = %d`. Append ` LOCK IN SHARE MODE` when `$is_locking`, as `sum_points()` does. Return ints.
- `find_recovery_for_order( int $order_id ): ?LedgerEntry`: use `select_entries( 'type = %s AND order_id = %d AND idempotency_key LIKE %s', [ LedgerType::EARN, $order_id, '%' . $wpdb->esc_like( ':recovery' ) ], 1 )`.

- [ ] **Step 5: Implement `recover_order()` and the guard in `LedgerService`**

- Add the constants `REASON_RECOVERED = 'recovered_manually'` and `REASON_ORDER_HAS_POINTS = 'order_has_points'`, each with a one-line docblock like their siblings.
- `recover_order( int $user_id, int $order_id, int $points, string $note, int $actor_id ): Outcome`:
  - Throw `\InvalidArgumentException` when `$points` is outside 1–1,000,000.
  - Build the draft as `adjust()` does, with these values:
    - type `EARN`, source `LedgerSource::ORDER`
    - reference `ReferenceType::ORDER` / `$order_id`, and `order_id = $order_id`
    - campaign `null`, the given note, and actor `$actor_id > 0 ? $actor_id : null`
    - created_at `Dates::now()`
    - key `IdempotencyKey::compose( LedgerType::EARN, $user_id, ReferenceType::ORDER, $order_id, IdempotencyKey::RECOVERY )`
  - Call `write_keyed( $draft, $points_under_lock, self::REASON_ORDER_HAS_POINTS )`. The `$points_under_lock` callable returns 0 when `$this->ledger->order_points_summary( $order_id, true )['net'] > 0`, and `$points` otherwise.
- The guard goes in `write_keyed()`, right after the `find_by_idempotency_key()` duplicate check and before `campaign_survives()`. It returns `Outcome::skipped( self::REASON_RECOVERED )` when all of these hold:
  - the draft is `EARN`;
  - its `order_id` is not null;
  - `IdempotencyKey::qualifier_of( $key ) === CampaignTrigger::PURCHASE`;
  - `find_by_idempotency_key()` finds the recovery key for the draft's user and order.

  Put the check in a private `is_recovered_order( LedgerEntry $draft ): bool`.
- In the class docblock, add `recover_order()` to the "System rows enter through" list.

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter OrderPointsRecoveryTest`
Expected: `OK (5 tests, …)`

- [ ] **Step 7: Run the existing earning and ledger suites**

Run: `composer test:integration -- --filter 'ReversalFlowTest|PurchaseLifecycleTest|LedgerServiceCampaignLockTest|DailyEarningLimitTest'`
Expected: `OK`, with the same test count as on `dev/edd`

- [ ] **Step 8: Lint the changed files**

Run: `composer lint -- includes/Engine/Earning/IdempotencyKey.php includes/Repositories/LedgerRepository.php includes/Services/LedgerService.php tests/integration/Earning/OrderPointsRecoveryTest.php`
Expected: no errors or warnings

- [ ] **Step 9: Commit**

Stage:
- `includes/Engine/Earning/IdempotencyKey.php`
- `includes/Repositories/LedgerRepository.php`
- `includes/Services/LedgerService.php`
- `tests/integration/Earning/OrderPointsRecoveryTest.php`

Message: `feat(ledger): add a once-per-order recovery earn and skip purchase awards after it`

### Task 2: Order points state, recovery service and REST route

**Files:**
- Create: `includes/Engine/Earning/OrderPointsState.php`
- Create: `includes/Engine/Earning/OrderPointsRecovery.php`
- Create: `includes/Controllers/OrderPointsRestController.php`
- Modify: `includes/Engine/RestAPI.php` (`controllers()`)
- Modify: `includes/Engine/Earning/PendingPoints.php`
- Modify: `includes/Repositories/CustomerRepository.php`
- Test: `tests/integration/Earning/OrderPointsRecoveryTest.php`

**Interfaces:**
- Consumes: the Task 1 interfaces; `PendingPoints::unreleased()` and `release()`
- Produces (see the README contract): `OrderPointsState`, the `orders/{id}/points` routes, `PendingPoints::forget_cached()`, `restate_earned()` and `resync_customer()`, `CustomerRepository::set_pending_points()`, and `OrderPointsRecovery::recover( \WC_Order $order, int $points, string $note, int $actor_id ): Outcome`

- [ ] **Step 1: Write the failing test**

```php
public function test_rest_recovery_of_a_failed_award(): void {
    $this->create_campaign();
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ] ); // pending: stages 40.
    $throw   = static function () { throw new \RuntimeException( 'test' ); };
    add_filter( 'yayloyalty_award_points', $throw );
    $order = $this->transition( $order, 'completed' );
    remove_filter( 'yayloyalty_award_points', $throw );
    $this->assertSame( [], $this->ledger_points( $user_id ) );

    $this->act_as_admin();
    $route = '/yayloyalty/v1/orders/' . $order->get_id() . '/points';
    $this->assertSame( 'missing_known', $this->send( 'GET', $route )->get_data()['status'] );
    $this->assertSame( 40, $this->send( 'GET', $route )->get_data()['figure'] );

    $response = $this->send( 'POST', $route );

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( 'recovered', $response->get_data()['status'] );
    $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    $this->assertSame( 'Recovered missing order points', $this->ledger_rows( $user_id )[0]['note'] );
    $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
    $fresh = wc_get_order( $order->get_id() );
    $this->assertNotSame( '', (string) $fresh->get_meta( PendingPoints::META_RELEASED ) );
    $this->assertSame( 40, (int) $fresh->get_meta( PendingPoints::META_EARNED ) );
    $this->assertSame( 'yayloyalty_already_recovered', $this->send( 'POST', $route )->as_error()->get_error_code() );
}

/** Case 4: the order meta claims 40 earned and released, the ledger has nothing, and pending_points still holds 40. */
public function test_recovery_after_stale_earned_meta(): void {
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ], 'completed' );
    $order->update_meta_data( PendingPoints::META_PENDING, 40 );
    $order->update_meta_data( PendingPoints::META_RELEASED, Dates::now() );
    $order->update_meta_data( PendingPoints::META_EARNED, 40 );
    $order->save_meta_data();
    ( new CustomerRepository() )->ensure_row( $user_id );
    ( new CustomerRepository() )->apply_pending_delta( $user_id, 40 );

    $this->act_as_admin();
    $route = '/yayloyalty/v1/orders/' . $order->get_id() . '/points';
    $state = $this->send( 'GET', $route )->get_data();
    $this->assertSame( 'missing_known', $state['status'] );
    $this->assertSame( 40, $state['figure'] );

    $this->assertSame( 200, $this->send( 'POST', $route )->get_status() );

    $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    $this->assertSame( 40, (int) wc_get_order( $order->get_id() )->get_meta( PendingPoints::META_EARNED ) );
    $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
    $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter 'test_rest_recovery_of_a_failed_award|test_recovery_after_stale_earned_meta'`
Expected: 2 failures, the GET answers 404 `rest_no_route`

- [ ] **Step 3: Implement `OrderPointsState`**

`final class`, read only, with the constants and getters from the README contract. `resolve()` decides the status in this order, and the first match wins:

| # | Condition | Status | Figure |
|---|---|---|---|
| 1 | status not in `wc_get_is_paid_statuses()` | `NOT_PAID` | 0 |
| 2 | `get_customer_id() < 1` | `GUEST` | 0 |
| 3 | `find_recovery_for_order()` finds a row | `RECOVERED` | 0 |
| 4 | `net > 0` | `EARNED` | 0 |
| 5 | `PendingPoints::unreleased() > 0` | `MISSING_KNOWN` | the unreleased figure |
| 6 | `earned > 0` | `MISSING_KNOWN` | `earned` |
| 7 | `(int) get_meta( PendingPoints::META_EARNED ) > 0` (case 4: the meta claims points the ledger lacks) | `MISSING_KNOWN` | that meta value |
| 8 | otherwise | `MISSING_UNKNOWN` | 0 |

- For `RECOVERED`, `is_recovery_reversed()` is `sum_refunded_for( $recovery, false ) >= $recovery->get_points()`.
- `to_array()` returns `[ 'status', 'figure', 'net_earned', 'recovery' => null | [ 'points', 'note', 'actor_name', 'created_at', 'reversed' ] ]`, where `created_at` goes through `Dates::to_site_date()` and `actor_name` is the actor's `display_name`, or `''` when there is none.

- [ ] **Step 4: Add the `PendingPoints` methods and the customer setter**

- `CustomerRepository::set_pending_points( int $user_id, int $points ): void`: an UPDATE of `pending_points` to `max( 0, $points )`. It throws `QueryException` on `false`, like `apply_pending_delta()`. Its docblock says only `PendingPoints::resync_customer()` calls it.
- `PendingPoints::forget_cached( int $order_id ): void`:
  - Call `clean_post_cache( $order_id )`.
  - When `OrderUtil::orders_cache_usage_is_enabled()`, also call `wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id )`.
  - The docblock explains case 4: `Transaction` rolls back the database, not the object cache.
- `PendingPoints::restate_earned( int $order_id, LedgerEntry $entry ): void`: like `mark_awarded()`, but sets `META_EARNED` to `$entry->get_points()` instead of adding to it. Saved through `save_meta()`.
- `PendingPoints::resync_customer( int $user_id ): int`:
  - Sum `META_PENDING` over `wc_get_orders( [ 'customer_id' => $user_id, 'limit' => -1, 'return' => 'ids', 'meta_query' => … ] )`, using `META_PENDING` EXISTS and `META_RELEASED` NOT EXISTS.
  - Call `set_pending_points()` with the sum and return it.
  - The docblock repeats the class rule: order meta is the truth, and `pending_points` is its cache.

- [ ] **Step 5: Implement `OrderPointsRecovery`**

- `__construct( LedgerService $writer, LedgerRepository $ledger, PendingPoints $pending )`.
- `recover( \WC_Order $order, int $points, string $note, int $actor_id ): Outcome`:
  - First call `$pending->forget_cached( $order_id )` and re-read with `wc_get_order( $order_id )`.
  - In one `Transaction::run()`, call `$writer->recover_order( customer_id, order_id, $points, $note, $actor_id )`.
  - On `AWARDED`, also call `$pending->release( $order_id )`, `$pending->restate_earned( $order_id, $entry )` and `$pending->resync_customer( $customer_id )`.
  - On `AWARDED`, `Transaction::after_commit()` adds the order note `sprintf( __( '%1$d points added manually by %2$s: %3$s.', 'yayloyalty' ), $points, display_name, $note )`.
  - Return the outcome.

- [ ] **Step 6: Implement the controller and its route args**

- No body args (amended 2026-11-03): the route takes only `id`, so no `OrderPointsRouteArgs` class.
- `OrderPointsRestController`:
  - `get_rest_base()` returns `'orders'`.
  - It registers `/orders/(?P<id>[\d]+)/points` with `READABLE` → `exec_read( [ $this, 'get_state' ] )` and `CREATABLE` → `exec_write( [ $this, 'recover' ] )`, both with `requires( Capabilities::MANAGE_POINTS )`.
  - Constructor: `( OrderPointsRecovery $recovery, LedgerRepository $ledger, PendingPoints $pending )`.
  - Both callbacks call `$pending->forget_cached( $id )` before `wc_get_order()`.
- `recover()` maps cases to responses as follows. Each error is a `new \WP_Error( code, message, [ 'status' => N ] )`, with the codes from the README contract.

| Case | Response |
|---|---|
| No `wc_get_order()` | 404 |
| State `NOT_PAID` or `GUEST` | 409 `yayloyalty_not_eligible` |
| State `RECOVERED` | 409 `yayloyalty_already_recovered` |
| State `EARNED` | 409 `yayloyalty_order_has_points` |
| `MISSING_KNOWN` | Points = figure. Note = "Recovered missing order points". |
| `MISSING_UNKNOWN` | 409 `yayloyalty_not_eligible` |
| Outcome `skipped( REASON_ORDER_HAS_POINTS )` | 409 `yayloyalty_order_has_points` |
| Outcome `DUPLICATE` | 409 `yayloyalty_already_recovered` |
| Success | 200, with `OrderPointsState::resolve()` of a fresh `wc_get_order()` |

- [ ] **Step 7: Register the controller**

In `RestAPI::controllers()`, build `$pending = new PendingPoints( $customers )` and add `new OrderPointsRestController( new OrderPointsRecovery( $ledger_service, $ledger, $pending ), $ledger, $pending )` next to `CustomerRestController`, with a one-line comment that `$ledger` is read only here.

- [ ] **Step 8: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter OrderPointsRecoveryTest`
Expected: `OK (7 tests, …)`

- [ ] **Step 9: Verify the reversed-then-restored figure, the refusal of `missing_unknown` and case 4 under an object cache**

On the local site, complete an order for a registered customer while a purchase campaign is active, so it earns N. Set it to Cancelled, then to Completed. As an administrator, in the browser console on wp-admin, run `wp.apiFetch({ path: '/yayloyalty/v1/orders/<id>/points' })`.
Expected: `status: "missing_known"` and `figure: N`

On a completed order that earned nothing (no active purchase campaign), run `wp.apiFetch({ path: '/yayloyalty/v1/orders/<id>/points', method: 'POST', data: { points: 50, note: 'x' } })`.
Expected: 409 `yayloyalty_not_eligible`, and no new ledger row in the customer's drawer

With a persistent object cache active (Redis Object Cache plugin, `wp redis status` showing `Connected`), repeat spec acceptance 11 steps 1 and 2, then open the order.
Expected: the box offers **Give N points** whatever the cached meta says. After clicking, `wp eval 'echo wc_get_order(<id>)->get_meta("_yayloyalty_points_earned");'` prints N (not 2N), and the customer's pending in their drawer is 0. If no object cache is available locally, record that this check was skipped; the accepted test covers the meta and pending outcome.

- [ ] **Step 10: Lint the changed files**

Run: `composer lint -- includes/Engine/Earning/OrderPointsState.php includes/Engine/Earning/OrderPointsRecovery.php includes/Controllers/OrderPointsRestController.php includes/Engine/RestAPI.php includes/Engine/Earning/PendingPoints.php includes/Repositories/CustomerRepository.php`
Expected: no errors or warnings

- [ ] **Step 11: Commit**

Stage the seven files above and `tests/integration/Earning/OrderPointsRecoveryTest.php`.
Message: `feat(earning): recover an order's missing points through orders/{id}/points`

### Task 3: Order note for the skipped award and the quiet partial refund

**Files:**
- Modify: `includes/Engine/Earning/Listeners/OrderListener.php` (`note()`, SKIPPED branch)
- Modify: `includes/Engine/Earning/Reversal.php` (`bases()`)

**Interfaces:**
- Consumes: `LedgerService::REASON_RECOVERED`, `IdempotencyKey::RECOVERY`
- Produces: nothing

- [ ] **Step 1: Write the note for `REASON_RECOVERED`**

In `note()`, in the `Outcome::SKIPPED` branch, before the generic `No points earned: %s.`, add a case for `LedgerService::REASON_RECOVERED === $outcome->get_reason()` that writes `__( 'No points earned: points were recovered manually.', 'yayloyalty' )`.

- [ ] **Step 2: Silence the warning in `bases()`**

Log the "campaign … is gone" warning only when the row's `campaign_id` is not null or its key's qualifier is not `IdempotencyKey::RECOVERY`. Every line still counts when the campaign is null.

- [ ] **Step 3: Verify on the local site**

- Recover a `processing` order through the box or the REST call from Task 2, then set it to Completed. Expected: the notes include "No points earned: points were recovered manually." and the balance is unchanged.
- Refund one of two lines on a recovered order. Expected: a `refund_earn` row proportional to that line's total, and no `campaign of ledger row` warning in WooCommerce › Status › Logs (source `yayloyalty`).

- [ ] **Step 4: Lint and commit**

Run: `composer lint -- includes/Engine/Earning/Listeners/OrderListener.php includes/Engine/Earning/Reversal.php`
Expected: no errors or warnings

Stage both files.
Message: `feat(earning): note recovered orders and quiet the refund warning for recovery rows`

### Task 4: The "Loyalty points" metabox

**Files:**
- Create: `includes/Engine/Admin/OrderPointsMetabox.php`
- Create: `assets/admin/order-points.js`
- Modify: `includes/YayLoyalty.php` (the `is_admin()` branch, next to `ReviewPointsNote::register()`)

**Interfaces:**
- Consumes: `OrderPointsState`; the REST routes
- Produces: meta box id `yayloyalty-order-points`; script handle `yayloyalty-order-points`

- [ ] **Step 1: Implement `OrderPointsMetabox`**

- `final class` with `public static function register(): void`, which hooks `add_meta_boxes` and `admin_enqueue_scripts`.
- `add()`: when `current_user_can( Capabilities::MANAGE_POINTS )`, call `add_meta_box( 'yayloyalty-order-points', __( 'Loyalty points', 'yayloyalty' ), [ self::class, 'render' ], wc_get_page_screen_id( 'shop-order' ), 'side', 'default' )`.
- `render( $post_or_order )`:
  - Read the order with `wc_get_order( $post_or_order instanceof \WP_Post ? $post_or_order->ID : $post_or_order )`.
  - Call `( new PendingPoints( new CustomerRepository() ) )->forget_cached( $id )` before the read, then resolve the state with `OrderPointsState::resolve( $order, new LedgerRepository() )`.
  - Escape all output. The wrapper is `<div class="yayloyalty-order-points" data-order-id="{id}">`.

  Each state renders as follows:

| State | Output |
|---|---|
| `NOT_PAID` | "Points can be added once the order is paid (Processing or Completed)." |
| `GUEST` | "Assign a customer in the Customer field and update the order to add points." |
| `EARNED` | "Earned %d points." Then the Adjust points link. |
| `RECOVERED` | "%1$d points added manually by %2$s on %3$s: %4$s." When `is_recovery_reversed()`: "%1$d points added manually by %2$s on %3$s were reversed." Then the Adjust points link. |
| `MISSING_KNOWN` | "This order should have earned %1$d points but they are not in %2$s's balance.", where %2$s is the customer's display name. Then a button `.button.button-primary` "Give %d points". No reason field. |
| `MISSING_UNKNOWN` | The Adjust points link only. |

  The Adjust points link reads "Adjust points →" and points to `admin_url( 'admin.php?page=yayloyalty#/customers/{customer_id}?adjust=1' )`. The `<div class="yayloyalty-order-points__error" hidden>` error line appears in `MISSING_KNOWN` only.
- `enqueue( string $hook )`: return unless `get_current_screen()` has the id `wc_get_page_screen_id( 'shop-order' )` and the user can `MANAGE_POINTS`. Then call `wp_enqueue_script( 'yayloyalty-order-points', YAYLOYALTY_PLUGIN_URL . 'assets/admin/order-points.js', [ 'wp-api-fetch' ], YAYLOYALTY_VERSION, true )`.

- [ ] **Step 2: Write `assets/admin/order-points.js`**

- An IIFE with no build step, matching the style of `assets/storefront/*.js`.
- On a button click inside `.yayloyalty-order-points`, call `wp.apiFetch( { path: '/yayloyalty/v1/orders/' + id + '/points', method: 'POST', data } )`. No `data`: the route takes no body.
- Disable the button while the request runs. On success, call `window.location.reload()`. On error, show `error.message` in the error line and enable the button again.
- `wp-api-fetch` already sends the REST nonce in wp-admin.

- [ ] **Step 2b: Open Adjust points from the link**

In `apps/admin/src/pages/customers/drawer/CustomerDrawerActions.tsx`, when the search has `adjust=1`, drop the parameter with `setSearchParams( …, { replace: true } )`, and open the Adjust points dialog when the user has `CAP.MANAGE_POINTS` and the account is not deleted. Check with `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`.

- [ ] **Step 3: Register it**

In `includes/YayLoyalty.php`, in the `else` (admin) branch, add `\YayLoyalty\Engine\Admin\OrderPointsMetabox::register();`.

- [ ] **Step 4: Verify the box in both storages and for permissions**

- With HPOS on (WooCommerce › Settings › Advanced › Features), walk spec acceptance 11 steps 1, 2, 4, 5 and 6, opening the order directly. Expected:
  - the box shows "This order should have earned N points…";
  - **Give N points** reloads to "N points added manually by {you} on {date}: Recovered missing order points.";
  - the order note is there and the balance rose by N;
  - re-completing adds nothing;
  - cancelling takes N back, and the box then says "…were reversed; use YayLoyalty › Customers › Adjust points."
- Switch to legacy post storage and open a pending order and a guest processing order. Expected: the not-paid and guest messages, with no button.
- On a paid order that earned nothing, the box shows only the Adjust points link. Clicking it opens YayLoyalty › Customers with that customer's drawer and the Adjust points dialog open; closing the dialog and reloading does not reopen it.
- Log in as a shop manager after removing the capability: run `wp cap remove shop_manager yayloyalty_manage_points`, then put it back with `wp cap add`. Expected: no "Loyalty points" box, and `wp.apiFetch` against the route answers 403 `yayloyalty_forbidden`.

- [ ] **Step 5: Lint and commit**

Run: `composer lint -- includes/Engine/Admin/OrderPointsMetabox.php includes/YayLoyalty.php`
Expected: no errors or warnings

Stage `includes/Engine/Admin/OrderPointsMetabox.php`, `assets/admin/order-points.js` and `includes/YayLoyalty.php`.
Message: `feat(admin): add the Loyalty points box to the order screen`

- [ ] **Step 6: Phase exit check**

Run the exit criterion at the top of this file, then `/yayplancheck docs/plans/2026-10-30-order-points-recovery`.
Expected: both points of the exit criterion pass, and yayplancheck reports no gaps. Mark Phase 1 `done` in the README table.
