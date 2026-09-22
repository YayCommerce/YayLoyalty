# Points engine

Computes how many points an event earns and which campaign paid. **Writes nothing.**

```
evaluate( string $trigger, Context $ctx ): Result
```

One trigger per call. Within a trigger, highest priority wins; across triggers, the caller stacks by calling once per trigger. Liveness is scored at `Context::occurred_at` — the event's UTC time (an order's creation date), never `now()` for a stored event; there is no separate timestamp argument. `ContextFactory::from_order()` throws when the order has no creation date. Previews (`from_product`/`from_cart`) evaluate at `now()` by design.

```
1. CANDIDATES   CampaignRepository::live_for_trigger( $trigger, $ctx->get_occurred_at() )   → priority DESC, id DESC
                filter: yayloyalty_campaign_candidates
2. SELECT       first campaign whose conditions match AND whose handler says is_eligible()
                filter: yayloyalty_campaign_matches
3. BASE         purchase only: BaseCalculator                           filter: yayloyalty_earning_base_amount
4. POINTS       rate or multiplier → PointsMath::round_points()         filter: yayloyalty_points_for_order
5. CAPS         CapPolicy: max_points_per_order, daily_cap
6. RESULT       points + campaign + breakdown + reference + source, or none( reason )
```

Config gates are **eligibility, not calculation**: a boost with `min_subtotal 50` on a $30 order falls through to the default campaign and earns 1 pt/$ — it does not earn zero.

## Rules

- **Pure.** No `$wpdb`, no WooCommerce globals, no current user, no `time()`. `CampaignRepository` is the one class that queries. `ContextFactory` is the one class that touches WooCommerce.
- **No capability checks, no `actor_id`.** This runs from cron, WP-CLI and order hooks where there is no current user. Permissions live at the HTTP boundary; `actor_id` is a `LedgerService` argument.
- **The storefront exposes the integer only.** `Result` carries the campaign, the rate and the raw float — merchant strategy. Never `wp_send_json( $result )` on a Store API route; send `$result->get_points()`.

## Construction

```php
$campaigns = new CampaignRepository();
$engine    = new PointsEngine( Registry::with_lite_handlers( $campaigns ), $campaigns, new CapPolicy() );
```

`Registry::with_lite_handlers()` fires `yayloyalty_register_trigger_handlers` so Pro can add its own triggers.

## Call site 1 — award path

Lives in `Engine/Earning/` (see its README): `Listeners\OrderListener` builds the Context, `EarningService::preview()` / `award()` run the two moments, `LedgerService::earn()` writes. The engine is never called with a hand-made timestamp.

## Call site 2 — product-page preview (F13/F15, not wired yet)

```php
$ctx    = ContextFactory::from_product( $product, $qty, get_current_user_id() ?: null );
$result = $engine->evaluate( CampaignTrigger::PURCHASE, $ctx );

return [ 'points' => $result->get_points() ]; // the integer, nothing else
```

`points_earned_today` is 0 in a preview, so a daily cap shows the best case.

## `Result::get_reason()`

| Reason | Meaning |
|---|---|
| `''` | awarded |
| `no_campaigns` | nothing live for this trigger at `$at` |
| `not_eligible` | candidates existed; none matched conditions and passed its gates |
| `zero_points` | a campaign applied but rounded or capped to 0 |

## Tests

`composer test` — 152 cases under `tests/unit/` (27 incomplete by design: the earning-split invariants in `tests/unit/Earning/` and `tests/unit/Services/`), plain PHPUnit, no WordPress bootstrap (`tests/bootstrap.php` shims the hook functions): engine, the earning orchestration scaffold (`Engine/Earning/`), the reward engine scaffold (`tests/unit/Rewards/`), PointsMath shared vectors, the dashboard services (`tests/unit/Dashboard/`), campaign validation (`tests/unit/Models/`) and the drawer's query-arg groupings/fixture helpers (`tests/unit/Controllers/`). Integration against real `WC_Order` objects is exercised manually via WP-CLI until a wp-phpunit harness exists.
