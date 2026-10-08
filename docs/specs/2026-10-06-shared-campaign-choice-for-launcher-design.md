# Shared campaign choice for the launcher Design

**Status:** superseded 2026-10-08 by [docs/plans/2026-10-08-launcher-advert-review-fixes.md](../plans/2026-10-08-launcher-advert-review-fixes.md)
**Related:** [backlog: the launcher repeats the engine's campaign choice](../backlog/2026-10-05-launcher-repeats-the-engines-campaign-choice.md)

## Goal

The storefront launcher's "Ways to earn" never promises a rate the engine won't pay for the reasons the engine can know without an event. `PointsEngine` and the launcher pick a trigger's campaign through one loop, so the zero-points stop rule and the `yayloyalty_campaign_candidates` / `yayloyalty_campaign_matches` filters apply to both.

## Context

- `PointsEngine::evaluate()` (`includes/Engine/Points/PointsEngine.php`) runs the live campaigns through `yayloyalty_campaign_candidates`, keeps the first that passes `yayloyalty_campaign_matches` and the handler's `is_eligible()`, then computes points and applies `CapPolicy`. At 0 points it stops with `Result::REASON_ZERO_POINTS`; it never tries the next campaign.
- `LauncherPayload::live_campaigns()` (`includes/Engine/Storefront/LauncherPayload.php`) reads `CampaignRepository::live_for_trigger()` per trigger, through neither filter, and hands the list to `LauncherEarnRules::advertise()` (`includes/Engine/Storefront/LauncherEarnRules.php`). Its private `winners()` keeps the first campaign per trigger that `PointsEngine::applies_to_everyone()` passes and whose value is above 0. A 0-value campaign is skipped and the next one is advertised, so the panel can promise a rate the engine never pays.
- Nothing subscribes to `yayloyalty_campaign_candidates`, `yayloyalty_campaign_matches` or `yayloyalty_register_trigger_handlers` today, in Lite or Pro. Pro's only engine seam is `PointsEngine::CONDITIONS_EVALUATOR_FILTER`.
- Event-less Contexts already exist for previews: `ContextFactory::from_product()` and `from_cart()`; `LauncherPayload::product_estimate()` calls `evaluate()` with one.
- Handlers: `PurchaseHandler`, `SignupHandler`, `ReviewHandler` in `includes/Engine/Points/Triggers/`, behind the `TriggerHandler` interface. `is_eligible()` false means "fall through to the next campaign"; 0 points means "stop".
- Unit tests: `tests/unit/EngineTestCase.php` has campaign, context and in-memory repository builders; `tests/bootstrap.php` shims `apply_filters`/`add_filter`. No `PointsEngine` test exists yet.

## Scope

**In:**
- One private selection loop in `PointsEngine`, used by `evaluate()` and a new public `advertised()`.
- An advertising Context: `Context::advert()`, `Context::is_advert()`, `ContextFactory::for_advert()`.
- `TriggerHandler::advertised_value()` on the interface and the three Lite handlers.
- `LauncherEarnRules::advertise()` and `LauncherPayload` switched to `PointsEngine::advertised()`; the launcher's own loop removed.
- Unit test `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`.
- Deleting the backlog item in the implementing PR, since both Done when boxes are met.

**Out:**
- Caps (`daily_cap`, `max_points_per_order`) on the advertised value. A review paying 50 with `daily_cap` 30 still advertises 50. Documented in `advertised()`'s docblock as the gap no event-less check can close.
- Event-dependent gates: `min_subtotal`, `exclude_coupon_orders`, `require_verified`, `one_per_product` are not checked when advertising. `min_subtotal` keeps its "minimum" line (`purchase_min`).
- `LauncherPayload::product_estimate()` behaviour, advertising Pro triggers, any admin or SPA change.
- A decision record: merging two loops is cheap to undo and changes no architectural choice.

## Acceptance criteria

1. Given live purchase campaigns A (priority 10, `points_per_unit` 0) and B (priority 0, `points_per_unit` 2), both with no conditions, when `PointsEngine::advertised( CampaignTrigger::PURCHASE, $advert_ctx )` runs, then it returns `null`, and `LauncherEarnRules::advertise()` returns `earn['purchase'] === null` and `purchase_min === 0.0`.
2. Given a multiplier purchase campaign (priority 10) while the default purchase campaign is paused, then a rate campaign with `points_per_unit` 2, `advertised()` returns the rate campaign with `value` 2.0.
3. Given a conditional campaign (priority 10, non-empty `conditions`) with a conditions evaluator attached through `PointsEngine::CONDITIONS_EVALUATOR_FILTER`, then a campaign with no conditions paying 3, `advertised()` returns the second campaign with `value` 3.0, and the evaluator's `evaluate()` is never called.
4. A callback on `yayloyalty_campaign_candidates` that removes the first campaign changes what `advertised()` returns; a callback on `yayloyalty_campaign_matches` receives a `Context` whose `is_advert()` is `true`.
5. `advertised()` called with a Context whose `is_advert()` is `false` throws `\InvalidArgumentException`.
6. `evaluate()` returns the same `Result` as before for every event Context: same points, campaign and reason.
7. When `PointsEngine::advertised()` throws for a trigger (a filter callback or handler fails), `LauncherPayload::for_balance()` logs `Log::warning( 'LauncherPayload', ... )`, sets every `earn` value to `null` and `purchase_min` to 0.0, and the page still renders.
8. Signup and review rows advertise `(float) flat_points()` of the chosen campaign, cast to `int` in `earn`; `earn['signup']` is `null` when the store lets no shopper register, as today.
9. End-to-end verification: `composer test` passes, including `PointsEngineAdvertisedTest`. On the storefront, with purchase campaign A (higher priority, 0 points per unit) and B (2 points per unit), the launcher panel shows no purchase row; after editing A to 1 point per unit, the panel shows 1 point per currency unit.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- `PointsEngine` and `Context` stay WordPress-free; only `ContextFactory` reads WooCommerce.
- Lite names Pro nowhere new; Pro attaches only through existing filters.
- Filter callbacks always receive a `Context`, never `null`.
- `advertised()` writes nothing and scores at the given Context's `occurred_at`, never `now()`.
- Keep each touched file under 200 lines where it already is.

## Approach

**Chosen:** advertising Context through the engine's own loop.

**Architecture:** `PointsEngine` gains a private `pick( TriggerHandler $handler, string $trigger, Context $ctx ): ?Campaign` holding today's loop: candidates filter, matches filter, then eligibility, returning the first campaign that passes. Eligibility is `$handler->is_eligible( $campaign, $ctx )` for an event Context and `null !== $handler->advertised_value( $campaign )` for an advertising one; `conditions_match()` returns `applies_to_everyone()` for an advertising Context without calling Pro's evaluator. `evaluate()` becomes `pick()` then compute and caps, unchanged in behaviour. `advertised()` becomes `pick()` then `advertised_value()`, returning `null` at a value ≤ 0: the engine's zero-points stop.

**Components:**
- `includes/Engine/Points/PointsEngine.php`: `pick()` (private), `advertised( string $trigger, Context $ctx ): ?array` returning `array{campaign: Campaign, value: float}` or `null`; throws `\InvalidArgumentException` when `! $ctx->is_advert()` or no handler owns the trigger.
- `includes/Engine/Points/Context.php`: `public static function advert( string $at, string $currency, float $base_rate ): self` (guest, no lines, 0 amounts, no coupons, no roles, no customer facts) and `is_advert(): bool`, backed by a private `$advert` property set only by `advert()`.
- `includes/Engine/Points/ContextFactory.php`: `for_advert( string $at ): Context`, reading `get_woocommerce_currency()` and `base_rate()` like `from_product()`.
- `includes/Engine/Points/Triggers/TriggerHandler.php`: `advertised_value( Campaign $campaign ): ?float`. `null` = skipped whatever the event (the engine falls through); `0` = pays nothing (the engine stops); above 0 = points per currency unit (purchase) or flat points.
- `PurchaseHandler::advertised_value()`: `null` when `is_multiplier( $config )` and `multiplier_default( find_default_purchase() )` is null; otherwise `rate_of( $config, find_default_purchase() )`.
- `SignupHandler::advertised_value()`, `ReviewHandler::advertised_value()`: `(float) $campaign->flat_points()`.
- `includes/Engine/Storefront/LauncherEarnRules.php`: `advertise( PointsEngine $engine, Context $ctx, bool $can_register ): array` with the same return shape; calls `$engine->advertised()` per trigger in `TRIGGERS`. Removes `winners()`, `purchase_rate()`, `flat_points()` and the `$default_purchase` parameter.
- `includes/Engine/Storefront/LauncherPayload.php`: builds `PointsEngine::build( $this->campaigns )` once per `for_balance()` and shares it with `product_estimate()`; passes `ContextFactory::for_advert( $now )`; wraps `advertise()` in `try/catch ( \Throwable )` with `Log::warning`. Removes `live_campaigns()`.
- `tests/unit/Engine/Points/PointsEngineAdvertisedTest.php`: criteria 1–5, extending `EngineTestCase`.

**Data and storage:** None.

**Integration points:** `yayloyalty_campaign_candidates` and `yayloyalty_campaign_matches` now also fire on every storefront page that renders the launcher, with an advertising Context; their docblocks say so and name `Context::is_advert()`. `TriggerHandler` gains a method: any handler registered through `yayloyalty_register_trigger_handlers` must implement it (none exist today). `PointsEngine::CONDITIONS_EVALUATOR_FILTER` is not reached when advertising.

**UI:** None. The launcher templates read the same `earn` / `purchase_min` shape.

**Edge cases and failure modes:**
- First campaign that applies to everyone pays 0: no row for that trigger.
- Multiplier with a paused default: skipped, next campaign advertised, as the engine falls through.
- Conditional campaign first: skipped, next campaign that applies to everyone advertised (today's rule: never promise a rate some shoppers miss).
- A filter callback that ignores `is_advert()` sees a guest with an empty cart; at worst the launcher skips a campaign.
- A throwing callback or handler: logged, no earn rows, page renders.
- No live campaign for a trigger: `null`, as today.

**Decision record:** None.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Pass `null` as the filters' Context when advertising | A third-party callback type-hinted `Context $ctx` would fatal on every storefront page. |
| Separate `yayloyalty_advertised_campaign_*` filters around a shared loop | Fails the backlog's second box: the existing candidates and matches filters would still not reach the launcher. |
| Only make `winners()` stop at 0 | Closes the zero-points gap only; the two loops keep drifting. |

## Rollout

None. No stored data or settings change. A store whose first everyone-campaign for a trigger pays 0 stops seeing that trigger's row in the launcher, which matches what the engine already pays.

## Open questions

None.
