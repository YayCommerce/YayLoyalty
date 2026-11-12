# Phase 1: The multiplier

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** slices 1–2 on `jason/feat/tiers`: `TierRepository::find_live()`, `TierEvaluator::is_enabled()`, `CustomerRepository::set_tier_state()`, and `LedgerItemTierNames`.
**Delivers:** tiered customers earn multiplied points on every campaign award. The admin ledger and drawer label those rows " · {Tier} ×{multiplier}".
**Exit criterion:**
- `composer test -- --filter PointsEngineMultiplierTest` and `composer test:integration -- --filter TierMultiplierAwardTest` pass.
- On wp-env (tiers on), a Silver customer's 101-unit completed order earns 126 points, and the drawer's Points tab labels it "Earned on order #{id} · Silver ×1.25" (spec AC 1, 4, 5).

### Task 1: The multiplier seam (Lite)

**Files:**
- Create: `includes/Engine/Points/Multiplier.php`
- Modify: `includes/Engine/Points/PointsEngine.php` (`evaluate()`, a new constant)
- Modify: `includes/Engine/Points/Breakdown.php`
- Modify: `includes/Engine/Points/Result.php`
- Test: `tests/unit/Engine/Points/PointsEngineMultiplierTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Multiplier`, `PointsEngine::MULTIPLIER_FILTER`, `Breakdown::with_multiplier()`, and `Result::get_multiplier()`. See the README contract.

- [ ] **Step 1: Write the failing test**

`final class PointsEngineMultiplierTest extends EngineTestCase` in namespace `YayLoyalty\Tests\Unit\Engine\Points`. It uses `$this->engine()`, `$this->purchase_campaign()` and `$this->order()` from `EngineTestCase` (1 pt per unit, rounding down). `EngineTestCase::tearDown()` already clears the hook shim.

```php
private function multiply_by( $answer ): void {
    add_filter( PointsEngine::MULTIPLIER_FILTER, static function () use ( $answer ) { return $answer; }, 10, 3 );
}

public function test_multiplier_floors_after_the_campaign(): void {
    $this->multiply_by( new Multiplier( '1.25', 7 ) );
    $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 101.0 ) );

    $this->assertSame( 126, $result->get_points() );
    $this->assertSame( 101, $result->get_breakdown()->get_campaign_points() );
    $this->assertSame( '1.25', $result->get_breakdown()->get_multiplier() );
    $this->assertSame( 7, $result->get_multiplier()->get_tier_id() );
}

public function test_campaign_cap_bounds_points_before_the_multiplier(): void {
    $this->multiply_by( new Multiplier( '1.50', 9 ) );
    $result = $this->engine( [ $this->purchase_campaign( [ 'max_points_per_order' => 100 ] ) ] )
        ->evaluate( CampaignTrigger::PURCHASE, $this->order( 150.0 ) );

    $this->assertSame( 150, $result->get_points() );
    $this->assertSame( 100, $result->get_breakdown()->get_campaign_points() );
}

public function test_multiplier_uses_integer_math(): void {
    $this->multiply_by( new Multiplier( '1.15', 7 ) );
    $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 100.0 ) );

    $this->assertSame( 115, $result->get_points() );
}

/** @dataProvider malformed_answers */
public function test_malformed_answer_is_neutral( $answer ): void {
    $this->multiply_by( $answer );
    $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 101.0 ) );

    $this->assertSame( 101, $result->get_points() );
    $this->assertTrue( $result->get_multiplier()->is_neutral() );
}

public function malformed_answers(): array {
    return [
        'string'       => [ '1.25' ],
        'null'         => [ null ],
        'below 1.00'   => [ new Multiplier( '0.50', 7 ) ],
        'above 10.00'  => [ new Multiplier( '10.05', 7 ) ],
        'three places' => [ new Multiplier( '1.255', 7 ) ],
    ];
}

public function test_no_subscriber_leaves_the_result_as_today(): void {
    $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 101.0 ) );

    $this->assertSame( 101, $result->get_points() );
    $this->assertSame( 101, $result->get_breakdown()->get_campaign_points() );
    $this->assertSame( '1.00', $result->get_breakdown()->get_multiplier() );
    $this->assertTrue( $result->get_multiplier()->is_neutral() );
    $this->assertNull( $result->get_multiplier()->get_tier_id() );
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter PointsEngineMultiplierTest`
Expected: FAIL, class `YayLoyalty\Engine\Points\Multiplier` not found.

- [ ] **Step 3: Implement `Multiplier` in `includes/Engine/Points/Multiplier.php`**

Signatures are in the README contract. Write the class docblock in the house style: an immutable value, "Internal seam, not a public API", and why the math is integer (`100 × 1.15` as a float floors to 114). `Log::write()` is guarded by `function_exists( 'wc_get_logger' )`, so the warning is safe in the unit suite.

- [ ] **Step 4: Add `with_multiplier()` to `Breakdown` and the optional `Multiplier` to `Result::awarded()`**

- `Breakdown`: add private `$campaign_points` (default = `$points`, set in the constructor) and `$multiplier` (default `'1.00'`). `with_multiplier()` returns a clone with `points`, `campaign_points` and `multiplier` set. `to_array()` appends `campaign_points` and `multiplier`.
- `Result`: a private `$multiplier` (`?Multiplier`), set only by `awarded()`. `get_multiplier()` returns it or `Multiplier::none()`.

- [ ] **Step 5: Apply the seam in `PointsEngine::evaluate()`**

Add `public const MULTIPLIER_FILTER = 'yayloyalty_points_multiplier';`. After `$points = $this->caps->apply( … )`:
1. `$multiplier = Multiplier::from_filter( apply_filters( self::MULTIPLIER_FILTER, Multiplier::none(), $campaign, $ctx ) )`.
2. When the multiplier isn't neutral: `$breakdown = $breakdown->with_multiplier( $points, $multiplier, $multiplier->apply( $points ) )` and `$points = $breakdown->get_points()`.
3. Keep the `<= 0` check after it, and pass `$multiplier` as `awarded()`'s 7th argument.

The filter's docblock says:
- it runs after the campaign's rounding and `max_points_per_order`;
- `advertised()` never calls it;
- the answer must be a `Multiplier` from `'1.00'` to `'10.00'`, or it is ignored;
- "Internal seam, not a public API.";
- it carries the `// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound` comment that the existing constant-named filters use.

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `composer test -- --filter PointsEngine`
Expected: PASS for `PointsEngineMultiplierTest`, `PointsEngineEvaluateTest` and `PointsEngineAdvertisedTest`.

Run: `composer test`
Expected: no failures beyond those `dev/edd` already has.

- [ ] **Step 7: Lint**

Run: `composer lint -- includes/Engine/Points/Multiplier.php includes/Engine/Points/PointsEngine.php includes/Engine/Points/Breakdown.php includes/Engine/Points/Result.php tests/unit/Engine/Points/PointsEngineMultiplierTest.php`
Expected: no error or warning in those files.

- [ ] **Step 8: Commit**

Stage: `includes/Engine/Points/Multiplier.php`, `includes/Engine/Points/PointsEngine.php`, `includes/Engine/Points/Breakdown.php`, `includes/Engine/Points/Result.php`, `tests/unit/Engine/Points/PointsEngineMultiplierTest.php`
Message: `feat(points): add the points multiplier seam after the campaign cap`

### Task 2: Multiplied EARN rows and the Pro tier multiplier

**Files:**
- Modify: `includes/Database/Schema/LedgerTable.php` (`definition()`, `expected_columns()`)
- Modify: `includes/Models/LedgerEntry.php`
- Modify: `includes/Engine/Earning/EarningService.php` (`draft_from()`)
- Create: `includes/Pro/Services/TierMultiplier.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`boot()`)
- Test: `tests/integration/Earning/TierMultiplierAwardTest.php`

**Interfaces:**
- Consumes: Task 1's `Multiplier`, `PointsEngine::MULTIPLIER_FILTER` and `Result::get_multiplier()`.
- Produces: the ledger columns, the `LedgerEntry` tail parameters and getters, and `TierMultiplier`. See the README contract.

- [ ] **Step 1: Write the failing test**

`final class TierMultiplierAwardTest extends IntegrationTestCase` in namespace `YayLoyalty\Tests\Integration\Earning`.
- **Setup:** skip without `YayLoyaltyPro`, as `DailyEarningLimitTest` does. Pin settings through `pre_option_` + `SettingsHelper::OPTION_SETTINGS` with `tiers_enabled` (default true) and `max_points_per_day` (default null). Seed and rerank tiers, as `TierEvaluatorTest::setUp()` does.
- **Customer:** a real WordPress user, as `TierEvaluatorTest::customer()` makes one. Give it a tier with `CustomerRepository::ensure_row()` then `set_tier_state( $user, [ 'tier_id' => <id> ] )`.
- **Campaign:** one live `signup` campaign paying 101 flat points (`insert_campaign()`, then set its flat points to 101 through `CampaignRepository`).
- **Award:** `EarningService::build()->award( CampaignTrigger::SIGNUP, ContextFactory::from_user( $user, Dates::now() ) )`.

```php
public function test_silver_award_writes_multiplied_points_and_tier_columns(): void {
    $user = $this->customer_in( 'Silver' );
    $this->award_signup( $user );

    $row = $this->only_earn_row( $user );
    $this->assertSame( 126, (int) $row['points'] );
    $this->assertSame( $this->tier_id( 'Silver' ), (int) $row['tier_id'] );
    $this->assertSame( '1.25', $row['tier_multiplier'] );
    $this->assertSame( 'signup', $row['source'] );
    $this->assertNotNull( $row['campaign_id'] );
    $this->assertSame( 126, (int) $this->customer_row( $user )['lifetime_earned'] );
}

public function test_tierless_award_writes_nulls(): void {
    $user = $this->customer_in( null );
    $this->award_signup( $user );

    $row = $this->only_earn_row( $user );
    $this->assertSame( 101, (int) $row['points'] );
    $this->assertNull( $row['tier_id'] );
    $this->assertNull( $row['tier_multiplier'] );
}

public function test_tiers_off_is_neutral(): void {
    $user                = $this->customer_in( 'Silver' );
    $this->tiers_enabled = false;
    $this->award_signup( $user );

    $row = $this->only_earn_row( $user );
    $this->assertSame( 101, (int) $row['points'] );
    $this->assertNull( $row['tier_multiplier'] );
}

public function test_daily_limit_cuts_the_multiplied_points(): void {
    $user      = $this->customer_in( 'Silver' );
    $this->cap = 100;
    $this->award_signup( $user );

    $row = $this->only_earn_row( $user );
    $this->assertSame( 100, (int) $row['points'] );
    $this->assertSame( '1.25', $row['tier_multiplier'] );
}
```

`only_earn_row()` asserts exactly one `type = 'earn'` row in `$this->ledger_rows( $user )` and returns it.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter TierMultiplierAwardTest`
Expected: FAIL. The Silver case writes 101, and `tier_multiplier` is an undefined index. Check that the output names the tests: [exit 0 doesn't prove it ran](../../known-issues.md).

- [ ] **Step 3: Add the ledger columns**

In `LedgerTable::definition()`, add `tier_id bigint unsigned DEFAULT NULL,` and `tier_multiplier decimal(4,2) DEFAULT NULL,` after `campaign_id`. Add no key. Append both names to `expected_columns()`. Follow the dbDelta rules in `AbstractTableSchema`'s docblock.

- [ ] **Step 4: Map them in `LedgerEntry`**

- **Constructor:** append the tail `?int $tier_id = null, ?string $tier_multiplier = null`.
- **`from_row()`:** `self::cast_int( $row['tier_id'] ?? null )` and `self::cast_string( $row['tier_multiplier'] ?? null )`.
- **`to_row()`:** append `tier_id` and `tier_multiplier`.
- **`get_format()`:** append `'%d', '%s'` at the matching positions.
- **`to_array()`:** add `tier_id` and `tier_multiplier`.
- **Getters:** add both. `with_points()` clones, so the fields survive the daily-limit cut.

- [ ] **Step 5: Write the columns in `EarningService::draft_from()`**

When `$result->get_multiplier()->is_neutral()` is false, pass its `get_tier_id()` and `get_factor()` as the new tail arguments. Otherwise pass null, null. Add one line to the docblock's postconditions.

- [ ] **Step 6: Implement `Pro\Services\TierMultiplier`**

`register()` calls `add_filter( PointsEngine::MULTIPLIER_FILTER, [ $this, 'for_context' ], 10, 3 )`.

`for_context()` returns `$multiplier` unchanged when any of these hold:
- `! TierEvaluator::is_enabled()`;
- `null === $ctx->get_user_id()`;
- the customer row (`CustomerRepository::find_by_user_id()`) is null or has a null `get_tier_id()`;
- `TierRepository::find_live()` is null.

Otherwise it returns `new Multiplier( $tier->get_multiplier(), (int) $tier->get_id() )`.
- Memoize the answer per user id in a private array, for the request.
- The docblock says it reads the stored tier now, not as of the event (the award is authoritative). It also says the order that lifts a customer into a tier earns at the old tier's multiplier.

- [ ] **Step 7: Register it in `YayLoyaltyPro::boot()`**

Add `( new TierMultiplier() )->register();` next to `( new TierLedgerListener() )->register();`.

- [ ] **Step 8: Apply the schema and run the test to confirm it passes**

Run: `$WP option update yayloyalty_db_version 0`, load http://localhost:8889/wp-admin/ once, then `$WP eval 'var_export( \YayLoyalty\Database\Installer::verify() );'`
Expected: `array ( )`.

Run: `composer test:integration -- --filter TierMultiplierAwardTest`
Expected: PASS, 4 tests.

Run: `composer test:integration`
Expected: no failures beyond those `dev/edd` already has.

- [ ] **Step 9: Lint**

Run: `composer lint -- includes/Database/Schema/LedgerTable.php includes/Models/LedgerEntry.php includes/Engine/Earning/EarningService.php includes/Pro/Services/TierMultiplier.php includes/Pro/YayLoyaltyPro.php tests/integration/Earning/TierMultiplierAwardTest.php`
Expected: no error or warning in those files.

- [ ] **Step 10: Commit**

Stage: `includes/Database/Schema/LedgerTable.php`, `includes/Models/LedgerEntry.php`, `includes/Engine/Earning/EarningService.php`, `includes/Pro/Services/TierMultiplier.php`, `includes/Pro/YayLoyaltyPro.php`, `tests/integration/Earning/TierMultiplierAwardTest.php`
Message: `feat(tiers): multiply campaign points by the customer's tier and record it on the earn row`

### Task 3: The admin ledger label

**Files:**
- Modify: `includes/Controllers/LedgerItemTierNames.php`
- Modify: `includes/Controllers/LedgerRestController.php` (item schema)
- Modify: `apps/admin/src/lib/schema/ledger.schema.ts`
- Modify: `apps/admin/src/lib/helpers/ledger.helper.ts`

**Interfaces:**
- Consumes: Task 2's `LedgerEntry::to_array()` keys `tier_id` and `tier_multiplier`.
- Produces: `tier_name` on multiplied rows, and `tier_multiplier` on the wire and in `ledgerEntrySchema`.

- [ ] **Step 1: Resolve `tier_name` for multiplied rows in `LedgerItemTierNames`**

Replace `is_bonus()` with `private static function tier_id_of( array $item ): ?int`:
- the `reference_id` of a `tier_bonus` row;
- otherwise a non-empty `tier_id`;
- otherwise null.

Both loops use it. Update the class and method docblocks: the name now also labels a multiplied earn row.

- [ ] **Step 2: Extend the ledger item schema**

In `LedgerRestController`'s item schema:
- widen `tier_name`'s description to "The tier an entry bonus was paid for, or whose multiplier an earn row used, deleted tiers included. Null on every other row.";
- add `tier_id` (`integer|null`, readonly) and `tier_multiplier` (`string|null`, readonly, "The tier multiplier an earn row was awarded at, as \"1.25\". Null when none applied.").

`StoreLedgerRestController` reuses this schema, so it needs no edit.

- [ ] **Step 3: Mirror it in `ledger.schema.ts`**

Add `tier_multiplier: z.string().nullable().default(null)` after `tier_name`, with the docblock "Mirrors LedgerEntry::to_array(), the source of truth. Only the ledger list routes send it." Widen `tier_name`'s docblock as in Step 2.

- [ ] **Step 4: Append the suffix in `ledger.helper.ts`**

Where `describeLedgerEntry()` handles `case 'earn': return describeEarn(entry);`, wrap the result in `withTierMultiplier(label, entry)` (module-private). It returns `label` when `entry.tier_multiplier` is null. Otherwise:
- `sprintf(/* translators: 1: what the points were earned for, 2: the tier's name, 3: the multiplier, e.g. "1.25". */ __('%1$s · %2$s ×%3$s', 'yayloyalty'), label, entry.tier_name, entry.tier_multiplier)`;
- or, when `tier_name` is null, `__('%1$s · ×%2$s', 'yayloyalty')`, with its own translators comment.

The storefront label (`ShopperActivityRow`) is unchanged.

- [ ] **Step 5: Type-check and lint**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`
Expected: exit 0.

Run: `pnpm --filter @yayloyalty/admin-page exec eslint src/lib/schema/ledger.schema.ts src/lib/helpers/ledger.helper.ts`
Expected: no errors.

Run: `composer lint -- includes/Controllers/LedgerItemTierNames.php includes/Controllers/LedgerRestController.php`
Expected: no error or warning in those files.

Run: `pnpm --filter @yayloyalty/admin-page test`
Expected: no failures beyond those `dev/edd` already has.

- [ ] **Step 6: Verify on wp-env (the phase exit, spec AC 1, 4 and 5)**

Setup:
- tiers on (`#/tiers` switch);
- the default purchase campaign at 1 pt per unit, rounding down;
- customer A set to Silver (Assign tier → Silver, No end);
- customer B tierless;
- a simple product priced 101.

Actions and expected results:
1. As A, add the product to the cart. The checkout and thank-you pending points read 126. The product page line reads 126. The launcher's Ways to earn still reads 1 point per unit.
2. Complete A's order in wp-admin. A's drawer → Points shows +126, labelled "Earned on order #{id} · Silver ×1.25". `#/ledger` (the store ledger) shows the same label.
3. B's identical order earns 101, labelled "Earned on order #{id}" with no suffix.
4. Turn tiers off, and repeat A's order. It earns 101, with no suffix. Turn tiers back on.
5. **Spec AC 7:** set Silver's bonus to 100, then lift a Bronze customer at 780 past 800 with a 30-unit order. The "Silver tier bonus" row is +100, with no suffix, and `$WP db query "SELECT points, tier_multiplier FROM wp_yayloyalty_ledger WHERE source='tier_bonus' ORDER BY id DESC LIMIT 1"` prints `100	NULL`.

- [ ] **Step 7: Commit**

Stage: `includes/Controllers/LedgerItemTierNames.php`, `includes/Controllers/LedgerRestController.php`, `apps/admin/src/lib/schema/ledger.schema.ts`, `apps/admin/src/lib/helpers/ledger.helper.ts`
Message: `feat(ledger): label multiplied earn rows with their tier and multiplier`
