# Order Points Retry Award Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** The order screen's "Give N points" button re-runs the standard purchase award for an order whose award never completed, instead of writing a separate `:recovery` ledger row.
**Spec:** [docs/specs/2026-10-30-order-points-recovery-design.md](../specs/2026-10-30-order-points-recovery-design.md), amended by Task 1 of this plan. Supersedes [docs/plans/2026-10-30-order-points-recovery/](2026-10-30-order-points-recovery/README.md).
**Architecture:** "Missing" is decided by the order alone: `completed`, has a customer, no EARN row for the order (any user), and either an unreleased staged figure (signal A) or `_yayloyalty_points_earned` without an EARN row (signal C). The button runs the same code `completed` runs (`OrderAward::run()`, extracted from `OrderListener`), so the row is a normal purchase earn keyed `earn:{user}:order:{id}:purchase` and every existing guard, reversal and refund path applies unchanged. The rejected alternative is the separate recovery row this branch shipped first: it needed its own qualifier, guard, figure rules and reversal tweaks, and produced the double-pay and refund bugs found on 2026-11-03.
**Touches:**
- `includes/Engine/Earning/Listeners/OrderListener.php` (award body moves out)
- `includes/Engine/Earning/OrderAward.php` (new; consumers: `OrderListener`, `OrderPointsRetry`)
- `includes/Engine/Earning/OrderPointsRetry.php` (new, replaces `OrderPointsRecovery.php`)
- `includes/Engine/Earning/OrderPointsState.php`
- `includes/Engine/Earning/PendingPoints.php` (shared: `OrderListener`, `EarningService`, `OrderReversalListener`, `OrderCustomerChangeListener`)
- `includes/Services/LedgerService.php` (shared: every ledger write)
- `includes/Repositories/LedgerRepository.php` (shared: `LedgerService`, `Reversal`, `OrderListener`, controllers)
- `includes/Engine/Earning/IdempotencyKey.php`, `includes/Engine/Earning/Reversal.php` (both back to `dev/edd`)
- `includes/Controllers/OrderPointsRestController.php`, `includes/Engine/RestAPI.php`, `includes/YayLoyalty.php`
- `includes/Engine/Admin/OrderPointsMetabox.php`, `includes/Engine/Admin/OrderPointsColumn.php`
- `apps/admin/src/pages/customers/drawer/CustomerDrawerActions.tsx`, `apps/admin/src/pages/customers/components/CustomersAdjustPointsDialog.tsx`
- `docs/specs/2026-10-30-order-points-recovery-design.md`, `docs/plans/2026-10-30-order-points-recovery/README.md`

**Tests:** `tests/integration/Earning/OrderPointsRetryTest.php` (integration, `composer test:integration`), replacing `OrderPointsRecoveryTest.php`. Accepted by the user on 2026-11-03:
- `test_retry_awards_a_failed_award` (signal A): one standard purchase row, pending released, second POST refused;
- `test_retry_after_stale_earned_meta` (signal C): one row, `_yayloyalty_points_earned` stays 40, not 80;
- `test_reassigned_order_is_not_paid_twice`: the `write_keyed()` guard for reassigned orders;
- `test_reversed_order_is_not_missing`: a cancelled-then-restored order is not missing and the POST writes nothing.
- `test_order_never_processed_is_missing` and `test_skipped_award_is_not_missing` (signal D, accepted 2026-11-04; Task 6).

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed`/`static`/`never` types, `catch` without a variable, `throw` expressions, a trailing comma after the last declared parameter, `new` in defaults, first-class callables or `$object::class`.
- Only `LedgerService` writes ledger rows and balances, and it is constructed only in the places its docblock lists. The retry gets its `EarningService` from `EarningService::build()`, an existing site; no new `new LedgerService(`.
- Lite feature: everything lives in core `includes/` and `apps/admin/src/` outside `pro/`.
- Orders are read and written only through the WooCommerce CRUD API. Order meta is written only through `PendingPoints`, meta only, never a full save.
- The REST route uses `exec_write()` and `requires( Capabilities::MANAGE_POINTS )`. The actor is `get_current_user_id()`, never a request field. The POST takes no body.
- Strings use the `yayloyalty` text domain. Hooks, globals, meta box ids and column keys use the `yayloyalty_`/`yayloyalty-` prefix.
- SQL values go through `$wpdb->prepare()` placeholders. Logging goes only through `Log::error|warning|debug`.
- Lint only the changed PHP files with `composer lint -- <files>`; they must add no error or warning.
- `composer test:integration` on this branch already fails 2 tests unrelated to this work (`ExpiryRemindersTest::test_a_restamp_that_moves_a_lot_clears_its_reminder`, `PointsEarnedEmailTest::test_the_value_line_shows_while_the_discount_reward_is_live`). Expected result of a full run: those 2 and no others. Confirm the test count in the output (docs/known-issues.md).

## Review focus

- **A double click or a retried POST.** Expected: the second answers 409 `yayloyalty_order_has_points` and writes nothing. Owner: Task 3, test `test_retry_awards_a_failed_award`.
- **Case C under a persistent object cache.** The cached meta claims 40 earned, the database and ledger have nothing. Expected: one 40-point row and `_yayloyalty_points_earned = 40`. Owner: Task 3, test `test_retry_after_stale_earned_meta`.
- **The campaign changed or was paused/deleted since checkout.** Expected: the button offers what the award gives now; when that differs from the staged figure the box also says what checkout expected; when it is 0 there is no button, and the Adjust points link opens the dialog prefilled with the staged figure. Owner: Task 5, Step 4.
- **An order reassigned after it earned, then completed again.** Expected: the new customer gets nothing and the note says why. Owner: Task 4, test `test_reassigned_order_is_not_paid_twice`.
- **A cancelled order restored to completed (spec group B).** Expected: not missing; link only; POST 409 `yayloyalty_not_eligible`. Owner: Task 3, test `test_reversed_order_is_not_missing`.
- **`:recovery` rows already on a local site.** After `IdempotencyKey` goes back to `dev/edd`, reversing such a row throws. The branch never shipped, so only local data has them. Owner: Task 4, Step 3.
- **A shop manager without `yayloyalty_manage_points`.** Expected: no box and 403 from the route. Owner: Task 5, Step 4.

---

### Task 1: Amend the spec and supersede the old plan

**Files:**
- Modify: `docs/specs/2026-10-30-order-points-recovery-design.md`
- Modify: `docs/plans/2026-10-30-order-points-recovery/README.md` (Status line only)

- [ ] **Step 1: Amend the spec**

- Add an `**Amended:** 2026-11-03` line: the button re-runs the purchase award; missing is signal A or C on a `completed` order with no EARN row; group B (reversed then restored) is not missing.
- "Four ways the points go missing" table: case 1 is not missing (`awaiting_completion`); case 3 is not missing (link to Adjust points); cases 2 and 4 stay.
- Acceptance criteria: rewrite 1–3a and 5–8 for the retry (standard purchase row, its key and campaign, no recovery row, no `recovered_manually`); keep 4 (link, POST 409) and 9–10 with the state names below; criterion 11 stays as the end-to-end check.
- Components table: replace `recover_order()`, `IdempotencyKey::RECOVERY`, the `Reversal.php` and `restate_earned()` rows with `OrderAward`, `OrderPointsRetry` and `PendingPoints::forget_earned()`; drop the R1/R3/R4 edge-case rows that only applied to recovery rows.
- Alternatives: add "A separate recovery EARN row (shipped on this branch, replaced 2026-11-03): its own qualifier, guards and figure rules caused double-pay and refund bugs".
- Tests section: the four tests in this plan's **Tests:** line.

- [ ] **Step 2: Supersede the old plan**

In `docs/plans/2026-10-30-order-points-recovery/README.md` set `**Status:** superseded by [2026-11-03-order-points-retry-award.md](../2026-11-03-order-points-retry-award.md)`.

- [ ] **Step 3: Commit**

Stage: both files above and `docs/plans/2026-11-03-order-points-retry-award.md`
Message: `docs(earning): amend order points recovery to retry the award`

### Task 2: Extract `OrderAward` from `OrderListener`

No behaviour change: `completed` awards, notes and releases exactly as before.

**Files:**
- Create: `includes/Engine/Earning/OrderAward.php`
- Modify: `includes/Engine/Earning/Listeners/OrderListener.php` (`on_awardable()`, `note()`, constructor)
- Modify: `includes/YayLoyalty.php` (`register_earning_listeners()`)

**Interfaces:**
- Produces: `final class OrderAward` with `__construct( EarningService $service, PendingPoints $pending, LedgerRepository $ledger )` and `run( \WC_Order $order ): Outcome[]`. `run()` is today's `on_awardable()` body: award each trigger from `yayloyalty_order_award_triggers`, note each outcome on the order (today's `note()`, moved as is, including the `REASON_ORDER_HAS_POINTS` note), then `PendingPoints::release()`. Returns the outcomes in trigger order.
- Produces: `OrderListener::__construct( EarningService $service, PendingPoints $pending, OrderAward $award, OrderReversalListener $reversals )`; `on_awardable()` calls `$this->award->run( $order )`.

- [ ] **Step 1: Create `OrderAward` and move the award body and `note()` into it**

Move the `yayloyalty_order_award_triggers` docblock with the code. The `REASON_RECOVERED` branch of `note()` is not moved (Task 4 removes the constant).

- [ ] **Step 2: Rewire `OrderListener` and its composition root**

`register_earning_listeners()` builds `new OrderAward( $service, $pending, $ledger )` and passes it in.

- [ ] **Step 3: Verify**

Run: `composer test:integration`
Expected: only the 2 known failures listed in Global constraints; the `OrderListener` and `OrderPointsRecoveryTest` suites pass.
Run: `composer lint -- includes/Engine/Earning/OrderAward.php includes/Engine/Earning/Listeners/OrderListener.php includes/YayLoyalty.php`
Expected: no errors or warnings.

- [ ] **Step 4: Commit**

Stage: the three files above
Message: `refactor(earning): extract the order award from OrderListener`

### Task 3: Retry the award from the order screen

**Files:**
- Create: `includes/Engine/Earning/OrderPointsRetry.php`
- Delete: `includes/Engine/Earning/OrderPointsRecovery.php`
- Modify: `includes/Engine/Earning/OrderPointsState.php`
- Modify: `includes/Engine/Earning/PendingPoints.php`
- Modify: `includes/Controllers/OrderPointsRestController.php`
- Modify: `includes/Engine/RestAPI.php` (`controllers()`)
- Modify: `includes/Engine/Admin/OrderPointsMetabox.php`, `includes/Engine/Admin/OrderPointsColumn.php` (constant names only; Task 5 changes the box)
- Test: `tests/integration/Earning/OrderPointsRetryTest.php` (replaces `OrderPointsRecoveryTest.php`, deleted)

**Interfaces:**
- Consumes: `OrderAward::run()` (Task 2).
- Produces: `OrderPointsState` constants `NOT_PAID`, `GUEST`, `EARNED`, `AWAITING_COMPLETION`, `MISSING = 'missing'`, `NO_POINTS = 'no_points'` (`RECOVERED`, `MISSING_KNOWN`, `MISSING_UNKNOWN` removed); getters `get_status()`, `get_figure()`, `get_net_earned()`; `to_array(): array{status: string, figure: int, net_earned: int}`.
- Produces: `PendingPoints::forget_earned( int $order_id ): void` deletes `META_EARNED` and `META_CAMPAIGN` (meta-only save). `restate_earned()` and `write_earned()` are removed and `mark_awarded()` returns to its `dev/edd` body.
- Produces: `OrderPointsRetry::__construct( OrderAward $award, PendingPoints $pending )` and `retry( \WC_Order $order, int $actor_id ): Outcome[]`.
- Produces: `POST yayloyalty/v1/orders/{id}/points` answers 200 with `to_array()`, or 404 `yayloyalty_order_not_found`, 409 `yayloyalty_order_has_points`, 409 `yayloyalty_not_eligible`, 409 `yayloyalty_not_awarded` (`yayloyalty_already_recovered` removed).

`OrderPointsState::resolve()` order, first match wins:

| # | Condition | Status | Figure |
|---|---|---|---|
| 1 | status not in `wc_get_is_paid_statuses()` | `NOT_PAID` | 0 |
| 2 | no customer | `GUEST` | 0 |
| 3 | `net > 0` | `EARNED` | 0 |
| 4 | status is not `OrderStatus::AWARD` | `AWAITING_COMPLETION` | `PendingPoints::unreleased()` |
| 5 | `earned > 0` (any EARN row: group B) | `NO_POINTS` | 0 |
| 6 | has refunds and they cover the total (`Reversal::refund_state()`) | `NO_POINTS` | 0 |
| 7 | `unreleased() > 0`, else `META_EARNED > 0` | `MISSING` | that figure |
| 8 | otherwise | `NO_POINTS` | 0 |

`order_points_summary()` keeps `earned` and `net`; its `reversed` key is removed in Task 4.

- [ ] **Step 1: Write the failing tests**

Reuse the fixture setup of the current `OrderPointsRecoveryTest` (`setUp`, `tearDown`, `fresh_state()`), and the existing helpers `create_campaign()`, `create_customer()`, `create_order()`, `transition()`, `act_as_admin()`, `send()`, `ledger_rows()`, `ledger_points()`, `customer_row()`.

```php
public function test_retry_awards_a_failed_award(): void {
    $this->create_campaign();
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ] ); // pending: stages 40.
    $throw   = static function () {
        throw new \RuntimeException( 'test' );
    };
    add_filter( 'yayloyalty_award_points', $throw );
    $order = $this->transition( $order, 'completed' );
    remove_filter( 'yayloyalty_award_points', $throw );

    $state = $this->fresh_state( $order->get_id() );
    $this->assertSame( OrderPointsState::MISSING, $state->get_status() );
    $this->assertSame( 40, $state->get_figure() );

    $this->act_as_admin();
    $route    = '/yayloyalty/v1/orders/' . $order->get_id() . '/points';
    $response = $this->send( 'POST', $route );

    $this->assertSame( 200, $response->get_status() );
    $this->assertSame( 'earned', $response->get_data()['status'] );
    $rows = $this->ledger_rows( $user_id );
    $this->assertCount( 1, $rows );
    $this->assertSame( 40, (int) $rows[0]['points'] );
    $this->assertSame( 'earn:' . $user_id . ':order:' . $order->get_id() . ':purchase', $rows[0]['idempotency_key'] );
    $this->assertNotNull( $rows[0]['campaign_id'] );
    $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
    $fresh = wc_get_order( $order->get_id() );
    $this->assertNotSame( '', (string) $fresh->get_meta( PendingPoints::META_RELEASED_AT ) );
    $this->assertSame( 40, (int) $fresh->get_meta( PendingPoints::META_EARNED ) );
    $this->assertSame( 'yayloyalty_order_has_points', $this->send( 'POST', $route )->as_error()->get_error_code() );
    $this->assertCount( 1, $this->ledger_rows( $user_id ) );
}

/** Signal C: the meta claims 40 earned and released, the ledger has nothing, and pending_points still holds 40. */
public function test_retry_after_stale_earned_meta(): void {
    $this->create_campaign();
    $user_id = $this->create_customer();
    $order   = $this->create_order( $user_id, [ 40 ], 'completed' );
    // create_order() at completed awards; remove the row to leave only the meta.
    $this->delete_ledger_rows( $user_id );
    $order->update_meta_data( PendingPoints::META_PENDING, 40 );
    $order->update_meta_data( PendingPoints::META_RELEASED_AT, Dates::now() );
    $order->update_meta_data( PendingPoints::META_EARNED, 40 );
    $order->save_meta_data();
    ( new CustomerRepository() )->ensure_row( $user_id );
    ( new CustomerRepository() )->apply_pending_delta( $user_id, 40 );

    $this->assertSame( OrderPointsState::MISSING, $this->fresh_state( $order->get_id() )->get_status() );

    $this->act_as_admin();
    $this->assertSame( 200, $this->send( 'POST', '/yayloyalty/v1/orders/' . $order->get_id() . '/points' )->get_status() );

    $this->assertSame( [ 40 ], $this->ledger_points( $user_id ) );
    $this->assertSame( 40, (int) wc_get_order( $order->get_id() )->get_meta( PendingPoints::META_EARNED ) );
    $this->assertSame( 0, (int) $this->customer_row( $user_id )['pending_points'] );
    $this->assertSame( 40, (int) $this->customer_row( $user_id )['balance'] );
}

public function test_reversed_order_is_not_missing(): void {
    $this->create_campaign();
    $user_id = $this->create_customer();
    $order   = $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );
    $order   = $this->transition( $order, 'cancelled' );
    $order   = $this->transition( $order, 'completed' );

    $this->assertSame( OrderPointsState::NO_POINTS, $this->fresh_state( $order->get_id() )->get_status() );

    $this->act_as_admin();
    $response = $this->send( 'POST', '/yayloyalty/v1/orders/' . $order->get_id() . '/points' );

    $this->assertSame( 'yayloyalty_not_eligible', $response->as_error()->get_error_code() );
    $this->assertSame( [ 40, -40 ], $this->ledger_points( $user_id ) );
}
```

`delete_ledger_rows( int $user_id ): void` is a private helper in the test: a `$wpdb->delete()` on the ledger table for that user, then `balance`, `lifetime_earned` and `pending_points` set to 0 on the customer row. If `create_order( …, 'completed' )` does not award (check `ledger_points()` first), drop the helper call.

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter OrderPointsRetryTest`
Expected: 3 failures (`MISSING` undefined).

- [ ] **Step 3: Rewrite `OrderPointsState::resolve()` per the table above**

Drop the recovery row read and `is_recovery_reversed`. Update the constant names in `OrderPointsMetabox` (`MISSING_KNOWN` → `MISSING`; `MISSING_UNKNOWN`'s link case → `NO_POINTS`; remove the `RECOVERED` case and `render_recovered()`) and `OrderPointsColumn` (`MISSING_KNOWN` → `MISSING`; drop `RECOVERED`).

- [ ] **Step 4: Add `PendingPoints::forget_earned()` and revert `mark_awarded()`**

Remove `restate_earned()` and `write_earned()`; `mark_awarded()` takes its `dev/edd` body back (`git show origin/dev/edd:includes/Engine/Earning/PendingPoints.php`).

- [ ] **Step 5: Implement `OrderPointsRetry::retry()`**

In this order: `PendingPoints::forget_cached()` then re-read the order; `forget_earned()` (the ledger has no EARN row, so the projection is wrong); `OrderAward::run()`; `resync_customer()` for the order's customer; when any outcome is `AWARDED`, add the order note `sprintf( __( 'Points award retried by %s.', 'yayloyalty' ), $actor_display_name )`. No outer transaction: each award commits its own.

- [ ] **Step 6: Rewire the controller and `RestAPI::controllers()`**

`OrderPointsRestController::__construct( OrderPointsRetry $retry, LedgerRepository $ledger, PendingPoints $pending )`. `recover()` (rename `retry()`):

| Case | Response |
|---|---|
| no order | 404 `yayloyalty_order_not_found` |
| state `EARNED` | 409 `yayloyalty_order_has_points` |
| state other than `MISSING` | 409 `yayloyalty_not_eligible` |
| any outcome `AWARDED` | 200, `to_array()` of a fresh read |
| any outcome `DUPLICATE`, or skipped `LedgerService::REASON_ORDER_HAS_POINTS` | 409 `yayloyalty_order_has_points` |
| otherwise | 409 `yayloyalty_not_awarded`, message `sprintf( __( 'No points were awarded: %s. Use YayLoyalty › Customers › Adjust points instead.', 'yayloyalty' ), $first_reason )` |

`controllers()` builds `new OrderPointsRetry( new OrderAward( EarningService::build(), $pending, $ledger ), $pending )`. Remove `default_note()`.

- [ ] **Step 7: Delete `OrderPointsRecovery.php` and `OrderPointsRecoveryTest.php`**

- [ ] **Step 8: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter OrderPointsRetryTest`
Expected: `OK (3 tests, …)`

- [ ] **Step 9: Lint and commit**

Run: `composer lint -- <every PHP file in this task's Files list>`
Expected: no errors or warnings.
Stage: every file in this task's Files list, by name, including the two deletions
Message: `feat(earning): retry the order's award instead of writing a recovery row`

### Task 4: Remove the recovery ledger code

**Files:**
- Modify: `includes/Services/LedgerService.php`
- Modify: `includes/Repositories/LedgerRepository.php`
- Modify: `includes/Engine/Earning/IdempotencyKey.php`, `includes/Engine/Earning/Reversal.php` (back to `dev/edd`)
- Test: `tests/integration/Earning/OrderPointsRetryTest.php`

**Interfaces:**
- Produces: `LedgerService` without `recover_order()`, `is_recovered_order()`, `recovery_key()`, `REASON_RECOVERED`, `MAX_RECOVERY_POINTS`. `REASON_ORDER_HAS_POINTS` stays, documented for the reassigned-order guard only; `is_paid_to_another_customer()` loses its `RECOVERY` check.
- Produces: `LedgerRepository::order_points_summary(): array{earned: int, net: int}`; `find_recovery_for_order()` removed; `net_points_of_others_for_order()` stays.

- [ ] **Step 1: Write the failing test**

```php
public function test_reassigned_order_is_not_paid_twice(): void {
    $this->create_campaign();
    $first  = $this->create_customer( 'first' . wp_rand() . '@example.test' );
    $second = $this->create_customer( 'second' . wp_rand() . '@example.test' );
    $order  = $this->transition( $this->create_order( $first, [ 40 ] ), 'completed' );

    $order = wc_get_order( $order->get_id() );
    $order->set_customer_id( $second );
    $order->save();
    $order = $this->transition( wc_get_order( $order->get_id() ), 'processing' );
    $order = $this->transition( $order, 'completed' );

    $this->assertSame( [ 40 ], $this->ledger_points( $first ) );
    $this->assertSame( [], $this->ledger_points( $second ) );
    $this->assertContains( "No points earned: this order's points already went to another customer.", $this->order_notes( $order ) );
}
```

The guard already exists, so this passes before the removal: run it to confirm, then keep it passing through Step 3.

Run: `composer test:integration -- --filter test_reassigned_order_is_not_paid_twice`
Expected: `OK (1 test, …)`

- [ ] **Step 2: Remove the recovery code**

`git checkout origin/dev/edd -- includes/Engine/Earning/IdempotencyKey.php includes/Engine/Earning/Reversal.php`, then remove the `LedgerService` and `LedgerRepository` members listed in Interfaces. `git grep -n "recover_order\|REASON_RECOVERED\|RECOVERY\|find_recovery_for_order\|MAX_RECOVERY_POINTS\|'reversed'" -- includes` must print nothing.

- [ ] **Step 3: Clear local recovery rows**

On the local site, ledger rows keyed `%:recovery` exist only from testing this branch. Run `SELECT id, user_id, order_id, points FROM wp_yayloyalty_ledger WHERE idempotency_key LIKE '%:recovery'` and report the count to the user; delete them only if the user says so.

- [ ] **Step 4: Verify**

Run: `composer test:integration`
Expected: only the 2 known failures; `OrderPointsRetryTest` `OK (4 tests, …)`.
Run: `composer test`
Expected: `OK (70 tests, …)`.
Run: `composer lint -- includes/Services/LedgerService.php includes/Repositories/LedgerRepository.php tests/integration/Earning/OrderPointsRetryTest.php`
Expected: no errors or warnings.

- [ ] **Step 5: Commit**

Stage: the four PHP files above and the test file
Message: `refactor(earning): drop the recovery ledger row and its guards`

### Task 5: Show what the retry gives, and prefill Adjust points

**Files:**
- Modify: `includes/Engine/Admin/OrderPointsMetabox.php`
- Modify: `apps/admin/src/pages/customers/drawer/CustomerDrawerActions.tsx`
- Modify: `apps/admin/src/pages/customers/components/CustomersAdjustPointsDialog.tsx`

**Interfaces:**
- Consumes: `OrderPointsState::MISSING`, `get_figure()` (Task 3).
- Produces: link `admin.php?page=yayloyalty#/customers/{customer_id}?adjust=1&points={N}`; `points` is optional, a positive integer.
- Produces: `CustomersAdjustPointsDialog` prop `initialAmount?: number`, applied to `amount` (direction `add`) each time the dialog opens; ignored in bulk mode.

- [ ] **Step 1: Render `MISSING` from the award's own preview**

`$now = EarningService::build()->preview( CampaignTrigger::PURCHASE, ContextFactory::from_order( $order ) )`, its points when `is_awarded()`, else 0; a `\InvalidArgumentException` from `from_order()` counts as 0. `$expected = $state->get_figure()`.

| Case | Output |
|---|---|
| `$now > 0` | "This order should have earned %1$d points but they are not in %2$s's balance." (`$now`), then "At checkout it was expected to earn %d points." when `$expected !== $now`, then the button "Give %d points" (`$now`) and the error line |
| `$now = 0` | "No campaign gives this order points any more. At checkout it was expected to earn %d points." (`$expected`), then the Adjust points link with `points=$expected`; no button |

`adjust_points_link( int $customer_id, int $points = 0 )` adds `&points=N` only when `$points > 0`.

- [ ] **Step 2: Prefill the dialog**

In `CustomerDrawerActions.tsx`, read `points` alongside `adjust`, drop both from the URL in the same `setSearchParams` call, and pass `initialAmount` (a positive integer, else undefined) to the dialog.

- [ ] **Step 3: Type-check and lint**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: exit 0.
Run: `pnpm --filter @yayloyalty/admin-page exec eslint src/pages/customers/drawer/CustomerDrawerActions.tsx src/pages/customers/components/CustomersAdjustPointsDialog.tsx`
Expected: no errors. (Node 22 per `.nvmrc`; the eslint tailwind plugin crashes on Node 18.)
Run: `composer lint -- includes/Engine/Admin/OrderPointsMetabox.php`
Expected: no errors or warnings.

- [ ] **Step 4: Verify on the local site (spec acceptance 11, plus the review focus)**

With `./scripts/run.sh dev` running:
1. Add the mu-plugin filter from spec acceptance 11, place and complete an order as a registered customer, remove the mu-plugin. Expected: the orders list shows **Missing**; the box offers **Give N points**.
2. Click it. Expected: the box reads "Earned N points." with the Adjust link; the order notes include "Earned N points (campaign: …)" and "Points award retried by {you}."; the customer's balance rose by N.
3. Set the order to Processing, then Completed. Expected: balance unchanged.
4. Cancel the order. Expected: balance falls by N; set it back to Completed: box shows the Adjust link only, no **Missing** badge.
5. Repeat step 1, then pause the purchase campaign before opening the order. Expected: no button; "No campaign gives this order points any more. At checkout it was expected to earn N points."; the link opens the customer's drawer with Adjust points open and N in the amount field.
6. Repeat step 1, change the campaign's points per currency unit, open the order. Expected: the button offers the new figure and the box says what checkout expected.
7. Run `wp cap remove shop_manager yayloyalty_manage_points` (or remove it from the role in the Users screen), log in as a shop manager. Expected: no "Loyalty points" box; `wp.apiFetch({ path: '/yayloyalty/v1/orders/<id>/points', method: 'POST' })` answers 403. Put the capability back.

- [ ] **Step 5: Commit**

Stage: the three files above
Message: `feat(admin): show the retried award's points and prefill Adjust points`

### Task 6: Signal D, orders the plugin never processed (added 2026-11-04)

**Files:** `includes/Engine/Earning/PendingPoints.php` (`META_AWARD_RAN_AT`, `mark_award_ran()`), `includes/Engine/Earning/OrderAward.php` (`run()` marks the order), `includes/Engine/Earning/OrderPointsState.php` (`resolve( \WC_Order, LedgerRepository, ?OrderAward $award = null )`), `includes/Controllers/OrderPointsRestController.php` and `includes/Engine/RestAPI.php` (the controller takes the shared `OrderAward`), `includes/Engine/Admin/OrderPointsMetabox.php`, `includes/Engine/Admin/OrderPointsColumn.php` (one `OrderAward` per request); tests in `OrderPointsRetryTest.php`.

- [x] Tests `test_order_never_processed_is_missing`, `test_skipped_award_is_not_missing`: `composer test:integration -- --filter OrderPointsRetryTest` → `OK (6 tests, …)`.
- [x] Full suite: only the 2 known failures.
