# Conditional Offers Design

**Status:** accepted 2026-11-11
**Related:** `docs/specs/2026-10-10-product-page-earning-message-design.md`, `docs/specs/2026-10-06-shared-campaign-choice-for-launcher-design.md`

## Goal

On Pro, earning campaigns and rewards that carry conditions are advertised on the storefront. A shopper who meets the conditions sees the value they actually get. Anyone else sees a short teaser for the bonus ("2 pts per $1 for VIP members"). Lite does not change.

## Context

- **Ways to earn** (launcher, My Points tab, placements) is built by `LauncherEarnRules::advertise()` (`includes/Engine/Storefront/LauncherEarnRules.php`) from `PointsEngine::advertised()` (`includes/Engine/Points/PointsEngine.php`). That method walks `candidates()` (live campaigns, highest priority first) down to the first campaign for everyone and returns the *lowest* value seen. A conditional campaign can only lower the promise, never show up. With no campaign for everyone, the row is null. It runs on `Context::advert()`, so members see the guest row.
- **Product line** (`includes/Engine/Storefront/ProductEarnMessage.php`): a member's figure comes from `EarningService::preview()`, a guest's from `PointsEngine::evaluate()`, both on `ContextFactory::from_product()`. That Context has no `order_id` and no facts loader. `ConditionsEvaluator::missing_input()` (`includes/Pro/Services/ConditionsEvaluator.php`) answers `needs_order` for every `INPUT_ORDER` type (currency, payment_method, country, product_in_cart, category_in_cart) and `no_customer` for every `INPUT_FACTS` type (lifetime_points, order_count, lifetime_spend). Only `user_role` and `customer_ids` can match there.
- **Cart and checkout**: there is no earn preview. `Points\ContextFactory::from_cart()` exists and has no callers. It has no facts loader and no payment or country extras. `ContextFactory::from_order()` always sets `EXTRA_PAYMENT_METHOD`, `EXTRA_BILLING_COUNTRY` and `EXTRA_SHIPPING_COUNTRY`, and an `order_id` when the order is saved.
- **Ways to redeem**: `RewardsPanel::redeem_offers()` (`includes/Pro/Storefront/RewardsPanel.php`) appends every live `fixed_coupon` / `percent_coupon` reward, whatever its conditions. The redeem card's `rows()` shows options whose quote is denied as disabled rows (`is_denied`). Reward conditions are checked through the `yayloyalty_reward_conditions_match` filter inside `RewardGate::check()` (`includes/Engine/Redemption/RewardGate.php`), after the live and balance checks. `Result::REASON_CONDITIONS_NOT_MET` is that failure.
- The evaluator seam is `PointsEngine::CONDITIONS_EVALUATOR_FILTER` (`yayloyalty_campaign_conditions_evaluator`), null in Lite. `ConditionsEvaluator::explain()` returns per-condition `{type, operator, value, matched, reason}`, and `evaluate()` folds over it.
- Config JSON: `Campaign::config_schema()` and `Reward::config_schema()` are keyed by trigger or type, with specs checked by `Utils\ConfigSpec` (`enum`, `string` and others). Pro adds its own through `YayLoyaltyPro::add_campaign_config_schema()` / `add_reward_config_schema()`.
- Block cart pattern: `Engine\Redemption\Adapters\StoreApiAdapter` registers Store API cart data, and `assets/storefront/redeem-blocks.js` (plain JS, no build) fills a checkout slot from it. The classic pattern is `ClassicCheckoutAdapter` on `woocommerce_cart_totals_before_order_total` / `woocommerce_review_order_before_order_total`.
- The admin campaign preview (`CampaignPreviewRestController`) explains on `ContextFactory::from_user()`, which has no lines and no order.

## Scope

**In:**
- `PointsEngine::offers()` and `PointsEngine::quote_campaign()`.
- `ConditionsEvaluator::missing_input()` evaluating order-type conditions on a Context without an order when their input is present.
- A facts loader on `from_product()` and `from_cart()`, plus payment and country extras on `from_cart()`.
- Auto teaser phrases from conditions (Pro), and the per-campaign and per-reward override keys `advert_mode` / `advert_text` with a "Storefront teaser" field in the Pro editors.
- Four storefront surfaces: Ways to earn, the product line, a new cart and checkout earn preview (classic and block), and Ways to redeem plus the redeem card.
- Three design keys.
- Tests: two unit tests and one e2e spec (see Acceptance criteria 11).

**Out:**
- Tier conditions (tiers are not built).
- Auto phrases for `not_in`, `lte`, `lt`, `eq`, `between` and `customer_ids`. Auto mode gives no teaser for those, and a custom teaser still works.
- Pro triggers other than purchase, signup and review (birthday and the milestones keep their own rows).
- Product cards on shop, archive, related and collection listings.
- Product line changing with quantity (it stays at quantity 1).
- The Pro daily earning cap in any of these figures (existing backlog item).
- Emails.
- Teasers in Lite.

## Acceptance criteria

Setup for criteria 1–6: a Pro site on the base currency USD; campaign "Base" (purchase, 1 point per $1, no conditions, priority 0); campaign "VIP" (purchase, 2 per $1, `user_role in [vip]`, priority 10); campaign "Shoes" (purchase, 3 per $1, `category_in_cart in [Shoes]`, priority 20); reward "VIP $10" (`fixed_coupon` $10, 500 points, `user_role in [vip]`). `advert_mode` is unset on all of them (auto). The points label is "points".

1. As a guest, Ways to earn in the launcher and the `[yayloyalty_ways_to_earn]` placement shows "Make a purchase" at "1 point / $1". Under it are two bonus lines, in this order: "3 points / $1 when your order includes Shoes" and "2 points / $1 for VIP members". The HTML is byte-identical for two different guests.
2. As a member with role `vip`, the row reads "2 points / $1", followed only by the line "3 points / $1 when your order includes Shoes". As a member without `vip`, it reads "1 point / $1" with both bonus lines.
3. A simple product priced $100 in category Shoes, viewed as `vip`, shows "Earn 300 points with this purchase" and no bonus line. A $100 product outside Shoes, viewed as `vip`, shows "Earn 200 points with this purchase" and no bonus line. The same non-shoe product, viewed as a guest, shows "Earn 100 points with this purchase", the bonus line "Earn 200 points for VIP members", and the existing sign-in line.
4. A cart holding the $100 Shoes product, viewed by a member without `vip`, shows the row "You'll earn 300 points with this order" on the classic cart, the classic checkout, the Cart block and the Checkout block. With a $100 non-shoe product instead, it shows "You'll earn 100 points with this order" and the bonus line "Earn 300 points when your order includes Shoes".
5. As a member without `vip`, Ways to redeem lists "VIP $10" as a locked row (`.yayloyalty-rule--locked`) with the line "for VIP members". The cart's redeem card does not list it. As `vip`, it is a normal row in both places.
6. Setting "VIP" to `advert_mode = off` removes its bonus line from criteria 1–4. Setting it to `custom` with `advert_text` "for our insiders" makes the line read "2 points / $1 for our insiders". The same applies to the reward in criterion 5. A reward with conditions, `advert_mode = off` and a viewer who fails them is absent from Ways to redeem.
7. A campaign with conditions placed *below* "Base" in priority never shows a bonus line. A conditional campaign above "Base" with a value of at most the row value shown to the viewer never shows a bonus line. A trigger never shows more than 3 bonus lines.
8. `visibility.show_bonus_offers = false` removes every bonus line and locked reward row; the personalised member values stay. `visibility.show_cart_earn_preview = false` removes the cart and checkout row on classic and block.
9. Lite build (`./scripts/run.sh release lite --no-prompt`): the validator passes, and a Lite site shows no bonus lines and no locked rows. Rows keep their current values.
10. Award parity: for any Context with an `order_id`, `ConditionsEvaluator::explain()` returns the same result as before this change, for every condition type.
11. Tests pass. `tests/unit/Pro/ConditionsEvaluatorDraftContextTest.php` (`composer test`) covers criterion 10, plus draft cases: lines present → product and category evaluated; currency always evaluated; empty payment and country extras → `needs_order`; no lines → `needs_order`. `tests/unit/Engine/Points/PointsEngineOffersTest.php` (`composer test`) covers bonus selection (above base only, value > shown value), the `matched` / `locked` / `at_checkout` statuses, the cap of 3, and `bonuses = []` with no evaluator. `apps/e2e/specs/conditional-offers.spec.ts` (`pnpm --filter @yayloyalty/e2e test`) covers criteria 1–6.
12. End-to-end verification: on wp-env, `pnpm --filter @yayloyalty/e2e test specs/conditional-offers.spec.ts` passes. By hand on the Local site (Twenty Twenty-Five), walk criteria 1–6 as guest, non-VIP and VIP, on the block cart and on a classic `[woocommerce_cart]` page.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union types, `mixed` / `static` / `never` types, or a `catch` without a variable (CLAUDE.md hard rule 4, `.claude/rules/php-standards.md`).
- Lite names Pro only in `includes/YayLoyalty.php` (hard rule 2). Phrases, condition checks for rewards and the editor field live in `includes/Pro/` and `apps/admin/src/pro/`, the field behind `IS_PRO_BUILD` (hard rule 3). Lite engine code reaches Pro only through filters.
- No ledger or balance writes (hard rule 5). Everything here is read-only.
- Guest output on every surface depends only on the time, the currency and the product or cart, never on the person viewing it, so full-page caches stay safe.
- New design keys go in `DesignSchema::get_default_design()` and `schema()` and in `apps/admin/src/lib/schema/design.schema.ts`. New config keys go in the Lite `Campaign::config_schema()` / `Reward::config_schema()` for every Lite trigger and type, and in Pro's `add_*_config_schema()` for Pro ones, so a downgraded site keeps them. They are mirrored in the zod schemas under `apps/admin/src/lib/schema/`.
- New hooks attach in a `register()` called once from a composition root. Logging only through `Log::warning()`, with the class as context. Each storefront surface catches `Throwable` and drops only its own bonus lines.
- Each storefront page view evaluates conditions at most once per campaign per viewer, and reads facts at most once (the existing lazy loader).
- Strings use the `yayloyalty` text domain, and every placeholder has a translators comment.
- `yayloyalty_offer_condition_phrase` is an internal Pro seam, not public API.

## Approach

**Chosen:** A, an engine offer list.

**Architecture:** The engine owns which conditional campaigns are bonuses and what state each is in for a viewer, so all four surfaces share award-time semantics. `PointsEngine::offers()` returns the base the storefront may promise plus the bonuses above it, each with a status from `explain()`. Pro supplies the auto phrases through a filter, and a shared Lite helper resolves the teaser (auto, custom or off). Surfaces render values and bonus lines from that one shape. The evaluator learns to evaluate order-type conditions on draft Contexts (product, cart) when the input is there, and keeps today's path for Contexts with an `order_id`.

**Components:**
- `includes/Engine/Points/PointsEngine.php`:
  - `offers( string $trigger, Context $advert, ?Context $viewer ): array{base: ?array{campaign: Campaign, value: float}, bonuses: list<array{campaign: Campaign, value: float, status: string}>}`. `base` is `advertised( $trigger, $advert )`. Bonuses are the conditional candidates *before* the first campaign for everyone, in priority order. Status: `matched` when every `explain()` result matched; `locked` when any failed with a reason other than `needs_order`; `at_checkout` otherwise. A null `$viewer` is a guest, explained on `$advert` (customer inputs → `no_customer` → `locked`). No evaluator, or an evaluator without `explain()` → `bonuses = []`.
  - `quote_campaign( Campaign $campaign, Context $ctx ): int`: the handler's `is_eligible()`, `compute_points()` and caps for one campaign on an event Context; 0 when not eligible.
  - Status constants `OFFER_MATCHED = 'matched'`, `OFFER_LOCKED = 'locked'`, `OFFER_AT_CHECKOUT = 'at_checkout'`.
- `includes/Engine/Storefront/OfferLines.php` (new, Lite): resolves `offers()` for a viewer into display data. The row value is the first `matched` bonus's value, else the base value. Bonus lines are the `locked` and `at_checkout` bonuses with value > row value, the first 3, each with its teaser. Teaser resolution: `advert_mode` `off` → none; `custom` → `advert_text` (trimmed, empty → none); `auto` or missing → `apply_filters( 'yayloyalty_offer_condition_phrase', null, $conditions )`, null → none. Holds a per-request memo keyed by trigger and viewer.
- `includes/Pro/Storefront/ConditionPhrases.php` (new, Pro): attaches `yayloyalty_offer_condition_phrase`. One clause per condition, joined with " and ". If any condition has no clause, it returns null.
  - `user_role in` → "for {role display names} members"
  - `lifetime_points gte N` → "once you've earned {N} {points label}"
  - `order_count gte N` → "after {N} orders"
  - `lifetime_spend gte {amount, currency}` → "once you've spent {wc_price( amount, currency )}"
  - `currency in` → "when you pay in {codes}"
  - `payment_method in` → "when you pay with {gateway titles}"
  - `country in` with source shipping / billing / any → "for orders shipped to / billed to / from {country names}"
  - `product_in_cart in` / `category_in_cart in` → "when your order includes {first 3 names}[ and {N} more]"

  Lists are joined with `wp_sprintf_l( '%l', … )`. Every other type or operator gives no clause.
- `includes/Pro/Services/ConditionsEvaluator.php`: `missing_input()` keeps today's answer when `$ctx->get_order_id()` is not null. Without an order, `INPUT_ORDER` types are evaluable as follows: product_in_cart and category_in_cart when `get_line_items()` is non-empty; currency always; payment_method when `EXTRA_PAYMENT_METHOD` is non-empty; country when the extra its `source` reads is non-empty (for `any`, either one). Otherwise `needs_order`.
- `includes/Engine/Points/ContextFactory.php`: `from_product()` and `from_cart()` pass the facts loader `static fn() => self::customer_facts( $user_id, $now )` for a user id > 0. `from_cart()` adds `EXTRA_PAYMENT_METHOD` from `WC()->session->get( 'chosen_payment_method' )` and the billing and shipping countries from `WC()->customer`, as '' when absent.
- `includes/Engine/Storefront/LauncherEarnRules.php` / `LauncherPayload.php`: `advertise()` takes the viewer id. A member's `earn[ trigger ]` is `OfferLines`' row value. The payload adds `earn_bonuses: array<trigger, list<{text: string}>>`, where text is `{value via LauncherCopy::earn_rate / points_gain} {teaser}`. `member_rows()` and `guest_rows()` put a row's bonus lines on the row as `bonuses` (documented on `yayloyalty_launcher_earn_rows`), and `rule-list-item.php` prints them as `.yayloyalty-rule__bonus` lines.
- `includes/Engine/Storefront/ProductEarnMessage.php`: adds bonus lines `.yayloyalty-product-earn__bonus`, "Earn {points} {points_label} {teaser}". `{points}` is `quote_campaign()` on the product Context, and a bonus quoting 0 is dropped. Variations carry `yayloyalty_earn_bonuses` (HTML) beside `yayloyalty_earn`, and `assets/storefront/product-earn.js` swaps it the same way.
- `includes/Engine/Storefront/CartEarnPreview.php` (new, Lite): `register()` hooks classic `woocommerce_cart_totals_after_order_total` and `woocommerce_review_order_after_order_total` (a `<tr class="yayloyalty-cart-earn">`). It registers Store API cart data under namespace `yayloyalty-earn-preview` with one field, `html` (the same markup), and enqueues `assets/storefront/cart-earn-blocks.js` on the Cart and Checkout blocks. That plain script, with no build step, fills `ExperimentalOrderMeta` from the field, as `redeem-blocks.js` does. Member figure: `EarningService::build()->preview( PURCHASE, from_cart( $cart, $user_id ) )`. Guest figure: `PointsEngine::evaluate()` on `from_cart( $cart, null )`, plus the sign-in line from `ProductEarnMessage`. Bonus lines: the `at_checkout` and `locked` bonuses with `quote_campaign()` > the figure. Nothing is rendered when the figure is 0 and there are no bonus lines.
- `includes/Pro/Storefront/RewardsPanel.php`: `redeem_offers()` gains the viewer's user id. For each reward with conditions, it checks `yayloyalty_reward_conditions_match` on a rewards Context for the viewer, with balance and liveness ignored (Pro's existing evaluator over facts). A failed reward becomes `{name, cost, text, locked: true, teaser}`, and is dropped when there is no teaser or `show_bonus_offers` is false. For a guest, every reward with conditions counts as failed. `rows()` drops options whose quote reason is `REASON_CONDITIONS_NOT_MET`. `ways-to-redeem.php` prints a locked offer with `.yayloyalty-rule--locked` and the teaser line.
- Pro SPA: a "Storefront teaser" field (radio Auto / Custom / Off, a text input ≤ 120 characters when Custom, and a live preview of the auto phrase) in the campaign editor's conditions section (`apps/admin/src/pro/campaigns/`) and the reward editor's (`apps/admin/src/pro/rewards/`). It is shown only when the item has at least one condition. The auto preview comes from the existing campaign preview route's response, extended with `phrase`.
- `includes/Pro/Controllers/CampaignPreviewRestController.php`: the response adds `phrase: string|null` from `yayloyalty_offer_condition_phrase`.

**Data and storage:** No new tables, columns or options. In config JSON, `advert_mode` is `{ type: 'enum', values: [ 'auto', 'custom', 'off' ] }` and `advert_text` is `{ type: 'string' }`, ≤ 120 characters after `sanitize_text_field()`. They are listed under every campaign trigger and reward type. A missing key means `auto`. Design keys: `visibility.show_bonus_offers` (bool, default `true`), `visibility.show_cart_earn_preview` (bool, default `true`), and `storefront_text.cart_earn_line` (string, required, default "You'll earn {points} {points_label} with this order"). Nothing to migrate and nothing to uninstall beyond what already goes.

**Integration points:**
- Filter added (internal Pro seam): `yayloyalty_offer_condition_phrase` (`?string $phrase, array $conditions`). Ways to redeem needs no new filter: `RewardsPanel` is Pro and applies the existing `yayloyalty_reward_conditions_match` itself.
- The evaluator seam now also expects `explain( array $conditions, Context $ctx ): array`.
- Filter changed: `yayloyalty_launcher_earn_rows` rows may carry `bonuses: list<{text: string}>`.
- Store API: cart extension namespace `yayloyalty-earn-preview`.
- WooCommerce hooks consumed: `woocommerce_cart_totals_after_order_total`, `woocommerce_review_order_after_order_total`, `woocommerce_blocks_enqueue_cart_block_scripts_after`, `woocommerce_blocks_enqueue_checkout_block_scripts_after`.
- Shared code changed: `PointsEngine`, `ContextFactory`, `ConditionsEvaluator`, `LauncherEarnRules`, `LauncherPayload`, `RewardsPanel`. Award paths (`EarningService::award()`) go through Contexts with an `order_id` or with no order-type conditions in play, so their results don't change (criterion 10).

**UI:** Storefront: sub-lines under the Ways to earn rows, under the product line, and under the cart and checkout row; locked rows in Ways to redeem. They are styled in `assets/storefront/` with the existing tokens. Admin: the Pro "Storefront teaser" field, and two switches plus one text field in Design → Visibility / Storefront text (`DesignVisibilitySection.tsx`, `DesignStorefrontTextSection.tsx`).

**Edge cases and failure modes:**
- No campaign for everyone: `base` is null and the row is hidden as today. A member with a `matched` bonus still gets a row at that value, and guests see no row but no bonus lines either, since there is no base to anchor them.
- A member who matches several bonuses: the first in priority order is the value, which is what `evaluate()` pays.
- A bonus with `min_subtotal` above the product price: `quote_campaign()` is 0 on the product page, so no line there. Ways to earn still shows it.
- A role or gateway since deleted: its name falls back to the stored slug.
- A bonus that ends later today: liveness comes from the advert Context's time, as today.
- An evaluator throw: `OfferLines` catches it, logs `Log::warning( 'OfferLines', … )` and returns no bonuses for that trigger.
- A guest page cached by a full-page cache: the guest output depends on no viewer data, so it is correct.
- A variable product: bonus lines come per variation, and a variation with none clears the bonus area.

**Decision record:** None. This extends the engine's advertising contract within the existing Pro seam pattern.

## Alternatives considered

| Option | Why rejected |
|---|---|
| B: per-surface logic | Copies the priority and match rules into four places and drifts from what `evaluate()` pays. |
| C: a static promo list written by the merchant | No personalised value and no auto copy, against what the user chose. |
| Auto phrases only, with no override | Can't hide internal conditions or fix awkward copy. |
| Merchant-written text only | Every campaign needs setup before it shows anything. The user chose auto with an override. |
| New columns `advert_mode` / `advert_text` | A schema upgrade for two display-only fields; config JSON already carries per-item settings. |
| Hide rewards that don't qualify entirely | The user chose locked rows with a teaser. Hiding stays the fallback when there is no teaser. |
| Splitting the cart earn preview into its own spec | The user chose to include it. |

## Rollout

Defaults are on: `show_bonus_offers` and `show_cart_earn_preview` are true, and `advert_mode` missing means auto. A Pro site with conditional campaigns starts showing bonus lines and the cart row after the update. Release note: "Conditional campaigns and rewards are now advertised on the storefront, with a per-campaign teaser override. A new 'points you'll earn' row appears on cart and checkout." Lite: the cart row appears too (`show_cart_earn_preview`), with no bonus lines.

## Open questions

None.
