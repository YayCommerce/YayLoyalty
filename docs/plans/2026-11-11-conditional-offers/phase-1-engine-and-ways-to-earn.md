# Phase 1: Engine offers and Ways to earn

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** Ways to earn (launcher panels, the My Points tab and `[yayloyalty_ways_to_earn]`) shows a member's personal value and up to 3 bonus lines per row, with phrases generated from the conditions.
**Exit criterion:** `composer test` passes with `ConditionsEvaluatorDraftContextTest` and `PointsEngineOffersTest`. On the Local site, with the spec's setup (Base, VIP and Shoes; Shoes and VIP campaigns created in the admin), spec criteria 1, 2 and 7 hold as a guest, a non-VIP customer and a `vip` customer.

### Task 1: The evaluator reads order conditions on draft Contexts

**Files:**
- Modify: `includes/Pro/Services/ConditionsEvaluator.php` (`missing_input()` and the class docblock)
- Test: `tests/unit/Pro/ConditionsEvaluatorDraftContextTest.php`

**Interfaces:**
- Consumes: `Context::get_order_id()`, `get_line_items()`, `get_extra()`, `CampaignConditionType::input_for()`
- Produces: `explain()` / `evaluate()` unchanged in signature. Without an order, `INPUT_ORDER` types answer `needs_order` only when their input is absent.

- [ ] **Step 1: Write the failing test**

Extend `YayLoyalty\Tests\Unit\EngineTestCase`, whose `context()` builds an order Context (order id 555). For the draft Context, build `new Context( 7, self::AT, 'USD', 1.0, $lines, $subtotal, 0.0, [], [ 'customer' ], null, null, $extra )`.

```php
public function test_order_context_results_are_unchanged(): void {
    $ctx = $this->context( [ $this->line( 100, null, 10, [ 5 ] ) ], [], 1.0, 7, [
        Context::EXTRA_PAYMENT_METHOD   => 'cod',
        Context::EXTRA_BILLING_COUNTRY  => 'VN',
        Context::EXTRA_SHIPPING_COUNTRY => '',
    ] );
    $results = ( new ConditionsEvaluator() )->explain( self::all_order_conditions(), $ctx );
    $this->assertSame( self::EXPECTED_ORDER_RESULTS, array_column( $results, 'matched' ) );
    $this->assertSame( array_fill( 0, count( $results ), '' ), array_column( $results, 'reason' ) );
}

public function test_draft_with_lines_evaluates_product_category_and_currency(): void {
    $ctx = $this->draft( [ $this->line( 100, null, 10, [ 5 ] ) ], [] );
    $results = ( new ConditionsEvaluator() )->explain( [
        [ 'type' => 'product_in_cart',  'operator' => 'in', 'value' => [ 10 ] ],
        [ 'type' => 'category_in_cart', 'operator' => 'in', 'value' => [ 6 ] ],
        [ 'type' => 'currency',         'operator' => 'in', 'value' => [ 'USD' ] ],
    ], $ctx );
    $this->assertSame( [ true, false, true ], array_column( $results, 'matched' ) );
    $this->assertSame( [ '', '', '' ], array_column( $results, 'reason' ) );
}

public function test_draft_without_payment_or_country_needs_the_order(): void {
    $ctx = $this->draft( [ $this->line( 100 ) ], [ Context::EXTRA_PAYMENT_METHOD => '', Context::EXTRA_BILLING_COUNTRY => 'VN' ] );
    $results = ( new ConditionsEvaluator() )->explain( [
        [ 'type' => 'payment_method', 'operator' => 'in', 'value' => [ 'cod' ] ],
        [ 'type' => 'country', 'operator' => 'in', 'value' => [ 'countries' => [ 'VN' ], 'source' => 'shipping' ] ],
        [ 'type' => 'country', 'operator' => 'in', 'value' => [ 'countries' => [ 'VN' ], 'source' => 'any' ] ],
    ], $ctx );
    $this->assertSame( [ 'needs_order', 'needs_order', '' ], array_column( $results, 'reason' ) );
    $this->assertTrue( $results[2]['matched'] );
}

public function test_draft_without_lines_needs_the_order_for_products(): void {
    // The admin preview's Context (from_user): no lines, no order.
    $results = ( new ConditionsEvaluator() )->explain( [
        [ 'type' => 'product_in_cart', 'operator' => 'not_in', 'value' => [ 10 ] ],
    ], $this->draft( [], [] ) );
    $this->assertSame( 'needs_order', $results[0]['reason'] );
    $this->assertFalse( $results[0]['matched'] );
}
```

`all_order_conditions()` returns one `in` and one `not_in` condition for each of currency, payment_method, country (source shipping, billing and any), product_in_cart and category_in_cart. `EXPECTED_ORDER_RESULTS` is the list `explain()` returns for them on today's code. Run the test once on the unchanged evaluator to confirm it, and write it as a literal list.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter ConditionsEvaluatorDraftContextTest`
Expected: `test_order_context_results_are_unchanged` PASS (the baseline). `test_draft_with_lines_evaluates_product_category_and_currency` and `test_draft_without_payment_or_country_needs_the_order` FAIL, with reasons `needs_order`.

- [ ] **Step 3: Change `private function missing_input( string $type, Context $ctx ): ?string`**

With `null !== $ctx->get_order_id()`, return exactly what it returns today. Without an order, for `INPUT_ORDER` types:
- `product_in_cart` and `category_in_cart` are evaluable when `get_line_items()` is non-empty.
- `currency` is always evaluable.
- `payment_method` is evaluable when `(string) get_extra( EXTRA_PAYMENT_METHOD, '' ) !== ''`.
- `country` is evaluable when the extra its `source` reads is non-empty; for `any`, either one. The condition value is needed here, so pass the `CampaignCondition` (or its value) into `missing_input()`.

Anything else returns `REASON_NEEDS_ORDER`. Update the class docblock sentence on `needs_order`.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `composer test`
Expected: all tests PASS, the four new ones included.

- [ ] **Step 5: Commit**

Stage: `includes/Pro/Services/ConditionsEvaluator.php`, `tests/unit/Pro/ConditionsEvaluatorDraftContextTest.php`
Message: `feat(conditions): evaluate order conditions on carts and products before the order exists`

### Task 2: `PointsEngine::offers()`

**Files:**
- Modify: `includes/Engine/Points/PointsEngine.php`
- Test: `tests/unit/Engine/Points/PointsEngineOffersTest.php`

**Interfaces:**
- Consumes: `advertised()`, `candidates()`, `applies_to_everyone()`, `conditions_evaluator()`, `TriggerHandler::advertised_points()`
- Produces: `OFFER_*` constants and `offers()` (see README contract)

- [ ] **Step 1: Write the failing test**

Extend `EngineTestCase`. Copy the `engine()` and `advert()` helpers from `PointsEngineAdvertisedTest`. Attach an evaluator stub whose `explain()` returns per-condition rows from a map `type => [matched, reason]` set by each test, and whose `evaluate()` folds over it.

```php
public function test_bonuses_are_conditional_campaigns_above_the_base_paying_more(): void {
    $this->attach_explainer( [ 'user_role' => [ false, 'no_customer' ], 'category_in_cart' => [ false, 'needs_order' ] ] );
    $base   = $this->purchase_campaign( [ 'points_per_unit' => 1 ], 0, 1, Status::ACTIVE, null, null, 'base' );
    $vip    = $this->conditional( 2, 2.0, 10, 'user_role' );
    $shoes  = $this->conditional( 3, 3.0, 20, 'category_in_cart' );
    $lower  = $this->conditional( 4, 0.5, 30, 'user_role' );     // above the base, pays less: lowers the base, no bonus
    $below  = $this->conditional( 5, 9.0, -1, 'user_role' );     // below the base: never pays

    $offers = $this->engine( [ $base, $vip, $shoes, $lower, $below ] )->offers( CampaignTrigger::PURCHASE, $this->advert(), null );

    $this->assertSame( 0.5, $offers['base']['value'] );
    $this->assertSame( [ $shoes, $vip ], array_column( $offers['bonuses'], 'campaign' ) );
    $this->assertSame( [ PointsEngine::OFFER_AT_CHECKOUT, PointsEngine::OFFER_LOCKED ], array_column( $offers['bonuses'], 'status' ) );
}

public function test_viewer_who_meets_every_condition_is_matched(): void {
    $this->attach_explainer( [ 'user_role' => [ true, '' ] ] );
    $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ), $this->base() ] )
        ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );
    $this->assertSame( PointsEngine::OFFER_MATCHED, $offers['bonuses'][0]['status'] );
}

public function test_failed_condition_beats_needs_order(): void {
    // A campaign with both a failing role and an unresolved category is locked, not at_checkout.
    $this->attach_explainer( [ 'user_role' => [ false, '' ], 'category_in_cart' => [ false, 'needs_order' ] ] );
    $campaign = new Campaign( 2, 'Both', 'both', CampaignTrigger::PURCHASE, Status::ACTIVE, 10, [ 'points_per_unit' => 2 ],
        [ [ 'type' => 'user_role' ], [ 'type' => 'category_in_cart' ] ] );
    $offers = $this->engine( [ $campaign, $this->base() ] )->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );
    $this->assertSame( PointsEngine::OFFER_LOCKED, $offers['bonuses'][0]['status'] );
}

public function test_without_an_evaluator_there_are_no_bonuses(): void {
    $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ), $this->base() ] )
        ->offers( CampaignTrigger::PURCHASE, $this->advert(), null );
    $this->assertSame( [], $offers['bonuses'] );
    $this->assertSame( 2.0, $offers['base']['value'] ); // Lite: conditions are not enforced, so it is for everyone
}

public function test_evaluator_without_explain_gives_no_bonuses(): void {
    add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, static fn() => new class() {
        public function evaluate( array $conditions, Context $ctx ): bool { return true; }
    } );
    $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ), $this->base() ] )
        ->offers( CampaignTrigger::PURCHASE, $this->advert(), null );
    $this->assertSame( [], $offers['bonuses'] );
}

public function test_no_campaign_for_everyone_has_no_base_but_keeps_bonuses(): void {
    $this->attach_explainer( [ 'user_role' => [ true, '' ] ] );
    $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ) ] )
        ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );
    $this->assertNull( $offers['base'] );
    $this->assertSame( PointsEngine::OFFER_MATCHED, $offers['bonuses'][0]['status'] );
}
```

The cap of 3 is an `OfferLines` rule (Task 3), so `offers()` returns every bonus. Add `test_offers_returns_every_bonus_above_the_base` with 5 conditional campaigns above the base, asserting 5 bonuses. `conditional( int $id, float $rate, int $priority, string $type ): Campaign` builds a purchase campaign with `[ [ 'type' => $type ] ]` conditions.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter PointsEngineOffersTest`
Expected: FAIL, `Call to undefined method YayLoyalty\Engine\Points\PointsEngine::offers()`

- [ ] **Step 3: Implement `public function offers( string $trigger, Context $advert, ?Context $viewer ): array` and the three `OFFER_*` constants**

`base` is `$this->advertised( $trigger, $advert )`. Walk `candidates( $trigger, $advert )` until the first `applies_to_everyone()` campaign, and keep the conditional campaigns whose `advertised_points()` value is greater than `base['value']` (any value > 0 when `base` is null). The status comes from `explain( $campaign->get_conditions(), $viewer ?? $advert )`. A campaign that fails `yayloyalty_campaign_matches` for a `matched` status is downgraded to `locked`. Return `bonuses = []` when the evaluator is null or lacks `explain()`. Document `offers()` in the class docblock next to `advertised()`, and add `explain()` to the `CONDITIONS_EVALUATOR_FILTER` docblock.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `composer test`
Expected: all tests PASS

- [ ] **Step 5: Commit**

Stage: `includes/Engine/Points/PointsEngine.php`, `tests/unit/Engine/Points/PointsEngineOffersTest.php`
Message: `feat(engine): list the conditional campaigns a shopper could earn more from`

### Task 3: `OfferLines` and the Pro phrases

**Files:**
- Create: `includes/Engine/Storefront/OfferLines.php`
- Create: `includes/Pro/Storefront/ConditionPhrases.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`: `( new ConditionPhrases() )->register();` next to `RewardsPanel`)

**Interfaces:**
- Consumes: `PointsEngine::offers()` (Task 2), `DesignHelper::surface_enabled()`, `SettingsHelper::points_label()`, `wp_roles()->get_names()`, `WC()->payment_gateways()->payment_gateways()`, `WC()->countries->get_countries()`, `wc_get_product()`, `get_term()`
- Produces: `OfferLines` and `ConditionPhrases` as in the README contract

- [ ] **Step 1: Implement `OfferLines::for_trigger( PointsEngine $engine, string $trigger, Context $advert, ?Context $viewer ): array`**

`row` is the first `matched` bonus, else `base`; for a guest (`null === $viewer`) it is always `base`. `bonuses` are the `locked` and `at_checkout` bonuses with `value > row['value']` (any, when `row` is null) whose `teaser()` is not null, cut to the first `MAX_BONUSES`. A guest with no `base` gets no bonuses. When `enabled()` is false, `bonuses` is `[]` and `row` is still computed. Memoise per request by `$trigger . '|' . ( $viewer ? $viewer->get_user_id() : 0 )`. Catch `Throwable`, log `Log::warning( 'OfferLines', … )`, and return `base` / `row` from `advertised()` with no bonuses.

- [ ] **Step 2: Implement `OfferLines::teaser( array $config, array $conditions ): ?string` and `OfferLines::enabled(): bool`**

`advert_mode` missing or unknown means `MODE_AUTO`. For `MODE_OFF` → null. For `MODE_CUSTOM` → `trim( (string) $config['advert_text'] )`, with '' → null. For auto → `apply_filters( self::PHRASE_FILTER, null, $conditions )`, keeping only a non-empty string.

- [ ] **Step 3: Implement `ConditionPhrases::phrase( ?string $phrase, array $conditions ): ?string` and `register()`**

The clause table is the spec's (§ Approach → `ConditionPhrases`), each clause a translatable string with a translators comment. Lists go through `wp_sprintf_l( '%l', $names )`. Product and category lists show the first 3 names, then "and %d more" (`_n`). A role, gateway, country, product or term that no longer exists shows its stored id or slug. `register()` adds the filter at priority 10, 2 args. It returns the input `$phrase` unchanged when that is already a string.

- [ ] **Step 4: Verify the phrases with WP-CLI**

Run (on the Local site, Pro active): `wp eval 'echo apply_filters( "yayloyalty_offer_condition_phrase", null, [ [ "type" => "user_role", "operator" => "in", "value" => [ "customer" ] ], [ "type" => "lifetime_points", "operator" => "gte", "value" => 500 ] ] ), "\n", var_export( apply_filters( "yayloyalty_offer_condition_phrase", null, [ [ "type" => "customer_ids", "operator" => "in", "value" => [ 1 ] ] ] ), true );'`
Expected: `for Customer members and once you've earned 500 {label}` (the site's label), then `NULL`

- [ ] **Step 5: Lint the changed files**

Run: `composer lint -- includes/Engine/Storefront/OfferLines.php includes/Pro/Storefront/ConditionPhrases.php includes/Pro/YayLoyaltyPro.php`
Expected: no errors reported for these files

- [ ] **Step 6: Commit**

Stage: `includes/Engine/Storefront/OfferLines.php`, `includes/Pro/Storefront/ConditionPhrases.php`, `includes/Pro/YayLoyaltyPro.php`
Message: `feat(storefront): resolve bonus offers and their teasers for a viewer`

### Task 4: Bonus lines and personal values in Ways to earn

**Files:**
- Modify: `includes/Engine/Storefront/LauncherEarnRules.php` (`advertise()`, `member_rows()`, `guest_rows()`, `for_viewer()`, `list_item()`, the `yayloyalty_launcher_earn_rows` docblock)
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`build()` / `for_balance()`: pass the viewer id, add `earn_bonuses`)
- Modify: `includes/Engine/Storefront/templates/rule-list-item.php`, `panel-member.php`, `panel-guest.php`, `points-card.php` (pass `earn_bonuses` to `member_rows()`)
- Modify: `assets/storefront/account/rule-list-item.css` (`.yayloyalty-account__item-bonus`)
- Modify: `includes/Helpers/DesignSchema.php`, `apps/admin/src/lib/schema/design.schema.ts`, `apps/admin/src/pages/settings/DesignVisibilitySection.tsx` (switch "Show bonus offers", following `show_product_message`)

**Interfaces:**
- Consumes: `OfferLines::for_trigger()` (Task 3), `ContextFactory::from_user( $user_id, $now )` as the viewer Context
- Produces: `advertise()` with `$viewer_id`, the payload's `earn_bonuses`, `member_rows()` with `$bonuses`, rows' `bonuses` (README contract), and the design key `visibility.show_bonus_offers`

- [ ] **Step 1: Thread `OfferLines` through `LauncherEarnRules::advertise( PointsEngine $engine, Context $ctx, bool $can_register, int $viewer_id = 0 ): array`**

Per trigger, `OfferLines::for_trigger( $engine, $trigger, $ctx, $viewer_id > 0 ? ContextFactory::from_user( $viewer_id, $ctx->get_occurred_at() ) : null )`. `row` replaces `advertised()` as the value source; the purchase conversion, `purchase_min` and `purchase_rounding` read the row's campaign. Each bonus line is `{value text} {teaser}`, where value text is what the row would show for that campaign: `LauncherCopy::earn_rate( converted rate, that campaign's min, that campaign's rounding )` for purchase, `LauncherCopy::points_gain()` for signup and review. Keep `advertised()` as the wrapper `ProductEarnMessage` and `BirthdayEarnRow` call.

- [ ] **Step 2: Pass the viewer from `LauncherPayload::build()` into `for_balance()` (new trailing parameter `int $viewer_id = 0`), and add `earn_bonuses` to the payload**

The Design preview and guests pass 0.

- [ ] **Step 3: Carry `bonuses` on rows and print them**

`member_rows( …, array $bonuses = [] )` sets `bonuses` on the purchase and review rows; `guest_rows()` passes `$payload['earn_bonuses']` and sets the signup row's. `list_item()` adds `'bonuses' => (array) ( $row['bonuses'] ?? [] )`. `rule-list-item.php` prints each, after the text, as `<span class="yayloyalty-account__item-bonus">` with `esc_html`, and documents the key in its `@var`.

- [ ] **Step 4: Add `visibility.show_bonus_offers` (default `true`) to `DesignSchema` defaults and `schema()`, to `design.schema.ts`, and as a switch in `DesignVisibilitySection.tsx`**

- [ ] **Step 5: Type-check the SPA**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: exit 0

- [ ] **Step 6: Verify spec criteria 1, 2 and 7 on the Local site**

Setup: in the admin, create "VIP" (purchase, 2 / $1, user role in [vip], priority 10) and "Shoes" (purchase, 3 / $1, category in cart [Shoes], priority 20) over the existing default campaign (1 / $1). Add the role: `wp role create vip VIP --clone=customer`.
- Signed out, open the launcher: "Make a purchase" shows "1 point / $1" (with the site's label) and two lines, "3 … / $1 when your order includes Shoes" then "2 … / $1 for VIP members". Save the page HTML twice in two private windows: the `.yayloyalty-account__earn` markup is identical.
- As a `vip` customer: the row shows "2 … / $1" and only the Shoes line. As a plain customer: "1 … / $1" and both lines.
- Edit priorities so "VIP" sits below the default campaign: its line disappears for everyone, and the `vip` row shows 1 / $1. Restore them afterwards.
- Add 3 more conditional campaigns above the default at 4, 5 and 6 / $1: the guest sees exactly 3 lines.
- Turn off Design → Visibility → "Show bonus offers": no bonus lines, and the `vip` row still shows 2 / $1.
- Archive the default campaign: a guest sees no "Make a purchase" row and no bonus lines, and the `vip` customer sees the row at 2 / $1.

Expected: every bullet as stated.

- [ ] **Step 7: Verify Lite**

Run: `./scripts/run.sh release lite --no-prompt`, then `composer install`
Expected: the validator passes. Install the Lite zip on the Local site: the launcher shows the rows as before, with no bonus lines.

- [ ] **Step 8: Commit**

Stage: the files listed under **Files** for this task, by name
Message: `feat(launcher): show members their own earn rate and tease conditional bonuses`
