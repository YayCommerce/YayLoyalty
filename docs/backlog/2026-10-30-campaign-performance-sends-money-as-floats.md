# Campaign performance sends money as floats, and the redeem card defaults to 2 decimals

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

`docs/conventions.md` (Money) says REST sends money as a string that the SPA parses with `z.string()`, that computed money is rounded to the store currency's decimals and "never to a fixed 2", and that admin reports stay in the base currency.

- `Pro\Repositories\CampaignPerformanceRepository::shape_entry()` sends each entry's `subtotal` from `order_subtotal()`, which returns `(float) $order->get_subtotal()`: a float, in the order's own currency. `campaignPerformanceEntrySchema` in `apps/admin/src/lib/schema/campaign-performance.schema.ts` parses it with `z.number()`.
- `campaignPerformanceSchema`'s `totals.avg_order_subtotal` is `z.number().nullable()`. `Pro\Controllers\CampaignPerformanceRestController` always sends null for it today.
- `Engine\Storefront\RedeemCard::view()` declares `int $decimals = 2`. Its one production caller, `RedeemCard::markup()`, passes `wc_get_price_decimals()`; only `tests/unit/Engine/Storefront/RedeemCardViewTest.php` relies on the default.

`Engine\Storefront\LauncherPayload`'s `worth`, `estimate_off` and `unlock_off` are floats too, but they are not sent over REST. PHP templates print them through `LauncherCopy::money()`, and `Clamp` has already rounded them to the charged currency's decimals, so the convention doesn't cover them.

## Why it matters

A float subtotal can arrive as 19.999999999999996, and the entries table prints a non-base-currency order's subtotal with the base currency's symbol. The `= 2` default would round a 0-decimal currency (VND) or a 3-decimal one (KWD) wrongly for the next caller that forgets the argument.

## Suggested fix

Send `subtotal` as a decimal string converted to the base currency with `CurrencyRate::to_base()`, and parse it and `avg_order_subtotal` with `z.string().nullable()`. Remove the default from `RedeemCard::view()`'s `$decimals` and pass it in the tests. Left out of the rename because it changes the wire shape.

## Done when

- [ ] `CampaignPerformanceRepository::order_subtotal()` returns a decimal string or null, not a float.
- [ ] `CampaignPerformanceRepository::order_subtotal()` converts the order's subtotal to the base currency with `CurrencyRate::to_base()`.
- [ ] `campaignPerformanceEntrySchema`'s `subtotal` is `z.string().nullable()`.
- [ ] `campaignPerformanceSchema`'s `totals.avg_order_subtotal` is `z.string().nullable()`.
- [ ] `RedeemCard::view()`'s `$decimals` parameter has no default value.
