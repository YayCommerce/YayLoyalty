# Phase 1: Product page

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** The product page evaluates what it knows, and an unknown condition lowers the figure instead of being skipped.
**Exit criterion:** `composer test` passes, and spec criteria 2 to 6 hold on a Pro site.

### Task 1: Three-state verdict in the evaluator

**Files:**
- Modify: `includes/Enums/CampaignConditionType.php`
- Modify: `includes/Pro/Services/ConditionsEvaluator.php`
- Test: `tests/unit/Pro/Services/ConditionsEvaluatorVerdictTest.php`

**Interfaces:**
- Produces: `INPUT_BASKET`, `verdict()` (see README contract)

- [ ] **Step 1: Write the failing test**

Build each Context with `new Context()` directly: `order_id` null, lines as stated. `EngineTestCase::context()` always sets order 555.

```php
public function test_category_is_known_from_the_lines_without_an_order(): void {
    $conditions = [ [ 'type' => 'category_in_cart', 'operator' => 'in', 'value' => [ 5 ] ] ];

    $this->assertTrue( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0, null, 10, [ 5 ] ) ] ) ) );
    $this->assertFalse( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0, null, 10, [ 6 ] ) ] ) ) );
}

public function test_payment_method_is_unknown_without_an_order(): void {
    $conditions = [ [ 'type' => 'payment_method', 'operator' => 'in', 'value' => [ 'stripe' ] ] ];

    $this->assertNull( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0 ) ] ) ) );
}

public function test_a_failed_condition_beats_an_unknown_one(): void {
    $conditions = [
        [ 'type' => 'payment_method', 'operator' => 'in', 'value' => [ 'stripe' ] ],
        [ 'type' => 'category_in_cart', 'operator' => 'in', 'value' => [ 5 ] ],
    ];

    $this->assertFalse( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0, null, 10, [ 6 ] ) ] ) ) );
}

public function test_a_guest_fails_a_customer_condition(): void {
    $conditions = [ [ 'type' => 'user_role', 'operator' => 'in', 'value' => [ 'customer' ] ] ];

    $this->assertFalse( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0 ) ], null ) ) );
}

public function test_no_order_and_no_lines_still_reports_needs_order(): void {
    $conditions = [ [ 'type' => 'product_in_cart', 'operator' => 'in', 'value' => [ 10 ] ] ];

    $this->assertSame( 'needs_order', $this->evaluator->explain( $conditions, $this->shopping( [] ) )[0]['reason'] );
}
```

`shopping( array $lines, ?int $user_id = 7 )` is a private helper in the test.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter ConditionsEvaluatorVerdictTest`
Expected: FAIL, `verdict` not defined

- [ ] **Step 3: Add `INPUT_BASKET = 'basket'` and set it as `input` on `PRODUCT_IN_CART`, `CATEGORY_IN_CART` and `CURRENCY` in `schema()`**

- [ ] **Step 4: In `ConditionsEvaluator::missing_input()`, return `REASON_NEEDS_ORDER` for `INPUT_BASKET` only when `null === $ctx->get_order_id()` and `[] === $ctx->get_line_items()`**

- [ ] **Step 5: Implement `verdict( array $conditions, Context $ctx ): ?bool`**

Folds `explain()`: `false` if any result is unmatched with a reason other than `needs_order`; else `null` if any has `needs_order`; else `true`.

- [ ] **Step 6: Run the unit suite**

Run: `composer test`
Expected: PASS

- [ ] **Step 7: Commit**

Stage: the three files above
Message: `feat(campaigns): add a three-state verdict for conditions`

### Task 2: Estimate walk in the engine

**Files:**
- Modify: `includes/Engine/Points/Context.php`
- Modify: `includes/Engine/Points/PointsEngine.php`
- Test: `tests/unit/Engine/Points/PointsEngineEstimateTest.php`

**Interfaces:**
- Consumes: `ConditionsEvaluator::verdict()`
- Produces: `Context::as_estimate()`, `is_estimate()`, private `PointsEngine::verdict()` (see README contract)

- [ ] **Step 1: Write the failing test**

The test attaches a stub evaluator. Its `evaluate()` always returns true. Its `verdict()` adds to a public `$verdict_calls` and answers by `$conditions[0]['type']`: `'yes'` is `true`, `'no'` is `false`, `'unknown'` is `null`. `conditional( string $type, float $rate, int $id )` builds a purchase campaign at priority 10 with `[ [ 'type' => $type ] ]`. `everyone( float $rate )` is `purchase_campaign( [ 'points_per_unit' => $rate ], 0, 9, Status::ACTIVE, null, null, 'everyone' )`. Every line is `$this->line( 100.0 )`.

```php
public function test_a_true_verdict_pays_its_own_rate(): void {
    $this->assertSame( 300, $this->estimate( [ $this->conditional( 'yes', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
}

public function test_a_false_verdict_is_skipped(): void {
    $this->assertSame( 100, $this->estimate( [ $this->conditional( 'no', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
}

public function test_an_unknown_campaign_paying_less_lowers_the_figure(): void {
    $this->assertSame( 50, $this->estimate( [ $this->conditional( 'unknown', 0.5, 2 ), $this->everyone( 1 ) ] )->get_points() );
}

public function test_an_unknown_campaign_paying_more_keeps_the_figure(): void {
    $this->assertSame( 100, $this->estimate( [ $this->conditional( 'unknown', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
}

public function test_an_unknown_campaign_paying_nothing_promises_nothing(): void {
    $result = $this->estimate( [ $this->conditional( 'unknown', 0, 2 ), $this->everyone( 1 ) ] );

    $this->assertFalse( $result->is_awarded() );
    $this->assertSame( Result::REASON_ZERO_POINTS, $result->get_reason() );
}

public function test_only_unknown_campaigns_promise_nothing(): void {
    $result = $this->estimate( [ $this->conditional( 'unknown', 3, 2 ) ] );

    $this->assertFalse( $result->is_awarded() );
    $this->assertSame( Result::REASON_NOT_ELIGIBLE, $result->get_reason() );
}

public function test_a_matches_subscriber_can_skip_a_true_verdict(): void {
    add_filter( 'yayloyalty_campaign_matches', static fn( $matches, Campaign $campaign ) => 2 === $campaign->get_id() ? false : $matches, 10, 2 );

    $this->assertSame( 100, $this->estimate( [ $this->conditional( 'yes', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
}

public function test_an_event_context_never_asks_for_a_verdict(): void {
    $engine = PointsEngine::build( $this->repository( [ $this->conditional( 'unknown', 3, 2 ), $this->everyone( 1 ) ] ) );

    // The stub's evaluate() returns true for every type, so the event pays the conditional campaign.
    $this->assertSame( 300, $engine->evaluate( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 100.0 ) ] ) )->get_points() );
    $this->assertSame( 0, $this->evaluator->verdict_calls );
}
```

`estimate( array $campaigns ): Result` builds the engine and calls `evaluate()` with `$this->context( [ $this->line( 100.0 ) ] )->as_estimate()`.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter PointsEngineEstimateTest`
Expected: FAIL, `as_estimate` not defined

- [ ] **Step 3: Add `private $estimate = false`, `as_estimate(): self` (sets the flag on a clone) and `is_estimate(): bool` to `Context`**

- [ ] **Step 4: Extract the `apply_filters` from `PointsEngine::matches()` into `private function filtered_match( bool $matches, Campaign $campaign, Context $ctx ): bool`**

- [ ] **Step 5: Add `private function verdict( Campaign $campaign, Context $ctx ): ?bool`**

`applies_to_everyone()` gives `filtered_match( true, … ) ? true : false`. An advert with no user gives `null`. Otherwise call the evaluator's `verdict()`, and when that is `true`, pass it through `filtered_match()` the same way.

- [ ] **Step 6: In `evaluate()`, after the empty check, branch to `private function estimate( TriggerHandler $handler, iterable $candidates, Context $ctx ): Result` when `$ctx->is_estimate()`**

For each candidate: skip on `false` or when `is_eligible()` fails; compute points with the handler and caps; track the lowest with its campaign and breakdown; stop on `true`. No `true` reached: `REASON_NOT_ELIGIBLE`. Lowest at 0 or under: `REASON_ZERO_POINTS`. Otherwise `Result::awarded()` for the lowest. Update the docblocks of `CONDITIONS_EVALUATOR_FILTER`, `conditions_evaluator()` and `yayloyalty_campaign_matches`.

- [ ] **Step 7: Run the unit suite**

Run: `composer test`
Expected: PASS, with `PointsEngineAdvertisedTest` unchanged

- [ ] **Step 8: Commit**

Stage: the three files above
Message: `feat(campaigns): estimate points with unknown conditions lowering the figure`

### Task 3: Product and cart Contexts become estimates

**Files:**
- Modify: `includes/Engine/Points/ContextFactory.php`
- Modify: `docs/architecture.md`

**Interfaces:**
- Consumes: `Context::as_estimate()`
- Produces: private `ContextFactory::shared_facts()` (see README contract)

- [ ] **Step 1: Add `private static function shared_facts( int $user_id ): ?CustomerFacts`**

A `private static $shared_facts = []` memo by user id, filled from `customer_facts( $user_id, Dates::now() )`. Null for a user below 1.

- [ ] **Step 2: In `from_product()` and `from_cart()`, pass `static fn() => self::shared_facts( (int) $user_id )` as the facts loader, with the `null`, `null` and `[]` arguments before it, and return `->as_estimate()`**

- [ ] **Step 3: Add the verdict and estimate Context to the engine section of `docs/architecture.md`, and name `INPUT_BASKET` where condition inputs are described**

- [ ] **Step 4: Verify on a Pro site**

Set up: campaign A with `category_in_cart` Shoes, priority 10; B with no conditions, rate 1. Product pages at $100, as a guest and a member.
- A at 0.5: Shoes shows "Earn 50 Points", another product "Earn 100 Points".
- A at 3: Shoes shows 300, the other 100.
- `payment_method in [stripe]` at 0.5 instead: every product shows 50. At 3: every product shows 100.
- `order_count gte 5` at 2 instead: a member with 5 paid orders sees 200; a member with 1 and a guest see 100.
- Pause B with only the `payment_method` campaign live: no line shows.

- [ ] **Step 5: Verify one facts read per request**

With the `order_count` campaign live, add a temporary counter in `shared_facts()`, or set a Query Monitor filter on `wc_get_orders`, then open a variable product with 10 variations as a member.
Expected: one read of the member's orders. Remove the counter.

- [ ] **Step 6: Commit**

Stage: `includes/Engine/Points/ContextFactory.php`, `docs/architecture.md`
Message: `fix(storefront): show product-page points the order will pay under campaign conditions`
