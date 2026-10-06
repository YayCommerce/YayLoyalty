# Shared campaign choice for the launcher Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Goal:** `PointsEngine::evaluate()` and the launcher's "Ways to earn" pick a trigger's campaign through one engine loop, so the launcher follows the zero-points stop rule and the candidates and matches filters.
**Spec:** [docs/specs/2026-10-06-shared-campaign-choice-for-launcher-design.md](../specs/2026-10-06-shared-campaign-choice-for-launcher-design.md)
**Architecture:** The engine's loop moves into a private `pick()`, which branches on `Context::is_advert()` for eligibility and conditions. `advertised()` reuses it with an advertising Context instead of passing `null` to the filters, so a type-hinted third-party callback can't fatal the storefront. The launcher's own loop is deleted.
**Touches:** `includes/Engine/Points/Context.php`, `includes/Engine/Points/ContextFactory.php`, `includes/Engine/Points/PointsEngine.php` (shared: consumed by `EarningService`, `LauncherPayload`), `includes/Engine/Points/Triggers/TriggerHandler.php` (shared: implemented by the three Lite handlers; Pro registers none), `PurchaseHandler.php`, `SignupHandler.php`, `ReviewHandler.php`, `includes/Engine/Storefront/LauncherEarnRules.php`, `includes/Engine/Storefront/LauncherPayload.php` (consumed by `Launcher`, `LauncherPreview`); deletes `docs/backlog/2026-10-05-launcher-repeats-the-engines-campaign-choice.md`
**Tests:** `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`, unit suite: guards `PointsEngine::advertised()`'s zero-points stop, multiplier fall-through, conditional skip, filter reach and advert-only guard (spec criteria 1–5). Accepted 2026-10-06.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- `PointsEngine` and `Context` stay WordPress-free; only `ContextFactory` reads WooCommerce.
- Lite names Pro nowhere new; Pro attaches only through existing filters.
- Filter callbacks always receive a `Context`, never `null`.
- `advertised()` writes nothing and scores at the given Context's `occurred_at`, never `now()`.
- Keep each touched file under 200 lines where it already is (`ContextFactory.php` is 311 today; don't grow it beyond the one new method).
- Branch from `dev/edd` as `<name>/<type>/<topic>`; commits follow Conventional Commits and end with the `Co-Authored-By` trailer from CLAUDE.md. Commit only when the user asks.
- Baseline on `dev/edd` 2026-10-06: `composer test` OK (6 tests, 31 assertions); `vendor/bin/phpcs` on the touched PHP files reports nothing.

## Review focus

- `evaluate()` for a real order must pay exactly what it paid before the refactor (spec criterion 6): Task 2, Step 6.
- A filter callback that throws must not take a shop page down (criterion 7): Task 3, Step 4.
- `EarningService` builds the engine at boot and calls `evaluate()`; it must not need any change: Task 2, Step 5.
- The Design tab preview (`LauncherPreview` → `for_balance()`) runs the new path in admin: Task 3, Step 5.

---

### Task 1: Advertising Context and handler values

**Files:**
- Modify: `includes/Engine/Points/Context.php`
- Modify: `includes/Engine/Points/Triggers/TriggerHandler.php`, `PurchaseHandler.php`, `SignupHandler.php`, `ReviewHandler.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Context::advert( string $at, string $currency, float $base_rate ): Context` (static): user `null`, no lines, `0.0` subtotal, discount and loyalty discount, no coupons, no roles, no order or comment id, 0 earned today, no extra, no customer facts; sets private `bool $advert = true`.
  - `Context::is_advert(): bool`: `false` for every Context built by the constructor.
  - `TriggerHandler::advertised_value( Campaign $campaign ): ?float`. Docblock: `null` = skipped whatever the event (the engine falls through); `0` = pays nothing (the engine stops); above 0 = points per currency unit (purchase) or flat points.
  - `PurchaseHandler::advertised_value()`: `null` when `self::is_multiplier( $config )` and `self::multiplier_default( $this->campaigns->find_default_purchase() )` is null; else `self::rate_of( $config, $this->campaigns->find_default_purchase() )`.
  - `SignupHandler::advertised_value()`, `ReviewHandler::advertised_value()`: `(float) $campaign->flat_points()`.

- [x] **Step 1: Add `Context::advert()` and `is_advert()` in `includes/Engine/Points/Context.php`**

The constructor's signature does not change; `advert()` calls `new self( ... )` and sets the private property. Class docblock gains one line: an advertising Context stands for "any shopper, no event" and is built only for `PointsEngine::advertised()`.

- [x] **Step 2: Add `advertised_value()` to `TriggerHandler` and implement it in the three handlers**

- [x] **Step 3: Verify it compiles and nothing regressed**

Run: `for f in includes/Engine/Points/Context.php includes/Engine/Points/Triggers/*.php; do php -l "$f"; done && composer test`
Expected: `No syntax errors detected` for each file; `OK (6 tests, 31 assertions)`.

- [ ] **Step 4: Commit**

Stage: `includes/Engine/Points/Context.php`, `includes/Engine/Points/Triggers/TriggerHandler.php`, `includes/Engine/Points/Triggers/PurchaseHandler.php`, `includes/Engine/Points/Triggers/SignupHandler.php`, `includes/Engine/Points/Triggers/ReviewHandler.php`
Message: `feat(engine): add advertising context and handler advertised values`

### Task 2: One selection loop in the engine

**Files:**
- Modify: `includes/Engine/Points/PointsEngine.php`
- Test: `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`

**Interfaces:**
- Consumes: Task 1's `Context::advert()`, `Context::is_advert()`, `TriggerHandler::advertised_value()`.
- Produces:
  - `PointsEngine::advertised( string $trigger, Context $ctx ): ?array`, returning `array{campaign: Campaign, value: float}` or `null`. Throws `\InvalidArgumentException` when `! $ctx->is_advert()`, and (through `Registry::get()`) when no handler owns the trigger.
  - Private `PointsEngine::pick( TriggerHandler $handler, string $trigger, Context $ctx ): ?Campaign`.

- [x] **Step 1: Write the failing test**

Class `YayLoyalty\Tests\Unit\Engine\Points\PointsEngineAdvertisedTest extends EngineTestCase`. Helpers: `engine( array $campaigns ): PointsEngine` returns `PointsEngine::build( $this->repository( $campaigns ) )`; `advert(): Context` returns `Context::advert( self::AT, 'USD', 1.0 )`.

```php
public function test_first_campaign_paying_zero_advertises_nothing(): void {
    $engine = $this->engine( [
        $this->purchase_campaign( [ 'points_per_unit' => 0 ], 10, 2, Status::ACTIVE, null, null, 'a' ),
        $this->purchase_campaign( [ 'points_per_unit' => 2 ], 0, 3, Status::ACTIVE, null, null, 'b' ),
    ] );

    $this->assertNull( $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() ) );
}

public function test_multiplier_with_paused_default_falls_through(): void {
    $rate   = $this->purchase_campaign( [ 'points_per_unit' => 2 ], 0, 3, Status::ACTIVE, null, null, 'rate' );
    $engine = $this->engine( [
        $this->default_purchase_campaign( [], Status::PAUSED ),
        $this->purchase_campaign( [ 'mode' => 'multiplier', 'multiplier' => 3 ], 10, 2, Status::ACTIVE, null, null, 'multi' ),
        $rate,
    ] );

    $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() );

    $this->assertSame( $rate, $advert['campaign'] );
    $this->assertSame( 2.0, $advert['value'] );
}

public function test_conditional_campaign_is_skipped_without_asking_the_evaluator(): void {
    $evaluator = new class {
        public $calls = 0;
        public function evaluate( array $conditions, Context $ctx ): bool {
            ++$this->calls;
            return true;
        }
    };
    add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, static fn() => $evaluator );
    $everyone = $this->purchase_campaign( [ 'points_per_unit' => 3 ], 0, 3, Status::ACTIVE, null, null, 'everyone' );
    $engine   = $this->engine( [
        new Campaign( 2, 'Conditional', 'conditional', CampaignTrigger::PURCHASE, Status::ACTIVE, 10, [ 'points_per_unit' => 9 ], [ [ 'type' => 'country' ] ] ),
        $everyone,
    ] );

    $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() );

    $this->assertSame( $everyone, $advert['campaign'] );
    $this->assertSame( 3.0, $advert['value'] );
    $this->assertSame( 0, $evaluator->calls );
}

public function test_candidates_and_matches_filters_reach_the_advert(): void {
    $second = $this->purchase_campaign( [ 'points_per_unit' => 2 ], 0, 3, Status::ACTIVE, null, null, 'second' );
    $seen   = [];
    add_filter( 'yayloyalty_campaign_candidates', static fn( array $c ) => array_slice( $c, 1 ) );
    add_filter( 'yayloyalty_campaign_matches', static function ( bool $m, Campaign $c, Context $ctx ) use ( &$seen ) {
        $seen[] = $ctx->is_advert();
        return $m;
    } );
    $engine = $this->engine( [
        $this->purchase_campaign( [ 'points_per_unit' => 5 ], 10, 2, Status::ACTIVE, null, null, 'first' ),
        $second,
    ] );

    $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() );

    $this->assertSame( $second, $advert['campaign'] );
    $this->assertSame( [ true ], $seen );
}

public function test_event_context_is_refused(): void {
    $this->expectException( \InvalidArgumentException::class );

    $this->engine( [] )->advertised( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 10.0 ) ] ) );
}
```

- [x] **Step 2: Run the test to confirm it fails**

Run: `composer test -- --filter PointsEngineAdvertisedTest`
Expected: 5 errors, `Call to undefined method YayLoyalty\Engine\Points\PointsEngine::advertised()`

- [x] **Step 3: Implement `pick()` and `advertised()` in `includes/Engine/Points/PointsEngine.php`**

`pick()` holds today's loop from `evaluate()`: the candidates filter, the matches filter, then eligibility. It returns `null` where the loop finds no campaign; the caller maps that to `REASON_NO_CAMPAIGNS` or `REASON_NOT_ELIGIBLE`, so `pick()` must let `evaluate()` tell the two apart (for example, `evaluate()` checks the filtered candidates are empty before calling `pick()` with them; choose one and keep the filter applied once). Eligibility: `$handler->is_eligible( $campaign, $ctx )` for an event Context, `null !== $handler->advertised_value( $campaign )` for an advertising one. `conditions_match()` returns `self::applies_to_everyone( $campaign )` for an advertising Context, before reaching the evaluator. `evaluate()` = `pick()`, then `compute_points` and caps as today. `advertised()` = guard, `pick()`, `advertised_value()`; `null` when the value is ≤ 0. Its docblock names what it can't check without an event: caps, `min_subtotal`, `exclude_coupon_orders`, `require_verified`, `one_per_product`. Update both filter docblocks: they also fire on storefront pages with an advertising Context, see `Context::is_advert()`.

- [x] **Step 4: Run the test to confirm it passes**

Run: `composer test`
Expected: `OK (11 tests, ...)`, with no failures or warnings.

- [x] **Step 5: Verify the engine's other consumer needs no change**

Run: `git grep -n "PointsEngine\|->evaluate(" -- includes/Engine/Earning`
Expected: `EarningService.php` calls only `PointsEngine::build()` and `evaluate( string, Context )`, whose signatures are unchanged.

- [x] **Step 6: Verify `evaluate()` pays as before (spec criterion 6)**

On the Local site, with the default purchase campaign at 1 point per unit and no other purchase campaign: before Step 3, note the "earn" number in the launcher on a simple product's page as a guest. After Step 3, reload. Expected: the same number. Then place a test order with that product and process it. Expected: the order's pending/earned points equal that number.

- [ ] **Step 7: Commit**

Stage: `includes/Engine/Points/PointsEngine.php`, `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`
Message: `feat(engine): pick advertised campaigns through the evaluate loop`

### Task 3: Launcher advertises through the engine

**Files:**
- Modify: `includes/Engine/Points/ContextFactory.php`
- Modify: `includes/Engine/Storefront/LauncherEarnRules.php`
- Modify: `includes/Engine/Storefront/LauncherPayload.php`

**Interfaces:**
- Consumes: Task 2's `PointsEngine::advertised()`; Task 1's `Context::advert()`.
- Produces:
  - `ContextFactory::for_advert( string $at ): Context`: `Context::advert( $at, get_woocommerce_currency(), self::base_rate( $currency, null ) )`.
  - `LauncherEarnRules::advertise( PointsEngine $engine, Context $ctx, bool $can_register ): array`, return shape unchanged: `array{earn: array{purchase: ?float, signup: ?int, review: ?int}, purchase_min: float}`. Signup and review values cast to `int`.

- [x] **Step 1: Add `ContextFactory::for_advert()`**

- [x] **Step 2: Rewrite `LauncherEarnRules::advertise()` on `$engine->advertised()` per trigger in `TRIGGERS`**

Delete `winners()`, `purchase_rate()`, `flat_points()` and the `$default_purchase` parameter, and the imports they alone used. Update the class docblock: the engine decides the campaign; this class only shapes rows and applies `$can_register`.

- [x] **Step 3: Switch `LauncherPayload::for_balance()` to the engine**

Build `PointsEngine::build( $this->campaigns )` once in `for_balance()` and pass it to `product_estimate()` (new parameter `PointsEngine $engine`). Pass `ContextFactory::for_advert( $now )` to `advertise()`. Wrap the `advertise()` call in `try/catch ( \Throwable $e )`: `Log::warning( 'LauncherPayload', 'earn rules failed: ' . $e->getMessage() )` and fall back to every `earn` value `null`, `purchase_min` `0.0`. Delete `live_campaigns()`.

- [x] **Step 4: Verify the zero-points stop and the throwing-filter fallback on the storefront**

Run first: `for f in includes/Engine/Points/ContextFactory.php includes/Engine/Storefront/*.php; do php -l "$f"; done && vendor/bin/phpcs includes/Engine/Points includes/Engine/Storefront`
Expected: no syntax errors; phpcs reports nothing.

Then on the Local site (spec criteria 1, 7, 9):
1. Create purchase campaign A (priority above the default, 0 points per unit) and leave B at 2 points per unit, both with no conditions. Open the shop as a guest. Expected: the launcher panel has no purchase row.
2. Edit A to 1 point per unit. Reload. Expected: the panel shows 1 point per currency unit.
3. Add a temporary mu-plugin `wp-content/mu-plugins/yl-throw.php` with `add_filter( 'yayloyalty_campaign_candidates', function () { throw new Exception( 'probe' ); } );`. Reload. Expected: the page renders, the panel shows no earn rows, and WooCommerce → Status → Logs has a warning `LauncherPayload: earn rules failed: probe`. Delete the mu-plugin and A.

- [x] **Step 5: Verify the Design tab preview**

Open: `/wp-admin/admin.php?page=yayloyalty`, Design tab.
Action: toggle the preview between guest and member.
Expected: both previews render with the same earn rows as the storefront; no PHP notice in `wp-content/debug.log`.

- [ ] **Step 6: Commit**

Stage: `includes/Engine/Points/ContextFactory.php`, `includes/Engine/Storefront/LauncherEarnRules.php`, `includes/Engine/Storefront/LauncherPayload.php`
Message: `fix(storefront): advertise earn rows through the points engine`

### Task 4: Close the backlog item

**Files:**
- Delete: `docs/backlog/2026-10-05-launcher-repeats-the-engines-campaign-choice.md`

- [x] **Step 1: Confirm both Done when boxes are met, then delete the file**

Box 1 is Task 3 Step 4.1; box 2 is Task 2's `test_candidates_and_matches_filters_reach_the_advert` plus Task 3 Step 2. Delete the file.

- [x] **Step 2: Final verification (spec criterion 9)**

Run: `composer test && ./scripts/run.sh backlog`
Expected: `OK (11 tests, ...)`; the backlog listing no longer shows "The launcher repeats the engine's campaign choice". The storefront checks of Task 3 Step 4 still hold.

- [ ] **Step 3: Commit**

Stage: `docs/backlog/2026-10-05-launcher-repeats-the-engines-campaign-choice.md`, `docs/plans/2026-10-06-shared-campaign-choice-for-launcher.md` (Status set to `done YYYY-MM-DD`), `docs/specs/2026-10-06-shared-campaign-choice-for-launcher-design.md`
Message: `docs(backlog): close the launcher campaign-choice item`
