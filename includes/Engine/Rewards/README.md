# Reward engine

Answers "can this customer claim this reward right now, at what cost, for what value?" **Writes nothing.**

```
evaluate( Reward $reward, Context $ctx ): Result
```

One reward per call. Unlike the points engine there is no candidate scan: the customer already chose the reward, so there is nothing to fall through to — a gate that fails is a `denied()`.

**Scaffold status:** the wiring (registry lookup, Pro seam, delegation) is real; every gate and the flexible-discount math are stubs. The Lite build always returns `denied( not_implemented )`. See "Implementing this engine" below.

```
1. HANDLER      Registry::get( $reward->get_type() )                   throws on an unknown type
2. LIVE         ! $reward->is_live_at( $ctx->get_at() )                → denied( not_live )              (TODO)
3. BALANCE      $ctx->get_balance() < cost                             → denied( insufficient_balance )  (TODO)
4. CONDITIONS   ! filter: yayloyalty_reward_conditions_match           → denied( conditions_not_met )    (TODO)
5. QUOTE        $handler->quote( $reward, $ctx )                       type math; stub today
6. RESULT       allowed( reward, points, value, currency, rate_snapshot ) or denied( reason )
```

`cost` is `$reward->get_cost_points()` for fixed-cost types and `$ctx->get_points_requested()` for `flexible_discount` — the one type where the customer picks the amount.

## Rules

- **Pure.** No `$wpdb`, no WooCommerce globals, no current user, no `time()`. The balance is a `Context` input, never a lookup — whoever builds the `Context` reads the ledger. A `ContextFactory` (the one class allowed to touch WooCommerce) does not exist yet.
- **No capability checks, no `actor_id`.** Permissions live at the HTTP boundary; the ledger write is the caller's.
- **The storefront exposes the numbers only.** `Result` carries the `Reward` and the rate snapshot — merchant strategy. Send `get_points()` and `get_value()` on a Store API route, never the object.
- **Handlers never throw.** A config gate that fails is `Result::denied( 'your_reason' )`; the engine throws only for a type nothing registered.

## Construction

```php
$engine = new RewardEngine( Registry::with_lite_handlers() );
```

`Registry::with_lite_handlers()` fires `yayloyalty_register_reward_handlers` so Pro can add handlers for `fixed_coupon`, `percent_coupon`, `free_shipping`, `free_product`.

## Call site 1 — checkout apply (not wired yet)

```php
$ctx    = new Context( $user_id, $balance, $points_requested, $cart_subtotal, $currency, Dates::now() );
$result = $engine->evaluate( $reward, $ctx );

if ( $result->is_allowed() ) {
    // The caller inserts the redemptions row and writes the ledger through
    // LedgerService::redeem() — both belong to the write-path task, not here.
}
```

## Call site 2 — catalog claim (not wired yet)

Same call with `points_requested` null and `cart_subtotal` null; the cost comes from `cost_points`.

## `Result::get_reason()`

| Reason | Meaning |
|---|---|
| `''` | allowed |
| `not_live` | archived, paused, or outside `starts_at`/`ends_at` at `$ctx->get_at()` |
| `insufficient_balance` | balance below the cost |
| `conditions_not_met` | the reward's conditions filter said no |
| `not_implemented` | scaffold stub reached; nothing else was checked |
| anything else | handler-specific, e.g. `below_min_points` |

## Implementing this engine

In this order, each with its test in `tests/unit/Rewards/`:

1. `RewardEngine` gate 1 — liveness.
2. `RewardEngine` gate 2 — balance vs cost.
3. `RewardEngine` gate 3 — conditions filter (leave the default `true`; a reward conditions evaluator is its own task and `redemption_count` needs the redemptions table).
4. `FlexibleDiscountHandler::quote()` — `min_points`, `increment`, `max_points_per_order`, `max_percent_of_subtotal`, then value through `Utils\PointsMath`. Replace the `not_implemented` assertion in `RewardEngineTest` when you do.
5. Pro handlers under `includes/Pro/`, registered from `YayLoyaltyPro` via the action.
6. Then the write path: `redemptions` table, `RedemptionRepository`, `LedgerService::redeem()`, `ContextFactory` — a separate plan.

## Tests

`composer test -- --filter Rewards` — plain PHPUnit, no WordPress bootstrap: `Result`, `Registry` (including the Pro seam) and the engine's delegation.
