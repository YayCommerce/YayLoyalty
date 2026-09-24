# Redemption orchestration

Redemption has three zones, and every class belongs to exactly one:

| Zone | What | Where | Purity |
|---|---|---|---|
| **1 — QUOTE** | how many points can be spent on this cart, what that buys, why it was limited | `Engine/Rewards/*` (pure) reached through `Quoter` and `RedemptionService::quote()`; `SessionIntent` (the one integer); `CouponProvider` (materialises the quote as a virtual coupon) | writes nothing; runs on every cart recalculation |
| **2 — COMMIT** | debit once per order: redemptions row + ledger row + customer cache + order meta, one transaction | `RedemptionService::commit()` → `CommitWriter` → `LedgerService::redeem()` | impure by definition |
| **3 — REVERSE** | cancel / refund restore | **not designed.** Named seam: `Engine\Redemption\Reversal`, attached from `Earning\Listeners\OrderListener::on_cancelled()` / `on_refunded()`; needs `LedgerService::refund_redeem()` and `Redemption::restored_points` | — |

Around them: `Adapters/*`, one per checkout surface, which only translate hooks into service calls.

```
cart change ─┐
coupon apply ├─► CouponProvider::sync_cart ─► RedemptionService::quote( cart, user )
checkout load┘                                   ├─ reward = RewardRepository::find_by_slug( cart-discount )
                                                 ├─ ctx = ContextFactory::from_cart( cart, user, SessionIntent::get() )   reads only
                                                 └─ Quoter::quote( reward, ctx )   gates → denied( reason ) | RewardEngine::evaluate → FlexibleDiscountHandler → Clamp::apply → Result
                                 woocommerce_get_shop_coupon_data( yayloyalty-redeem ) ← CouponProvider::virtual_coupon_data: amount = Result::get_value()
                                 woocommerce_coupon_sort ← CouponProvider::sort_weight: PHP_INT_MAX, ours applies LAST

slider / block ─► SessionIntent::set( points )            the ONLY write on the quote path, unclamped

checkout submit ─► Adapter::validate ─► RedemptionService::checkout_error( cart, user ) → reject with the reason / bound_by

order processed ─► Adapter::on_order_processed ─► RedemptionService::commit( order )
                                                   └─ CommitWriter::commit( order, reward )      Transaction::run
                                                        ├─ CustomerRepository::ensure_row + lock_and_get       SELECT … FOR UPDATE
                                                        ├─ RedemptionRepository::find_by_order → Outcome::duplicate
                                                        ├─ ctx = ContextFactory::from_order( order, LOCKED balance ); Quoter::quote again
                                                        │     fewer points than the frozen coupon line → order note, Outcome::skipped, NO debit
                                                        ├─ RedemptionRepository::insert( applied row )
                                                        ├─ LedgerService::redeem( draft )     UNIQUE idempotency_key; balance never < 0
                                                        └─ order meta _yayloyalty_points_redeemed / _yayloyalty_redeem_value
                                                   └─ SessionIntent::clear(); after commit: yayloyalty_ledger_recorded, yayloyalty_points_redeemed
```

## Invariants (the test names under `tests/unit/Redemption/` and `tests/unit/Rewards/ClampTest.php` are the specification)

1. **Zone 1 writes nothing.** Not the ledger, not the customer row, not an option, transient or order. `RedemptionService::quote()` touches only the `Quoter` (which holds the engine and the gates and nothing that can write), `ContextFactory` (typed on `Repositories\CustomerReader`, an interface with no write method), the reward repository (reads) and `SessionIntent::get()`. `CommitWriter` is the only holder of anything that writes; `quote()` never references it.
2. **Intent, not money, in the session.** `SessionIntent` holds one integer — the points requested — unclamped. The discount value is derived from the current cart on every recalculation.
3. **One clamp.** `Engine\Rewards\Clamp::apply()` is the only place a limit is computed; it reports which ceiling bound (balance, reward min/max, cart, step). Gates decide yes/no; adapters decide nothing.
4. **Whole currency units by default.** The seeded `increment` equals `points_per_currency_unit`; the clamp floors to it.
5. **Virtual coupon.** `yayloyalty-redeem` through `woocommerce_get_shop_coupon_data` — never a `shop_coupon` post on the 1.0 path. `individual_use` false; `woocommerce_coupon_sort` moves it last so the cart ceiling is the subtotal every real coupon already reduced. No free shipping (a Pro reward type, not a property of this discount).
6. **Two adapters, one rule set.** `Adapters/ClassicCheckoutAdapter` and `Adapters/StoreApiAdapter` call only `quote()`, `checkout_error()` and `commit()`.
7. **One transaction at commit**, locking read first, re-quote with the locked balance, redemptions row and ledger row together, aggregates, order meta, session cleared. The balance never goes below zero: `LedgerService::redeem()` hard-refuses under the same lock (a refund clawback may go negative; a redemption may not).
8. **Affordability before totals freeze.** `checkout_error()` from `woocommerce_after_checkout_validation` / `woocommerce_store_api_cart_errors` rejects the submission. If the commit still loses a race, the order stands **without** the debit and gets an order note — never half-written, never retried.
9. **Reuse.** One ledger writer (`LedgerService`, `redeem()` beside `earn()` over the same private `record()`), one key composer (`Earning\IdempotencyKey`, qualifier = reward type for redeem rows), one transaction helper (`Database\Transaction`), one outcome shape (`Earning\Outcome::redeemed()`).

## Double-debit guard

`RedemptionsTable` UNIQUE `(order_id, reward_id)` plus `find_by_order()` under the customer lock. The ledger key `redeem:{user}:redemption:{id}:{reward_type}` is a second belt: it cannot guard on its own because the redemption id is assigned inside the same transaction.

## Status

Scaffold. Real: `Quoter`, `Engine\Rewards\Clamp`, the value objects (`Result`, `Context`, `Models\Redemption`), `LedgerService`'s shared `record()`. Every other body is a `TODO(F08)` stub naming what goes there: `RedemptionService::build()/quote()/checkout_error()/commit()`, both gates, `SessionIntent`, `CouponProvider`, `CommitWriter`, both adapters, `RedemptionRepository`, `RedemptionsTable::definition()` (not yet in `Installer::schemas()`), `LedgerService::redeem()`, `Rewards\ContextFactory`. Not built and not started: the block UI (a storefront package reading `extensions.yayloyalty` and posting the `yayloyalty-redeem` update callback), Zone 3, Pro catalog coupons.
