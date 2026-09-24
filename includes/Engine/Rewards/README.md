# Reward engine (ZONE 1 — the quote)

Answers "for this customer, this cart and this reward: how many points can be spent, what discount does that buy, and which limit bit?" **Writes nothing.** Runs on every cart recalculation, so it is cheap and side-effect free: no `$wpdb`, no WooCommerce globals, no current user, no `time()`.

```
evaluate( Reward $reward, Context $ctx ): Result          RewardEngine — type handler lookup + quote
Clamp::apply( Reward, int $requested, int $balance, ?float $cart_subtotal, string $currency, int $decimals ): Result
```

One reward per call. The customer already chose the reward, so there is no candidate scan and no eligibility here — liveness, balance vs a fixed cost, conditions and program state are `Engine\Redemption` gates that run **before** `evaluate()`.

## The clamp — one place, pure

`Clamp` is the single source of every limit. No caller re-clamps: the cart quote, checkout validation and the commit's re-check under the lock all call it and get the same answer. It returns the number **and** which ceiling bound it (`Result::get_bound_by()`), so the UI can explain itself:

| `bound_by` | Ceiling |
|---|---|
| `balance` | the customer's spendable points |
| `reward_max` | config `max_points_per_order` |
| `cart` | the subtotal after every other coupon, tightened by `max_percent_of_subtotal` |
| `step` | floored to config `increment` (reported only when nothing else bound) |
| `reward_min` | below config `min_points` → 0 points (reported unless a ceiling bound) |
| `''` | the request stood |

Ceilings are checked in that order and the first that reduces the request is reported. The seed's `increment` equals `points_per_currency_unit`, so a step is one whole currency unit by default and `points / rate` never leaves a fractional remainder. A reward with no usable rate is `denied( no_rate )` — the only denial the clamp produces.

`Result::allowed()` with `get_points()` 0 is a valid quote ("nothing applies yet, here is why"); a coupon needs `get_points() > 0`.

## Rules

- **Pure.** Everything arrives on `Context`, built by `ContextFactory` (the one class that reads WooCommerce and the customer row; it depends on `Repositories\CustomerReader`, an interface with no write method) or by hand in a test. Holding a `Context` grants no way to write.
- **The value is never stored.** `Result` is derived from the current cart every time; the only persistent state on this path is the one integer in `Engine\Redemption\SessionIntent`.
- **No capability checks, no `actor_id`.** Permissions live at the HTTP boundary; the ledger write is `Engine\Redemption\CommitWriter`'s.
- **The storefront exposes the numbers only.** `Result` carries the `Reward` and the rate snapshot — merchant strategy. Send requested / applied / value / currency / bound_by, never the object.
- **Handlers never throw.** A failed config gate is `Result::denied( 'your_reason' )`; the engine throws only for a type nothing registered.

## Construction

```php
$engine = new RewardEngine( Registry::with_lite_handlers() );
```

`Registry::with_lite_handlers()` fires `yayloyalty_register_reward_handlers` so Pro can add handlers for `fixed_coupon`, `percent_coupon`, `free_shipping`, `free_product` (catalog rewards, each a real single-use coupon — the Pro seam, not the 1.0 path).

## Call site

`Engine\Redemption\RedemptionService::quote()` (cart) and `CommitWriter::commit()` (order, under the lock). See `includes/Engine/Redemption/README.md`.

## `Result::get_reason()`

| Reason | Meaning |
|---|---|
| `''` | allowed (points may be 0 — read `bound_by`) |
| `not_live` | gate: archived, paused, or outside `starts_at`/`ends_at` at `$ctx->get_at()` |
| `insufficient_balance` | gate: fixed-cost type, balance below `cost_points`; at commit, the locked balance cannot cover the frozen points |
| `conditions_not_met` | gate: the `yayloyalty_reward_conditions_match` filter said no |
| `no_rate` | clamp: config has no usable `points_per_currency_unit` |
| anything else | gate- or handler-specific (`no_customer`, `program_paused`, `coupons_disabled`, `no_reward`) |

## Status

Scaffold. Real: `Clamp`, `Result`, `Context`, `RewardEngine::evaluate()`, `Registry`. Stubs (`TODO(F08)`): `FlexibleDiscountHandler::quote()` (one line — delegate to `Clamp`), `ContextFactory::from_cart()` / `from_order()`.

## Tests

`composer test -- --filter Rewards` — plain PHPUnit, no WordPress bootstrap: `ClampTest` (every ceiling, reporting, steps, float edges), `ResultTest`, `RegistryTest` (including the Pro seam), `RewardEngineTest` (delegation; the handler test is incomplete until its body lands).
