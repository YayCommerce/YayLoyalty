# Points engine

Computes how many points an event earns and which campaign paid. **Writes nothing.**

```
evaluate( string $trigger, Context $ctx, string $at ): Result
```

One trigger per call. Within a trigger, highest priority wins; across triggers, the caller stacks by calling once per trigger. `$at` is the event's UTC time (an order's creation date), never `now()`.

```
1. CANDIDATES   CampaignRepository::live_for_trigger( $trigger, $at )   → priority DESC, id DESC
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

## Call site 1 — award path (order listener, F01-4)

```php
$ctx    = ContextFactory::from_order( $order, $ledger->points_earned_today( $order->get_customer_id() ) );
$result = $engine->evaluate( CampaignTrigger::PURCHASE, $ctx, $ctx->get_occurred_at() );

if ( $result->is_awarded() ) {
    // The caller writes the ledger through LedgerService (an earn() entry point is F01-4's to add;
    // only adjust() exists today). actor_id is null: a customer placing an order is not an actor.
    $ledger_service->earn(
        $ctx->get_user_id(),
        $result->get_points(),
        $result->get_source(),
        $result->get_reference_type(),
        $result->get_reference_id(),
        $result->get_campaign()->get_id()
    );
}
```

Repeat for `first_order` and any other trigger the order satisfies; each is its own ledger row and its own idempotency key.

## Call site 2 — product-page preview (F13/F15)

```php
$ctx    = ContextFactory::from_product( $product, $qty, get_current_user_id() ?: null );
$result = $engine->evaluate( CampaignTrigger::PURCHASE, $ctx, $ctx->get_occurred_at() );

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

`composer test` — 93 cases under `tests/unit/`, plain PHPUnit, no WordPress bootstrap (`tests/bootstrap.php` shims the hook functions). Engine tests plus PointsMath shared vectors. Integration against real `WC_Order` objects is exercised manually via WP-CLI until a wp-phpunit harness exists.
