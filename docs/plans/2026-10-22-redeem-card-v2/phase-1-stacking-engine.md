# Phase 1: Stacking engine

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** a shopper can apply rewards and a points discount together, through today's tabbed card (its "Remove your … to use …" locks removed); the slider is clamped to what the rewards leave; a stacked order commits in one transaction.
**Exit criterion:** `composer test:integration -- --filter 'StackedQuoteTest|CommitWriterTest'` passes and reports the new tests in its count; spec AC 9 by hand on `/e2e-classic-checkout/` (Pro build, fixtures of the spec): Rewards tab tick A and B → Apply, Points discount tab 500 → Apply, place the order → the customer drawer's Ledger tab shows −500, −1,500, −500 and balance 7,350.

### Task 1: Both choices in the session and on the cart

**Files:**
- Modify: `includes/Engine/Redemption/SessionIntent.php`
- Modify: `includes/Engine/Redemption/CouponProvider.php` (`sync_cart()`, `on_removed()`)
- Modify: `includes/Engine/Storefront/RedeemCard.php` (`on_apply()`, `slider_view()`, `has_applied_rewards()`)
- Modify: `includes/Engine/Redemption/Adapters/StoreApiAdapter.php` (`on_update()`, its docblock)
- Modify: `includes/Engine/Redemption/RedemptionService.php` (constants and `message_for()` cases)
- Modify: `includes/Engine/Storefront/templates/redeem-card.php` (`exclusive` branch)
- Modify: `includes/Pro/Storefront/RewardsPanel.php`, `includes/Pro/Storefront/templates/rewards-panel.php` (`is_slider_applied` lock)

**Interfaces:**
- Produces: `SessionIntent::set()` / `set_rewards()` without cross-clearing; `on_apply()` / `on_update()` accepting both keys (README contract).

- [ ] **Step 1: `SessionIntent`**

`set()` stops unsetting `REWARDS_KEY`; `set_rewards()` stops unsetting `KEY`. Rewrite the class and method docblocks: the two keys may coexist; `clear()` (commit only) still drops both.

- [ ] **Step 2: `CouponProvider::sync_cart()` and `on_removed()`**

`$wanted = array_merge( [] !== $reward_ids ? $this->wanted_rewards( $cart ) : [], $points > 0 ? $this->wanted_slider( $cart ) : [] )` — rewards first (percent, fixed), slider last. `on_removed()` for `self::CODE` calls `$this->intent->set( 0 )` instead of `clear()`. Update both docblocks ("Never both" goes).

- [ ] **Step 3: wire format**

`RedeemCard::on_apply()`: when `$_POST['rewards']` is set apply `set_rewards()`; then, independently, when `$_POST['points']` is set apply `set( absint(…) )`; when neither is set, `set( 0 )` as today. `StoreApiAdapter::on_update()`: same with `array_key_exists( 'rewards', $data )` and `array_key_exists( 'points', $data )`. Rewrite both docblocks ("a `rewards` key wins" goes).

- [ ] **Step 4: remove exclusivity from the card**

Delete `RedemptionService::NOTICE_EXCLUSIVE_SLIDER`, `NOTICE_EXCLUSIVE_REWARDS` and their `message_for()` cases; `RedeemCard::slider_view()` returns the view without the `exclusive` substitution and `has_applied_rewards()` goes if unused; the template's `exclusive` branch goes; `RewardsPanel` drops `is_slider_applied` from the view and `rewards-panel.php` drops `$yayloyalty_locked`, its note and `data-locked`. `git grep -n "EXCLUSIVE\|exclusive\|data-locked" includes assets` then shows nothing redemption-related.

- [ ] **Step 5: Verify on the classic cart**

Open `/e2e-classic-cart/` as the fixture customer (Pro build) with a $100 cart.
Action: Rewards tab, tick A → Apply; Points discount tab, enter 500 → Apply; then click "[Remove]" on the "Points discount" totals row.
Expected: after Apply both "$5 off −$5" and "Points discount −$5" rows show (total $90); after Remove only "$5 off" remains and the Rewards tab still shows A ticked. No PHP notice in `wp-content/debug.log`.

- [ ] **Step 6: Lint**

Run: `composer lint -- includes/Engine/Redemption/SessionIntent.php includes/Engine/Redemption/CouponProvider.php includes/Engine/Storefront/RedeemCard.php includes/Engine/Redemption/Adapters/StoreApiAdapter.php includes/Engine/Redemption/RedemptionService.php includes/Engine/Storefront/templates/redeem-card.php includes/Pro/Storefront/RewardsPanel.php includes/Pro/Storefront/templates/rewards-panel.php`
Expected: no errors or warnings in these files.

- [ ] **Step 7: Commit**

Stage the files above.
Message: `feat(redemption): let the points discount and rewards share one cart`

### Task 2: The slider quotes what the rewards leave

**Files:**
- Modify: `includes/Engine/Rewards/Context.php`
- Modify: `includes/Engine/Redemption/RedemptionService.php` (`quote_for()`, `quote_rewards()`, `checkout_error()`)
- Modify: `tests/integration/IntegrationTestCase.php`
- Test: `tests/integration/Engine/Redemption/StackedQuoteTest.php` (new)

**Interfaces:**
- Consumes: Task 1's session.
- Produces: `Context::with_cart_subtotal( float $cart_subtotal ): self`; netted `quote()` / `ceiling()` (README contract).

- [ ] **Step 1: Add the test helper `cart_for( int $user_id, float $price ): \WC_Cart` to `IntegrationTestCase`**

Signs in `$user_id`, initialises `WC()->session` and `WC()->cart` empty, creates and saves a simple product at `$price` (tax status none), adds one, returns `WC()->cart`. Its tearDown counterpart empties the cart and the session keys.

- [ ] **Step 2: Write the failing tests**

Fixtures in the test: `seeded_reward()` (100 Points = $1, min 100, step 100, no percent cap); A = `pro_reward( FIXED_COUPON, 500, [ 'amount' => 5 ] )`; B = `pro_reward( PERCENT_COUPON, 1500, [ 'percent' => 15, 'max_discount' => 10 ] )`; `$service = RedemptionService::build( $intent = new SessionIntent() )`.

```php
public function test_the_slider_alone_is_unchanged(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
    $cart = $this->cart_for( 7, 100.0 );
    $this->intent->set( 500 );

    $this->assertSame( 9850, $this->service->ceiling( $cart, 7 )->get_points() );
    $this->assertSame( 500, $this->service->quote( $cart, 7 )->get_points() );
}

public function test_the_slider_quotes_what_the_rewards_leave(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
    $cart = $this->cart_for( 7, 100.0 );
    $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );
    $this->intent->set( 99999 );

    $ceiling = $this->service->ceiling( $cart, 7 );
    $this->assertSame( 7850, $ceiling->get_points() );          // 9,850 − 2,000; the cart left ($85) allows 8,500
    $this->assertSame( 78.5, $ceiling->get_value() );
    $this->assertSame( Clamp::BOUND_BALANCE, $this->service->quote( $cart, 7 )->get_bound_by() );

    $this->intent->set( 500 );
    $this->assertSame( 500, $this->service->quote( $cart, 7 )->get_points() );
    $this->assertSame( 5.0, $this->service->quote( $cart, 7 )->get_value() );
}

public function test_the_cart_left_binds_when_the_balance_does_not(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 50000 ) );
    $cart = $this->cart_for( 7, 100.0 );
    $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );

    $this->assertSame( 8500, $this->service->ceiling( $cart, 7 )->get_points() ); // $100 − $15
}

public function test_checkout_passes_when_the_rewards_use_the_whole_balance(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 2000 ) );
    $cart = $this->cart_for( 7, 100.0 );
    $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );
    $this->intent->set( 500 );

    $this->assertSame( 0, $this->service->quote( $cart, 7 )->get_points() );
    $this->assertNull( $this->service->checkout_error( $cart, 7 ) );
}

public function test_checkout_stops_when_the_rewards_are_not_covered(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1900 ) );
    $cart = $this->cart_for( 7, 100.0 );
    $this->intent->set_rewards( [ $this->a->get_id(), $this->b->get_id() ] );
    $this->intent->set( 500 );

    $this->assertSame( RedemptionService::REASON_REWARDS_BALANCE, $this->service->checkout_error( $cart, 7 ) );
}
```

- [ ] **Step 3: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter StackedQuoteTest`
Expected: `test_the_slider_alone_is_unchanged` passes; the other four FAIL (ceiling 9,850, checkout error `reward_min` or `balance`).

- [ ] **Step 4: `Context::with_cart_subtotal( float $cart_subtotal ): self`**

A clone with only `cart_subtotal` replaced, like `with_balance()`.

- [ ] **Step 5: Net the slider in `quote_for()`, memoise `quote_rewards()`**

`quote_for()` builds the cart context as today, then — when `quote_rewards()` returns any row whose quote is allowed with `get_value() > 0` — applies `with_balance( balance − Σ get_points() )` and `with_cart_subtotal( max( 0.0, subtotal − Σ get_value() ) )` before quoting. `quote_rewards()` keeps its result in a private property keyed on `implode( ',', $reward_ids ) . '|' . $cart->get_cart_hash() . '|' . implode( ',', merchant coupon codes )`, recomputed when the key changes. Update both docblocks.

- [ ] **Step 6: `checkout_error()`**

When rewards are picked, `rewards_error()` first and return it when non-null. Then the slider's rules as today, except: a slider quote that is allowed with 0 points returns null when at least one picked reward's quote is allowed with a value. Rewrite the docblock.

- [ ] **Step 7: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'StackedQuoteTest|CommitWriterTest|ClassicCheckoutAdapterTest|ReversalTest'`
Expected: PASS; the count includes the five new tests.

- [ ] **Step 8: Verify the memo holds**

Temporarily add `Log::debug( 'RedemptionService', 'quote_rewards miss' )` in the recompute branch, load `/e2e-classic-cart/` with A, B and 500 Points applied, then remove the line.
Expected: at most 2 misses per page load in the WooCommerce log (`yayloyalty` source), not one per coupon load.

- [ ] **Step 9: Lint and commit**

Run: `composer lint -- includes/Engine/Rewards/Context.php includes/Engine/Redemption/RedemptionService.php tests/integration/IntegrationTestCase.php tests/integration/Engine/Redemption/StackedQuoteTest.php` — no errors or warnings in these files.
Stage those four files.
Message: `feat(redemption): clamp the points discount to what applied rewards leave`

### Task 3: One commit for a stacked order

**Files:**
- Modify: `includes/Engine/Redemption/CommitWriter.php`
- Modify: `includes/Engine/Redemption/RedemptionService.php` (`commit()`)
- Test: `tests/integration/Engine/Redemption/CommitWriterTest.php`

**Interfaces:**
- Consumes: Task 2's netting (the cart puts lines on the order that `commit_all()` must re-quote to).
- Produces: `CommitWriter::commit_all( \WC_Order $order, array $rewards, Reward $flexible ): Outcome` (README contract).

- [ ] **Step 1: Write the failing tests**

A helper `order_with_a_b_and_points()` builds, with `order_with_reward_lines( 7, 100.0, [ A code => 5.0, B code => 10.0, 'yayloyalty-redeem' => 5.0 ] )`, the rewards map of A and B, and uses `$this->reward()` as the flexible reward.

```php
public function test_a_stacked_order_writes_every_row_in_one_commit(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
    list( $order, $rewards ) = $this->order_with_a_b_and_points();
    list( $a, $b )           = array_values( $rewards );

    $outcome = $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );

    $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
    $this->assertSame( [ 9850, -500, -1500, -500 ], $this->ledger_points( 7 ) );
    $this->assertSame( 7350, (int) $this->customer_row( 7 )['balance'] );
    $rows = $this->redemption_rows( 7 );
    $this->assertSame( [ 500, 1500, 500 ], array_map( 'intval', array_column( $rows, 'points' ) ) );
    $this->assertSame( [ 5.0, 10.0, 5.0 ], array_map( 'floatval', array_column( $rows, 'value' ) ) );
    $this->assertSame( 'yayloyalty-redeem', $rows[2]['coupon_code'] );
    $keys = array_column( array_slice( $this->ledger_rows( 7 ), 1 ), 'idempotency_key' );
    $this->assertSame(
        [
            sprintf( 'redeem:7:order:%d:reward_%d', $order->get_id(), $a->get_id() ),
            sprintf( 'redeem:7:order:%d:reward_%d', $order->get_id(), $b->get_id() ),
            sprintf( 'redeem:7:order:%d:flexible_discount', $order->get_id() ),
        ],
        $keys
    );
    $saved = wc_get_order( $order->get_id() );
    $this->assertSame( 2500, (int) $saved->get_meta( CommitWriter::META_POINTS ) );
    $this->assertSame( 20.0, (float) $saved->get_meta( CommitWriter::META_VALUE ) );
}

public function test_a_second_stacked_commit_writes_nothing(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
    list( $order, $rewards ) = $this->order_with_a_b_and_points();

    $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );
    $second = $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );

    $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
    $this->assertCount( 3, $this->redemption_rows( 7 ) );
    $this->assertSame( 7350, (int) $this->customer_row( 7 )['balance'] );
}

public function test_a_stacked_order_above_the_balance_writes_nothing(): void {
    $this->ledger_service()->earn( $this->earn_draft( 7, 100, 2200 ) );
    list( $order, $rewards ) = $this->order_with_a_b_and_points();

    $outcome = $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );

    $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
    $this->assertSame( [ 2200 ], $this->ledger_points( 7 ) );
    $this->assertCount( 0, $this->redemption_rows( 7 ) );
    $this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `composer test:integration -- --filter CommitWriterTest`
Expected: the three new tests FAIL (`commit_all` undefined); the existing ones pass.

- [ ] **Step 3: Implement `CommitWriter::commit_all( \WC_Order $order, array $rewards, Reward $flexible ): Outcome`**

One `Transaction::run()`, `ensure_and_lock()` once. Order of checks: no customer → `skipped( no_customer )`; every reward code and the slider already have rows → `duplicate` (answer the slider's existing debit, as `commit()` does); each reward line re-quoted as in `commit_rewards()` (null reward, denied or worth nothing → `reward_unavailable`); the slider re-quoted with `from_order( $order, $flexible, $locked − Σ reward cost )` with `commit()`'s points and value checks (its bound or `insufficient_balance` as the reason); `Σ all costs > locked` → `rewards_balance`. Then insert reward rows and debits in line order, the slider's row and debit last, `freeze()` for the slider, summed `META_POINTS` / `META_VALUE`, one `save()`. A non-AWARDED debit rolls back all, as in `commit_rewards()`. Extract the per-reward-line quote and the per-row insert-and-debit that `commit()` and `commit_rewards()` already do into private helpers shared by all three, rather than copying them. Docblock lists the postconditions as `commit_rewards()`'s does.

- [ ] **Step 4: Route stacked orders in `RedemptionService::commit()`**

Replace the `$has_slider && [] !== $reward_codes` branch: `$reward = $this->flexible_reward()`; null → `skipped( REASON_NO_REWARD )`, else `$this->writer->commit_all( $order, $this->offered_rewards_of( $reward_codes ), $reward )`. Rewrite the docblock line "An order carrying both kinds is skipped".

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `composer test:integration -- --filter 'CommitWriterTest|ReversalTest|StackedQuoteTest'`
Expected: PASS; the count includes the three new tests.

- [ ] **Step 6: Lint and commit**

Run: `composer lint -- includes/Engine/Redemption/CommitWriter.php includes/Engine/Redemption/RedemptionService.php tests/integration/Engine/Redemption/CommitWriterTest.php` — no errors or warnings in these files.
Stage those three files.
Message: `feat(redemption): commit a stacked order in one transaction`

- [ ] **Step 7: Phase exit**

Run the exit criterion at the top of this file, then spec AC 11 by hand: cancel that order → the Ledger tab shows +500, +1,500, +500 and balance 9,850; all three rows `cancelled` in the drawer's Redemptions tab.
