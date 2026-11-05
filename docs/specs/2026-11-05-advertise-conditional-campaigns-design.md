# Advertise Conditional Campaigns Design

**Status:** accepted 2026-11-05
**Related:** [Shared campaign choice for the launcher](2026-10-06-shared-campaign-choice-for-launcher-design.md), [Product-page earning message](2026-10-10-product-page-earning-message-design.md), [Launcher advert review fixes](../plans/2026-10-08-launcher-advert-review-fixes.md)

## Goal

On a Pro store, a shopper can see the campaigns with conditions that apply or could apply to them: the right figure on the product page, their own rate in "Ways to earn", "Bonus offers" rows for campaigns they could still reach, and a nudge in the cart and checkout. The storefront never promises more than the order pays.

## Context

- `PointsEngine::advertised()` (`includes/Engine/Points/PointsEngine.php`) never evaluates conditions. A conditional campaign can only lower the promise, and with no campaign for everyone the row is hidden. `LauncherPayload::for_balance()` always passes `ContextFactory::for_advert()`, so a member who qualifies sees the same row as a guest.
- `ContextFactory::from_product()` has no `order_id` and no facts loader. `ConditionsEvaluator::missing_input()` therefore fails every `INPUT_ORDER` condition with `needs_order` and every `INPUT_FACTS` condition with `no_customer`. Only `user_role` and `customer_ids` work, and only for a member. `not_in` fails too.
- Overpromise bug: campaign A (priority 10, `category_in_cart` Shoes, 0.5 per unit) above B (no conditions, 1 per unit). The product page skips A and shows 1, and the order pays 0.5.
- `from_comment()` passes no lines and no order, so at award a review campaign fails every `INPUT_ORDER` condition. Those conditions can never pay on a review campaign.
- `Points\ContextFactory::from_cart()` has no caller on `dev/edd`. `paul/feat/guest-order-points` adds the first (`ProductEarnMessage::guest_cart_points()`).
- `BaseCalculator` earns on every included line of the order, so a condition on one category sets the rate for the whole order, minus the campaign's excluded products and categories.
- `yayloyalty_launcher_earn_rows` is the seam Pro already uses for an extra row (`BirthdayEarnRow`). The launcher prints a row's `label` and `value`; only My Account prints `description`.
- `StoreApiAdapter::cart_data()` carries the redeem card's HTML to `assets/storefront/redeem-blocks.js`, which is enqueued on the Cart and Checkout blocks with or without a live reward.
- "Ways to redeem" lists rewards only. Campaigns don't appear there.

## Scope

**In:**
- A three-state verdict for conditions when no order exists, shared by all four surfaces.
- Product page: real evaluation of what the page knows, and the overpromise fix.
- "Ways to earn": a member's own rate.
- "Bonus offers" rows in "Ways to earn", with text generated from conditions, for `purchase` and `review` campaigns.
- A nudge on the classic cart and checkout and the Cart and Checkout blocks.
- The switch `visibility.show_bonus_offers`.
- `docs/architecture.md` and one decision record.

**Out:**
- "Ways to redeem" and reward conditions.
- Merchant-written offer text and a per-campaign "show on storefront" option.
- Offers for `signup` and the other Pro triggers (`birthday`, `first_order`, `order_count`, `spend_milestone`).
- Reading the shopper's country or chosen payment method before the order exists: these stay unknown.
- Product cards on shop, archive and related-product listings.
- Any change to what an order pays: award contexts behave as today.
- Any change in Lite: with no evaluator every campaign stays for everyone, and the nudge filter has no subscriber.

## Acceptance criteria

Rates below are `points_per_unit`. B is a campaign with no conditions at 1, below the campaign under test.

**Verdict**

1. `ConditionsEvaluator::verdict()` returns `false` when any condition fails for a reason other than `needs_order`, else `null` when any fails with `needs_order`, else `true`. `evaluate()` and `explain()` return what they return today for an order, and `from_user()` still reports `needs_order` for `product_in_cart`, `category_in_cart` and `currency`.

**Product page** (guest and member alike unless stated)

2. A at 0.5 on `category_in_cart` Shoes: a Shoes product shows the 0.5 figure, any other product the 1 figure. A at 3: Shoes shows the 3 figure, others the 1 figure.
3. `payment_method in [stripe]` at 0.5: shows the 0.5 figure. The same campaign at 3: shows the 1 figure.
4. An unknown campaign that pays 0 on this product: no line shows. Only unknown campaigns live, with no campaign for everyone: no line shows.
5. `order_count gte 5` at 2: a member with 5 paid orders sees the 2 figure, a member with 1 and a guest see the 1 figure.
6. A variable product with 10 variations and a facts condition reads `CustomerFactsReader` once per request.

**Ways to earn**

7. A member with 5 paid orders sees the `order_count gte 5` campaign's rate in "Make a purchase"; a member with 1 sees B's.
8. A guest's rows are unchanged, and for a Context with no user the evaluator is not called.
9. For a member, `category_in_cart` at 3 above B leaves the row at 1, and at 0.5 lowers it to 0.5.

**Bonus offers**

10. `category_in_cart` Shoes at 2 above B: guest and member see a row labelled "Orders with Shoes" with the value `LauncherCopy::earn_rate()` gives for 2.
11. No row shows for a campaign that: holds a `customer_ids` or `user_role` condition; holds a condition the whitelist can't describe; pays no more than the row it would add to; sits below the first campaign this shopper is sure to get; or sits below a conditional campaign paying less. A category or product that no longer exists or isn't published hides the campaign.
12. A member for whom the campaign's verdict is `true` sees no offer for it. A member with 1 order sees `order_count gte 5` as "Orders after 5 orders". A member for whom only `gte`/`gt` conditions fail still sees it.
13. At most 3 offer rows show, in candidate order, per viewer.
14. With `visibility.show_bonus_offers` off, or the program off, no offer rows and no nudge show.
15. A `review` campaign shows an offer only when every condition is a history condition. `category_in_cart` on a review campaign shows none.

**Nudge**

16. Offer `category_in_cart` Shoes at 2, a cart with no Shoes: the classic cart, classic checkout, Cart block and Checkout block show "Add an item from Shoes to earn 2 Points per $1 on this order." Adding a Shoes item removes it in the same response. Only the first qualifying offer shows.
17. An offer with no `category_in_cart` or `product_in_cart` condition, or with another condition the verdict marks `false`, gives no nudge. An empty cart gives no nudge. In Lite the filter returns '' and nothing prints.

**End to end**

18. Walkthrough on a Pro site, with campaigns A (Shoes, 2) and B (1): as a guest, a Shoes product shows the 2 figure, the launcher shows "Orders with Shoes", a cart of another product shows the nudge. Add a Shoes item: the nudge goes. Place the order as a member: the points awarded equal 2 per unit, matching the cart. On the Lite zip, the same pages show what they show on `dev/edd`, and `./scripts/run.sh release lite --no-prompt` passes.

## Constraints

- Product PHP runs on PHP 7.4 (hard rule 4, and the extra list in `.claude/rules/php-standards.md`).
- Lite names Pro only in the guarded `boot()` call. Pro attaches only through the filters `boot()` adds. Storefront output hooks live in Lite; Pro answers a filter.
- The SPA switch renders behind `IS_PRO_BUILD`, imported through `apps/admin/src/pro/index.ts`.
- The evaluator queries nothing: facts come from the Context's loader.
- `EarningService::award()` and every Context built by `from_order()`, `from_user()` or `from_comment()` take the same path as today.
- Nothing personal in guest output, so cached pages stay safe.
- A failure in any new storefront code logs through `Log::warning()` and costs only that row or line.
- New strings use the `yayloyalty` text domain and the merchant's points label.
- No ledger writes.

## Approach

**Chosen:** one three-state verdict in the engine, with each surface a thin reader of it.

**Architecture:** Before an order exists a condition is true, false or unknown. `PointsEngine` walks candidates by priority: `true` wins and stops the walk, `false` skips, and `null` lowers the promise and the walk goes on. A walk that ends without a `true` promises nothing. The product page gets this through a Context flagged as an estimate, so `evaluate()` and `EarningService::preview()` keep their signatures. A member's "Ways to earn" gets it through an advert Context that carries a user. Offers are the campaigns the same walk passes on the way, filtered by what can be described in words.

**Components:**

*Phase 1: product page*
- `includes/Enums/CampaignConditionType.php`: `INPUT_BASKET = 'basket'` on `product_in_cart`, `category_in_cart` and `currency`. `payment_method` and `country` keep `INPUT_ORDER`.
- `includes/Engine/Points/Context.php`: `as_estimate(): self` (a flagged clone) and `is_estimate(): bool`.
- `includes/Engine/Points/ContextFactory.php`: `from_product()` and `from_cart()` return estimates with a facts loader, memoised per user for the request.
- `includes/Pro/Services/ConditionsEvaluator.php`: `missing_input()` reports `needs_order` for `INPUT_BASKET` only when the Context has neither an order nor lines. Adds `verdict( array $conditions, Context $ctx ): ?bool`, folded from `explain()`.
- `includes/Engine/Points/PointsEngine.php`: a private `verdict( Campaign, Context ): ?bool`. `true` for a campaign for everyone, `null` for any other campaign on an advert with no user, the evaluator's `evaluate()` for an event, and its `verdict()` otherwise. `evaluate()` on an estimate walks the candidates, considering only campaigns that pass `is_eligible()`, and returns the lowest result: `REASON_ZERO_POINTS` at 0, and `REASON_NOT_ELIGIBLE` when no `true` was reached.

*Phase 2: member rate*
- `Context::advert()` gains optional `?int $user_id = null`, `array $user_roles = []` and `$customer_facts = null`.
- `ContextFactory::for_member_advert( string $at, int $user_id ): Context`.
- `PointsEngine::advertised()` uses the private `verdict()` with the same walk.
- `LauncherPayload::for_balance()` gains `?int $user_id = null`, passed by `build()`; a set id builds the member advert. `BirthdayEarnRow`, `Launcher::viewer_can_earn()` and `ProductEarnMessage::guest_line()` keep `for_advert()`.

*Phase 3: offers*
- `PointsEngine::advertised_offers( string $trigger, Context $ctx ): array`: `array<int, array{campaign: Campaign, value: float}>`. Walks candidates until the first `true`. Each conditional campaign paying above 0 and above the lowest value seen so far among conditional campaigns is kept.
- `includes/Pro/Storefront/ConditionalOfferCopy.php`: `label( Campaign ): ?string`, null when the campaign can't be described. Label is the noun ("Orders", "Reviews") and the clauses, joined by a comma:

  | Condition (operator) | Clause | Purchase | Review |
  |---|---|---|---|
  | `category_in_cart`, `product_in_cart` (`in`) | "with Shoes, Bags" | yes | no |
  | `country` (`in`) | "in Vietnam" | yes | no |
  | `currency` (`in`) | "paid in EUR" | yes | no |
  | `payment_method` (`in`) | "paid with Stripe" | yes | no |
  | `order_count` (`gte` n, `gt` n) | "after n orders", n+1 for `gt` | yes | yes |
  | `lifetime_points` (`gte`, `gt`) | "after earning 500 Points" | yes | yes |
  | `lifetime_spend` (`gte`, `gt`) | "after spending $100", only in the shopper's currency | yes | yes |

  Names stop at 3, then "and more". Anything else returns null: `customer_ids`, `user_role`, `not_in`, `lt`, `lte`, `eq`, `between`, a missing term or a product that isn't published, an unknown gateway.
- `includes/Pro/Storefront/ConditionalOfferRows.php`: on `yayloyalty_launcher_earn_rows`, for `CampaignTrigger::PURCHASE` and `REVIEW`, using the viewer's Context. Appends `trigger`, `label`, `value` (`LauncherCopy::earn_rate()` with the campaign's minimum and rounding, or `points_gain()`) and `description` ("Bonus offer."). Skips an offer at or below `$earn[ $trigger ]`, compared after `LauncherCopy::converted_rate()`. Memoised per viewer for the request. Registered in `YayLoyaltyPro::boot()`.

*Phase 4: nudge*
- `includes/Engine/Storefront/CartEarnNudge.php` (Lite): `text( \WC_Cart $cart ): string`, applying `yayloyalty_cart_earn_nudge` to '' behind `DesignHelper::surface_enabled()`. Prints on `woocommerce_before_cart_totals` and `woocommerce_review_order_before_payment`.
- `StoreApiAdapter::cart_data()` and `cart_schema()`: a `nudge` string. `assets/storefront/redeem-blocks.js` prints it as text in the same fill, above the card.
- `includes/Pro/Storefront/CartOfferNudge.php`: on that filter. Takes `advertised_offers()` from `from_cart()`'s Context, picks the first offer that has a basket condition with a verdict of `false` and whose other conditions are not `false`.

**Data and storage:** One design key, `visibility.show_bonus_offers`, default `true`, in `DesignSchema::get_default_design()`, `schema()` and `design.schema.ts`. No table or option. Nothing to migrate: `DesignHelper::get_design()` merges defaults, and Lite keeps the stored key.

**Integration points:**
- New filter `yayloyalty_cart_earn_nudge` ( string $text, \WC_Cart $cart, int $user_id ). Internal Pro seam.
- Seam change: the evaluator behind `PointsEngine::CONDITIONS_EVALUATOR_FILTER` also exposes `verdict()`.
- `yayloyalty_campaign_matches` fires only for a verdict of `true` on an estimate or advert. Its docblock says so. Nothing subscribes today.
- Consumed: `yayloyalty_launcher_earn_rows`, `woocommerce_before_cart_totals`, `woocommerce_review_order_before_payment`.
- Shared code changed: `Context`, `ContextFactory`, `PointsEngine`, `LauncherPayload`, `StoreApiAdapter`, `redeem-blocks.js`. All additive for existing callers.

**UI:** A "Show bonus offers" `VisibilitySwitchField` under Design → Visibility, after `show_thankyou_message`, behind `IS_PRO_BUILD`. Storefront rows use the existing rule list item; the nudge uses `assets/storefront/redeem.css`.

**Edge cases and failure modes:**
- Downgrade to Lite: no evaluator, every campaign for everyone, stored conditions untouched.
- Product figures describe this one purchase: a cart that also holds Shoes pays A, as today's figures ignore the rest of the cart.
- A guest who signs in may qualify for more: the guest figure stays what a guest order pays.
- A daily limit of 0: `with_daily_limit()` already clears all rows, offers included.
- A failing evaluator or copy: the row is dropped and logged; the page renders.
- Page cache: guest output is the same for every guest. Member output already varies by user.
- Conversion: offer values convert as `advertise()` does; `lifetime_spend` never converts, matching the evaluator.

**Decision record:** `docs/decisions/2026-11-05-advertise-conditions-with-a-three-state-verdict.md`, added by the phase 2 PR. It records that adverts may now evaluate conditions for a known shopper, which reverses the 2026-10-06 spec's "evaluator is never called when advertising", and that an unknown condition lowers a promise.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Treat unknown as matching on the product page | Overpromises whenever the condition fails at order time. |
| Treat unknown as not matching (today) | Keeps the overpromise bug: the campaign below is promised. |
| Merchant-written offer text with a per-campaign switch | User chose generated text: no merchant setup. |
| Add a `Context` argument to `evaluate()` callers, or a separate public `estimate()` | Every preview caller would change; the flag keeps `EarningService::preview()` as it is. |
| Pro hooks the cart directly | Hard rule 2: Pro attaches through filters that Lite applies. |
| Nudge inside the redeem card | The card is empty for guests and customers with no reward to show. |
| Advertise `user_role` conditions | Exposes wholesale and internal programs. |

## Rollout

Each phase is one PR into `dev/edd`, in order. Phase 4 rebases on `paul/feat/guest-order-points` once that merges, as it touches the same cart area. Campaigns with conditions become public on update, as `show_bonus_offers` defaults on: the release note says so and points to Design → Visibility. No stored data migrates.

## Open questions

None.
