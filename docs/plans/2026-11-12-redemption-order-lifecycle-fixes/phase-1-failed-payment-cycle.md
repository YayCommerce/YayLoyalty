# Phase 1: Failed Payment Cycle

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** an order that moves to `failed` returns the points it redeemed. Pay-for-order (classic and block) debits them again before the gateway, and refuses on a short balance. A late `failed` → `on-hold`/`processing`/`completed` debits them again even into a negative balance.
**Exit criterion:**
- `composer test:integration -- --filter PaymentFailedTest` prints `OK (` with 0 failures.
- On brandy.test, as a customer with points: place an order that redeems points, then set it to Failed in wp-admin. The balance returns, and the note "Restored … redeemed points (payment failed)." appears.
- From My Account → Orders → Pay, pay with Check payments. The balance drops by the same points, and the order goes to on-hold.

The tests below share one fixture, modelled on `ReversalTest::redeemed_order()`. User 7 earns 1000, then spends 500 through the flexible discount on an order of 20.00 that totals 15.00. Call that order O, with its redemption row and its `redeem` ledger row.

### Task 1: Status, reference type and ledger lookup

**Files:**
- Modify: `includes/Enums/ReferenceType.php`
- Modify: `includes/Models/Redemption.php`
- Modify: `includes/Repositories/LedgerRepository.php`
- Modify: `apps/admin/src/lib/schema/redemption.schema.ts`
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.copy.ts`
- Modify: `apps/admin/src/pages/customers/drawer/customer-drawer.redemption-rows.ts`

**Interfaces:**
- Produces: `ReferenceType::LEDGER`, `Redemption::STATUS_UNPAID` and `LedgerRepository::latest_for_redemption()` (see README contract).

- [ ] **Step 1: Add `public const LEDGER = 'ledger';` to `ReferenceType`, and add it to `all()`**

Docblock: "yayloyalty_ledger id: the row a failed-payment restore or a pay-again debit undoes."

- [ ] **Step 2: Add `public const STATUS_UNPAID = 'unpaid';` to `Redemption`, and add it to `STORED_STATUSES`**

Rewrite the status docblock:
- applied ⇄ unpaid (payment failed, points returned; paid again, points debited again);
- applied | unpaid → cancelled | refunded | removed;
- `removed` is final, whether an admin restored what was left or chose not to.

- [ ] **Step 3: Add `latest_for_redemption( int $redemption_id, string $type ): ?LedgerEntry` to `LedgerRepository`**

Use `select_entries( 'type = %s AND redemption_id = %d', … )` ordered by `id DESC` with a limit of 1. Follow the existing `select_entries` callers. If it takes no ORDER BY, write the prepared query in the same style as `find_by_idempotency_key()`.

- [ ] **Step 4: Add the status to the SPA**

- `REDEMPTION_STATUSES` becomes `['applied', 'unpaid', 'refunded', 'cancelled', 'removed']`.
- Add `export const REDEMPTION_STATUS_UNPAID = () => __('Returned: payment failed', 'yayloyalty'); // status \`unpaid\`` to `customer-drawer.copy.ts`.
- Add `case 'unpaid': return REDEMPTION_STATUS_UNPAID();` to the switch in `customer-drawer.redemption-rows.ts`.

- [ ] **Step 5: Verify**

Run: `php -l` on the three PHP files, then `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`.
Expected: "No syntax errors detected" for each file, and tsc exits 0. A `switch` over `RedemptionStatus` without the new case would fail tsc.

Run: `composer lint -- includes/Enums/ReferenceType.php includes/Models/Redemption.php includes/Repositories/LedgerRepository.php`
Expected: no findings on the changed lines.

- [ ] **Step 6: Commit**

Stage: the six files above.
Message: `feat(redemption): add the unpaid status and ledger reference type`

### Task 2: Reversal's failed and pay-again events

**Files:**
- Modify: `includes/Engine/Redemption/Reversal.php`
- Test: `tests/integration/Engine/Redemption/PaymentFailedTest.php` (new)

**Interfaces:**
- Consumes: Task 1's contract entries.
- Produces: `payment_failed()`, `payment_retried()` and `has_unpaid()` (see README contract).

- [ ] **Step 1: Write the failing tests**

`PaymentFailedTest extends IntegrationTestCase`. Its `reversal()`, `redeemed_order()`, `row()` and `last_ledger_row()` helpers are copied from `ReversalTest`. It also has `redeem_row_id(): int`, which returns `(int) $this->ledger_rows( 7 )[1]['id']`.

```php
public function test_a_failed_payment_restores_and_marks_the_row_unpaid(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );

    $outcomes = $this->reversal()->payment_failed( $order );

    $this->assertSame( Outcome::AWARDED, $outcomes[0]->get_status() );
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_UNPAID, $this->row()['status'] );
    $this->assertSame( 500, (int) $this->row()['restored_points'] );
    $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );
    $this->assertSame( sprintf( 'refund_redeem:7:ledger:%d:flexible_discount', $this->redeem_row_id() ), $this->last_ledger_row()['idempotency_key'] );
}

public function test_a_repeated_failure_writes_nothing(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );

    $outcomes = $this->reversal()->payment_failed( $order );

    $this->assertSame( Outcome::SKIPPED, $outcomes[0]->get_status() );
    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
}

public function test_a_retry_debits_again_under_a_chained_key(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );
    $restore_id = (int) $this->last_ledger_row()['id'];

    $this->reversal()->payment_retried( $order, false );

    $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
    $this->assertSame( 0, (int) $this->row()['restored_points'] );
    $this->assertSame( sprintf( 'redeem:7:ledger:%d:flexible_discount', $restore_id ), $this->last_ledger_row()['idempotency_key'] );
}

public function test_two_failed_cycles_each_write_their_own_rows(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );

    $this->reversal()->payment_failed( $order );
    $this->reversal()->payment_retried( $order, false );
    $this->reversal()->payment_failed( $order );
    $this->reversal()->payment_retried( $order, false );

    $this->assertSame( [ 1000, -500, 500, -500, 500, -500 ], $this->ledger_points( 7 ) );
    $keys = array_column( $this->ledger_rows( 7 ), 'idempotency_key' );
    $this->assertSame( $keys, array_unique( $keys ) );
    $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
}

public function test_retrying_twice_debits_once(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );

    $this->reversal()->payment_retried( $order, false );
    $outcomes = $this->reversal()->payment_retried( $order, false );

    $this->assertSame( Outcome::SKIPPED, $outcomes[0]->get_status() );
    $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
}

public function test_a_short_balance_refuses_a_strict_retry_and_writes_nothing(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );
    $this->ledger_service()->adjust( 7, -600, 'spent elsewhere', 1 );

    $outcomes = $this->reversal()->payment_retried( $order, false );

    $this->assertSame( Result::REASON_INSUFFICIENT_BALANCE, $outcomes[0]->get_reason() );
    $this->assertSame( 400, (int) $this->customer_row( 7 )['balance'] );
    $this->assertSame( Redemption::STATUS_UNPAID, $this->row()['status'] );
}

public function test_a_lenient_retry_goes_negative(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );
    $this->ledger_service()->adjust( 7, -600, 'spent elsewhere', 1 );

    $this->reversal()->payment_retried( $order, true );

    $this->assertSame( -100, (int) $this->customer_row( 7 )['balance'] );
    $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
}

public function test_a_cancel_of_an_unpaid_order_writes_nothing_and_an_uncancel_debits_once(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );

    $this->reversal()->cancelled( $order );

    $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );

    $this->reversal()->uncancelled( $order );

    $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( sprintf( 'redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $this->last_ledger_row()['idempotency_key'] );
}

public function test_has_unpaid_reads_the_rows(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->assertFalse( $this->reversal()->has_unpaid( $order ) );

    $this->reversal()->payment_failed( $order );

    $this->assertTrue( $this->reversal()->has_unpaid( $order ) );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter PaymentFailedTest`
Expected: errors, "Call to undefined method …Reversal::payment_failed()".

- [ ] **Step 3: Implement in `Redemption\Reversal`**

- `payment_failed( \WC_Order $order ): array`
  - Runs `settle_each( $order_id, [ STATUS_APPLIED ], … )`.
  - It finds the key from `$this->ledger_rows->latest_for_redemption( row id, LedgerType::REDEEM )`. That is a new last constructor parameter, `LedgerRepository $ledger_rows`. Every construction site passes `new LedgerRepository()`:
    - `RedemptionService::build()` (`includes/Engine/Redemption/RedemptionService.php:125`);
    - `ReversalTest::reversal()`;
    - `LoyaltyCodeOnOrderTest::reversal()`;
    - `OrderReversalListenerTest` line 58.
  - Then it calls `restore( $row, $row->get_points(), STATUS_UNPAID, [ [ ReferenceType::LEDGER, id ] ], 'Payment failed' )`.
  - When `restored_points` already equals `points`, it calls `record_restore( id, points, STATUS_UNPAID )` and answers `REASON_NOTHING_TO_MOVE`.
  - When no `REDEEM` row exists, it answers `REASON_NOTHING_TO_MOVE` and logs `Log::warning()`.
- `payment_retried( \WC_Order $order, bool $allow_negative ): array`
  - Runs over `[ STATUS_UNPAID ]`.
  - It takes the key id from `latest_for_redemption( id, LedgerType::REFUND_REDEEM )` and calls `debit( $row, $this->refund_target( $order, $row ), ReferenceType::LEDGER, id, $allow_negative, 'Paid again' )`. `debit()` already sets `STATUS_APPLIED`.
  - **Strict mode** (`$allow_negative` false): wrap the whole `settle_each()` call in one `Transaction::run()`. When any outcome's reason is `REASON_INSUFFICIENT_BALANCE`, throw a `\DomainException` inside it so every row rolls back. Catch it outside and return `Outcome::skipped( REASON_INSUFFICIENT_BALANCE )` once per row.
- `cancelled()` lists `[ STATUS_APPLIED, STATUS_UNPAID ]`. For an `unpaid` row, `restore()` finds nothing to move. Add an explicit branch there: `record_restore( id, restored_points, STATUS_CANCELLED )`, answering `REASON_NOTHING_TO_MOVE`.
- `has_unpaid( \WC_Order $order ): bool`: any row from `for_order()` that is `unpaid`.
- Update the class docblock with the failed and pay cycle, and say that its keys chain on the ledger row each move undoes.

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'PaymentFailedTest|ReversalTest|LoyaltyCodeOnOrderTest|OrderReversalListenerTest'`
Expected: `OK (` with 0 failures. `ReversalTest` still passes, which proves the cancel and refund keys did not change.

- [ ] **Step 5: Commit**

Stage: `includes/Engine/Redemption/Reversal.php`, `includes/Engine/Redemption/RedemptionService.php`, `tests/integration/Engine/Redemption/PaymentFailedTest.php`, `tests/integration/Engine/Redemption/ReversalTest.php`, `tests/integration/Engine/Redemption/LoyaltyCodeOnOrderTest.php`, `tests/integration/Engine/Earning/OrderReversalListenerTest.php`
Message: `feat(redemption): return redeemed points on a failed payment and take them on retry`

### Task 3: Route the order statuses

**Files:**
- Modify: `includes/Engine/Earning/Listeners/OrderListener.php`
- Modify: `includes/Engine/Earning/Listeners/OrderReversalListener.php`
- Test: `tests/integration/Engine/Redemption/PaymentFailedTest.php`

**Interfaces:**
- Consumes: `payment_failed()` and `payment_retried()` from Task 2.
- Produces: `on_failed()` and `on_paid_after_failure()` (see README contract).

- [ ] **Step 1: Write the failing tests** (status changes through `$order->update_status()`, so the real listeners run)

```php
public function test_the_failed_status_restores_and_notes(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );

    $order->update_status( 'failed' );

    $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );
    $this->assertContains( 'Restored 500 redeemed points (payment failed).', $this->note_texts( $order ) );
}

public function test_failed_to_processing_debits_again_and_failed_to_pending_does_not(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $order->update_status( 'failed' );

    $order->update_status( 'pending' );
    $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );

    $order->update_status( 'failed' );
    $order->update_status( 'processing' );
    $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
    $this->assertContains( 'Debited 500 redeemed points again (payment received).', $this->note_texts( $order ) );
}

public function test_failed_then_processing_in_one_request_nets_one_debit(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );

    $order->update_status( 'failed' );
    $order->update_status( 'processing' );

    $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
}
```

`note_texts( \WC_Order $order ): string[]` maps `wc_get_order_notes( [ 'order_id' => … ] )` to their `content`.

- [ ] **Step 2: Run to confirm they fail**

Run: `composer test:integration -- --filter PaymentFailedTest`
Expected: the three new tests fail on the balance assertions; the Task 2 tests pass.

- [ ] **Step 3: Implement**

- `OrderListener`:
  - Add `private const PAID_STATUSES = [ 'on-hold', 'processing', 'completed' ];`.
  - In `on_status_changed()`, before `route()`: when `'failed' === $from` and `$to` is in `PAID_STATUSES`, call `ListenerGuard::for_order( 'OrderListener', 'paid after failure', $order_id, fn() => $this->reversals->on_paid_after_failure( $order ) )`.
  - In `route()`, move `'failed'` out of the no-op case into its own case, which calls `ListenerGuard::for_order( 'OrderListener', 'failed', … on_failed( $order ) )`.
  - Update the class docblock.
- `OrderReversalListener`:
  - `on_failed()` calls `$this->pending->release( order id )`, then `$outcomes = $this->redemptions->payment_failed( $order )`, then `note_restored( $order, $outcomes, __( 'payment failed', 'yayloyalty' ) )`.
  - `on_paid_after_failure()` calls `payment_retried( $order, true )`, then `note_debited( $order, $outcomes, __( 'payment received', 'yayloyalty' ) )`.

- [ ] **Step 4: Run to confirm they pass**

Run: `composer test:integration -- --filter 'PaymentFailedTest|OrderReversalListenerTest|ReversalFlowTest|PurchaseLifecycleTest'`
Expected: `OK (` with 0 failures.

- [ ] **Step 5: Commit**

Stage: `includes/Engine/Earning/Listeners/OrderListener.php`, `includes/Engine/Earning/Listeners/OrderReversalListener.php`, `tests/integration/Engine/Redemption/PaymentFailedTest.php`
Message: `feat(earning): route failed orders to the redemption restore`

### Task 4: Pay-for-order debits first

**Files:**
- Create: `includes/Engine/Redemption/PayForOrderRedebit.php`
- Modify: `includes/Engine/Redemption/Adapters/StoreApiAdapter.php`
- Modify: `includes/YayLoyalty.php`
- Test: `tests/integration/Engine/Redemption/PaymentFailedTest.php`

**Interfaces:**
- Consumes: `payment_retried()` and `has_unpaid()` from Task 2.
- Produces: `PayForOrderRedebit` (see README contract).

- [ ] **Step 1: Write the failing tests**

```php
public function test_pay_for_order_refuses_a_short_balance(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );
    $this->ledger_service()->adjust( 7, -600, 'spent elsewhere', 1 );

    $message = ( new PayForOrderRedebit( $this->reversal() ) )->redebit_or_message( $order );

    $this->assertSame( 'You no longer have enough points for the points discount on this order.', $message );
    $this->assertSame( 400, (int) $this->customer_row( 7 )['balance'] );
}

public function test_pay_for_order_debits_when_the_balance_covers_it(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );

    $this->assertNull( ( new PayForOrderRedebit( $this->reversal() ) )->redebit_or_message( $order ) );
    $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
}

public function test_the_block_pay_for_order_answers_409_on_a_short_balance(): void {
    $order = $this->redeemed_order( $this->seeded_reward() );
    $this->reversal()->payment_failed( $order );
    $this->ledger_service()->adjust( 7, -600, 'spent elsewhere', 1 );

    try {
        do_action( 'woocommerce_store_api_checkout_order_processed', $order );
        $this->fail( 'No RouteException' );
    } catch ( RouteException $e ) {
        $this->assertSame( 'yayloyalty_redeem_failed', $e->getErrorCode() );
        $this->assertSame( 409, $e->getCode() );
    }
}
```

- [ ] **Step 2: Run to confirm they fail**

Run: `composer test:integration -- --filter PaymentFailedTest`
Expected: errors, "Class …PayForOrderRedebit not found"; the 409 test fails with "No RouteException".

- [ ] **Step 3: Implement**

- `PayForOrderRedebit` (final, constructor `( Reversal $reversal )`):
  - `register()` hooks `woocommerce_before_pay_action` → `before_pay( \WC_Order $order ): void`.
  - `redebit_or_message()`:
    - returns null when `! has_unpaid( $order )`;
    - otherwise runs `payment_retried( $order, false )`;
    - returns the README message when any outcome's reason is `REASON_INSUFFICIENT_BALANCE`, else null.
  - `before_pay()`: on a message, `wc_add_notice( $message, 'error' )`, then `wp_safe_redirect( $order->get_checkout_payment_url() )` and `exit`. The docblock says why: `WC_Form_Handler::pay_action()` ignores notices added on this hook.
- `StoreApiAdapter`:
  - Takes `PayForOrderRedebit $redebit` as a new last constructor parameter.
  - `on_order_processed()` first calls `$this->redebit->redebit_or_message( $order )`. A message throws `RouteException( 'yayloyalty_redeem_failed', $message, 409 )` before `commit()`.
- `YayLoyalty::register_redemption()`:
  - Build `$redebit = new PayForOrderRedebit( $service->reversal() );`, call `$redebit->register();` and pass `$redebit` to `StoreApiAdapter`.
  - Not behind `is_admin()`.

- [ ] **Step 4: Run to confirm they pass**

Run: `composer test:integration -- --filter 'PaymentFailedTest|ClassicCheckoutAdapterTest|CommitWriterTest'`
Expected: `OK (` with 0 failures.

- [ ] **Step 5: Verify the classic redirect by hand on brandy.test**

Steps:
1. As customer #4662, give the customer 300 points (YayLoyalty → Customers → Adjust).
2. Place a checkout order that redeems the "Giảm 50.000₫" reward with Check payments.
3. Set the order to Failed in wp-admin. Expected: the balance is back at 300.
4. Adjust the customer by −200.
5. Open My Account → Orders → Pay and submit.

Expected:
- the page reloads on the same order-pay URL with "You no longer have enough points for the points discount on this order.";
- the order is still Failed;
- no new ledger row exists for the order.

Then adjust the customer by +200 and pay again. Expected: the order goes to On hold, and the balance is 0.

- [ ] **Step 6: Lint and commit**

Run: `composer lint -- includes/Engine/Redemption/PayForOrderRedebit.php includes/Engine/Redemption/Adapters/StoreApiAdapter.php includes/YayLoyalty.php includes/Engine/Redemption/Reversal.php includes/Engine/Earning/Listeners/OrderListener.php includes/Engine/Earning/Listeners/OrderReversalListener.php`
Expected: no findings on changed lines.

Stage: `includes/Engine/Redemption/PayForOrderRedebit.php`, `includes/Engine/Redemption/Adapters/StoreApiAdapter.php`, `includes/YayLoyalty.php`, `tests/integration/Engine/Redemption/PaymentFailedTest.php`
Message: `feat(redemption): debit redeemed points again before pay-for-order`
