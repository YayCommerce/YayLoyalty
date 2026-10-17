# Redemption Design

**Status:** accepted 2026-10-16
**Replaces:** the redemptions design of 2026-10-07 and the reward redemption design of 2026-10-14, which were never committed. This document describes the component as built on `jason/feat/redemptions`, with every decision those specs and their plans made.
**Decision records:** [Lite owns every redemption write](../decisions/2026-10-07-lite-owns-redemption-writes.md), [loyalty discounts are virtual coupons](../decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md), [the ledger's single writer](../decisions/2026-10-01-ledger-service-sole-writer.md)
**Plan:** [docs/plans/2026-10-16-redemption.md](../plans/2026-10-16-redemption.md)

## Goal

A signed-in customer spends points at the cart and checkout, on classic pages and on the Cart and Checkout blocks, in one of two exclusive ways:
- **Points discount (Lite and Pro):** a slider that turns any number of points into a cart discount at the store's rate.
- **Rewards (Pro):** fixed-amount and percentage rewards, ticked and applied together.

The points are debited once, when the order is placed. Cancelling or refunding the order returns them by fixed rules, and undoing that takes them again.

## Scope

**In:**
- The slider, backed by the seeded `flexible_discount` reward `cart-discount`.
- Pro reward types `fixed_coupon` and `percent_coupon`, several per order, each at most once per order.
- One card for both, on classic cart and checkout and on the Cart and Checkout blocks, plus a shortcode and an action.
- Commit at order placement and reversal on cancel, trash, refund, deleted refund, the `refunded` status and un-cancel.
- Real rows on the customer drawer's `GET /yayloyalty/v1/customers/{id}/redemptions` and its Redemptions tab.
- The earning side treating loyalty coupons as the shopper's own points, not as merchant coupons.
- The launcher's "Ways to redeem" line for the points discount.

**Out:**
- `free_shipping` and `free_product` rewards: never shown in the card and never spent. Their config, editor and handlers are unchanged.
- Catalog claims: no `shop_coupon` post is created, and the `issued` / `used` statuses, `coupon_id` and `expires_at` stay unwritten for a later reward type.
- `RewardClaimedEmail` and `CouponIssuedEmail` stay untriggered.
- `coupon_expiry_days` and `config.currency` of Pro rewards, and `show_on` and `max_total` of the points discount, are stored and not read.
- A refund-policy setting on Pro rewards: they always use `proportional`.
- Exclusivity with the merchant's own coupons: they stack with both the slider and rewards.
- The admin Redemptions log screen, currency conversion, schedulers and guest redemption.

## Components

Lite owns the session, the coupons, the commit and the reversal. Pro supplies two handlers and the Rewards tab, through filters only.

| File | Role |
|---|---|
| `includes/Engine/Redemption/RedemptionService.php` | Composition root (`build()`) and the only API the adapters call: `quote()`, `ceiling()`, `quote_rewards()`, `reward_options()`, `checkout_error()`, `commit()`, `commit_error()`, `message_for()` |
| `includes/Engine/Redemption/Quoter.php` | The gates, then the pure `RewardEngine` |
| `includes/Engine/Redemption/ProgramGate.php`, `RewardGate.php` | Program-level and reward-level refusals; Pro appends gates through `yayloyalty_redemption_gates` |
| `includes/Engine/Rewards/Clamp.php` | The slider's limits and the points ↔ money rule |
| `includes/Engine/Rewards/ContextFactory.php` | The quote Context from a cart or from an order line |
| `includes/Engine/Redemption/SessionIntent.php` | What the shopper asked for, in the WooCommerce session |
| `includes/Engine/Redemption/CouponProvider.php` | Keeps the cart's loyalty coupon lines in step with the intent |
| `includes/Engine/Redemption/RewardCoupons.php` | A reward's virtual coupon: probe, cap, coupon data |
| `includes/Engine/Redemption/CommitWriter.php` | The only inserter of redemption rows; one transaction per order |
| `includes/Engine/Redemption/Reversal.php` | Settles a row on cancel, refund and their undo |
| `includes/Engine/Redemption/RedemptionDraft.php` | The ledger entry and key for a row |
| `includes/Engine/Redemption/Adapters/ClassicCheckoutAdapter.php`, `StoreApiAdapter.php` | Classic and Store API wiring: render, validate, commit |
| `includes/Engine/Storefront/RedeemCard.php`, `templates/redeem-card.php` | The card |
| `assets/storefront/redeem.js`, `redeem-blocks.js`, `redeem.css` | Card behaviour, the blocks slot fill, the card's own styles |
| `includes/Pro/Services/DiscountRewardHandler.php` | Handler for `fixed_coupon` and `percent_coupon` |
| `includes/Pro/Storefront/RewardsPanel.php`, `templates/rewards-panel.php` | The Rewards tab |
| `includes/Engine/Earning/Listeners/OrderListener.php`, `OrderReversalListener.php` | Route order events to the reversal, beside the earn clawback |
| `includes/Services/LedgerService.php` | `redeem()` and `refund_redeem()`, the only ledger writes |

`LedgerService` keeps four construction sites: `Engine\RestAPI::controllers()`, `EarningService::build()`, `Earning\Reversal::build()`, `RedemptionService::build()`. The last one shares its instance between `CommitWriter` and `Reversal`.

## Data and storage

**Table `yayloyalty_redemptions`** (`includes/Database/Schema/RedemptionsTable.php`): `id`, `user_id`, `reward_id`, `order_id`, `points` (positive), `value` and `base_value` (`decimal(19,4)`, equal: no conversion), `currency`, `rate_snapshot`, `coupon_code`, `coupon_id`, `status`, `restored_points`, `expires_at`, `created_at`, `updated_at`. Indexes: primary key, `UNIQUE (coupon_id)`, keys on `user_id`, `order_id`, `reward_id`, `(status, created_at)` and `(reward_id, user_id)`.

**Statuses:** `applied`, `cancelled`, `refunded` are written today. `issued` and `used` are reserved. `expired` is derived at read time (`issued` past `expires_at`). `restored_points` never exceeds `points`, and the status is `refunded` only when they are equal.

**Rows per order:**
- Slider: one row, `coupon_code` `yayloyalty-redeem`, `points` the line's cost, `value` the line's discount, `rate_snapshot` the rate.
- Rewards: one row per reward line, `coupon_code` `yayloyalty-reward-{id}`, `points` the full `cost_points`, `value` the line's actual discount, `rate_snapshot` 0, `coupon_id` NULL.

**Ledger** (`LedgerSource::ORDER`), each row carrying `redemption_id`:
- `redeem` (negative) at commit: `redeem:{user}:order:{order_id}:{qualifier}`.
- `refund_redeem` (positive) on a restore: `refund_redeem:{user}:{reference_type}:{reference_id}:{qualifier}`, the reference being the refund, the order, or the redemption for a second cancel.
- `redeem` (negative) on a re-debit: keyed on the deleted refund, or on the redemption for an un-cancel.
- `{qualifier}` is `flexible_discount` for the slider and `reward_{reward_id}` for a reward (`RedemptionDraft::qualifier_for()`), so two rows of one order refunded by one refund never share a key.
- No `adjust` row is ever written: `LedgerRepository::sum_refunded_for()` reads an `adjust` row that references a refund as an earn give-back.

**Order meta** (`CommitWriter::META_POINTS`, `META_VALUE`): `_yayloyalty_points_redeemed` and `_yayloyalty_redeem_value`, the order's loyalty total (the slider alone or the sum of the rewards). Projections only; no balance is computed from them.

**Session** (`SessionIntent`): `yayloyalty_redeem_points` (int, the request, unclamped) or `yayloyalty_redeem_rewards` (int[], unique, in the order picked), never both. `set()` clears the rewards, `set_rewards()` clears the points, `remove_reward()` drops one id, `clear()` drops both.

No schema change and no version bump: the plugin is unreleased at 1.0.0. A development site whose tables predate a column gets it by deactivating and reactivating the plugin, which re-runs `Installer::install()`.

## Quote path

`quote()` and the other read methods write nothing and may run any number of times per request.

**Gates, in order** (`Quoter`):
1. `ProgramGate` (`ProgramGate::REASONS`): `no_customer`, `program_paused`, `opted_out`, `excluded_role`, `coupons_disabled`. These refuse the shopper, not a reward.
2. `RewardGate`: `not_live` (status, `starts_at`, `ends_at`), `insufficient_balance` for a reward whose cost exceeds the balance, and `conditions_not_met` through the filter `yayloyalty_reward_conditions_match`.
3. Any gate Pro appends through `yayloyalty_redemption_gates`.

**The points discount** (`FlexibleDiscountHandler`, `Clamp::apply()`), config `points_per_currency_unit`, `min_points`, `max_points_per_order`, `max_percent_of_subtotal`, `increment`, `refund_policy`:
- The requested points are clamped, in this order, by the balance, `max_points_per_order`, the cart (after every merchant coupon, and capped by `max_percent_of_subtotal`), and the step. Below `min_points` nothing applies. `bound_by` names the limit that bit (`balance`, `reward_max`, `cart`, `step`, `reward_min`). The only refusal is `no_rate`, for a reward without a usable `points_per_currency_unit`; a missing `cart-discount` reward answers `no_reward`.
- Only points that buy whole smallest currency units apply: at 1,000 points per unit, 5 points apply as 0 and 15 as 10. `Clamp::points_for()` turns an order's coupon amount back into points by the same rule, so the cart and the commit cannot disagree.
- `ceiling()` quotes a request for everything: the most this shopper can spend on this cart, used for the slider's right end and to decide whether the slider shows at all.
- `ContextFactory::from_cart()` computes the other coupons' discount itself with `WC_Discounts`, because the cart's stored totals are stale while WooCommerce loads coupons. Customer facts are passed as null in Lite.

**Rewards** (`DiscountRewardHandler`, `RewardCoupons`):
- `fixed_coupon` (`amount`, `min_order`, `exclude_sale_items`) is spent as a `fixed_cart` coupon. `percent_coupon` (`percent`, `max_discount`, `min_order`, `exclude_sale_items`) is spent as a `percent` coupon.
- `RewardCoupons::probe()` dry-runs the coupon alone with `WC_Discounts`, after every applied coupon that is not a loyalty code. It answers `min_order` (the displayed subtotal is below `min_order`), `sale_items` (worth 0 because every item is on sale and excluded) or `no_value` (worth 0 for any other reason), otherwise the value.
- `RewardCoupons::capped()`: when the probe's value exceeds `max_discount`, the coupon becomes `fixed_cart` at `max_discount`, and the quote's `bound_by` is `reward_max`.
- A reward giving less than its face value is allowed and costs its full `cost_points`.
- `quote_rewards()` quotes the session's ids in order against the balance left once the earlier allowed ones are paid for, so a selection never costs more than the balance. A missing reward, or one whose type has no handler (Lite after a downgrade), is denied `not_live` without reaching the Quoter.
- `reward_options()` lists every live `fixed_coupon` / `percent_coupon` reward with a registered handler, in `sort_order`, each quoted alone against the full balance.

## Cart sync

`CouponProvider` makes WooCommerce do the discount math natively (percent and fixed, minimum spend, sale exclusion, the tax split, one totals row per line):
- `woocommerce_get_shop_coupon_data`: `yayloyalty-redeem` is `fixed_cart` at the quote's value (0 when denied). A reward code gets `RewardCoupons::data()`, capped against the cart. A missing reward, or one with no handler, gets a `fixed_cart` coupon at 0, so WooCommerce never removes it with a notice naming the code before `sync_cart()` drops it. Every loyalty coupon has `individual_use` false, `free_shipping` false, `usage_limit` 0 and no expiry.
- `woocommerce_coupon_sort`: merchant coupons keep WooCommerce's weights. Percent rewards get `PHP_INT_MAX - 2`, fixed rewards `PHP_INT_MAX - 1`, the slider `PHP_INT_MAX`. The weight follows the reward type, not the emitted discount type, so a capped percent reward keeps its place.
- `woocommerce_before_calculate_totals` at `PHP_INT_MAX`, `sync_cart()`: the applied-coupon list becomes the merchant coupons, then either the slider code while its quote applies points, or one code per picked reward whose quote is allowed with a value (percent rewards first, then fixed, each in the order picked). Never both. It edits the list directly, so no notice shows and `woocommerce_removed_coupon` means only that the shopper removed a coupon. It never touches a merchant coupon and never triggers a recalculation.
- **Dropping a choice the card can no longer show.** A picked reward denied for `min_order`, `sale_items`, `no_value`, `not_live` or any `ProgramGate::REASONS` leaves the session with the notice "{name} was removed: this order no longer qualifies.", once per reward and request. When the slider applies nothing and `ceiling()` is refused too, the amount leaves the session with "Points discount was removed: this order no longer qualifies.". In both cases the card hides the control that could clear the choice, so without this checkout would refuse every attempt. Added 2026-10-16 (commit `8e24efa`).
- A picked reward denied `insufficient_balance` or `conditions_not_met` stays picked and unapplied.
- `woocommerce_check_cart_items` at priority 0, `sync_before_check()`: on classic pages WooCommerce checks cart coupons before totals run, so one totals pass first lets `sync_cart()` drop a reward that stopped qualifying, with its notice, instead of WooCommerce removing it with a notice naming the code.
- `woocommerce_removed_coupon`, `on_removed()`: the slider's code clears the intent, a reward's code removes that id. This covers the classic "[Remove]", the blocks' chip and WooCommerce removing an invalid coupon.
- `woocommerce_cart_totals_coupon_label`: "Points discount" (with the merchant's label) for the slider, the reward's name for a reward. The blocks relabel the same rows in `redeem-blocks.js`.

## Checkout and commit

**Validation before totals freeze** (`checkout_error()`, from `woocommerce_after_checkout_validation` and `woocommerce_store_api_cart_errors`):
- Slider: null when nothing is asked or the quote applies any points, even fewer than asked (the cart already shows the clamped discount). Otherwise the quote's reason, or its `bound_by` when it applies 0 (`reward_min` when empty).
- Rewards: `reward_unavailable` when a picked reward is missing, denied for anything but the balance, or worth nothing; else `rewards_balance` when the balance cannot cover one; else null.

**Commit** (`commit()`, from `woocommerce_checkout_order_processed` and `woocommerce_store_api_checkout_order_processed`): it re-quotes from the order's own coupon lines, never from the session, under the customer lock in one transaction.
- No loyalty line: `skipped( no_intent )`, nothing happens.
- Both a slider line and a reward line: `skipped( reward_unavailable )`.
- Slider: one row, the `redeem` row and the meta, for exactly the points the line costs. Skipped when a gate denies, the locked balance or a limit covers fewer points than the line costs, or the re-quoted value is not the line's amount.
- Rewards: per line a row and its `redeem` row, then the summed meta, or nothing. Skipped `reward_unavailable` for a missing or unoffered reward, a denied re-quote or a line worth nothing; `rewards_balance` when the summed cost exceeds the locked balance.
- A re-fired hook answers `duplicate` and writes nothing; the per-row ledger key is the guard.
- On `skipped`, every loyalty coupon line is removed from the order, so it can never stay payable with a discount and no debit, and the adapter fails the checkout with `message_for( reason )`: classic throws, the Store API answers `RouteException( 'yayloyalty_redeem_failed', …, 409 )`.
- The session intent is cleared after every outcome but `no_intent`.
- After a commit, `yayloyalty_points_redeemed( Outcome $outcome, \WC_Order $order )` fires once per order from `Transaction::after_commit()`.

## Reversals

`Reversal` settles each redemption row of the order (`coupon_id` NULL) to what should be restored now, and writes only the difference from `restored_points`, under the customer lock then the row lock, one transaction per row. A repeated event finds nothing to move, and part refunds add up without summing the ledger. `OrderReversalListener` runs the restore and the earn clawback in one transaction, restore first, and notes each event that moved points on the order.

| Event | Acts on | Redeemed points | Row after |
|---|---|---|---|
| Cancelled, or trashed (`woocommerce_trash_order`, `trashed_post`) | `applied` | Restore everything not yet restored, keyed on the order (a second cancel: on the redemption) | `cancelled` |
| Refund created (`woocommerce_order_refunded`) | `applied` | Raise to what the order's refunds justify under the policy, keyed on the refund | `refunded` when all are back, else `applied` |
| `refunded` status with no refund object | `applied` | `none` → 0; `proportional` and `full` → everything | as above |
| Refund deleted (`woocommerce_refund_deleted`) | `applied`, `refunded` | Debit down to what the remaining refunds justify; the balance may go negative | `applied` |
| Cancelled or trash → `pending`, `on-hold`, `processing` or `completed`, or restored from the trash to one of them | `cancelled` | Debit what the cancel restored beyond what the refunds still justify, all or nothing | `applied`, or unchanged with a note when the balance is short |
| `failed` | — | Nothing | — |

**Policy** (`policy_of()`, the reward's config when the event runs; a missing reward or key reads `proportional`):
- `proportional`: `points × refunded total ÷ order total`, rounded down. The order total is what the customer paid, shipping and tax included. The refund that completes the order returns the rest.
- `full`: everything on any refund. `none`: nothing.
- Pro rewards have no setting and always use `proportional`. Only the points discount's editor offers the choice.

**Cycles:** restore twice, re-debit once. A second un-cancel writes nothing and leaves the note "already debited again once".

**The earn side:** earned points are awarded once, at `completed`, and never re-awarded. Cancel and refunds claw back their share, written after the restore row. An amount-only refund claws back no earned points unless it completes the order; redeemed points still follow the amount.

## Earning

- `Points\Context::is_loyalty_code()` is true for `yayloyalty-redeem` and every `yayloyalty-reward-*` code. `get_real_coupon_codes()` excludes them, so `exclude_coupon_orders` still awards an order whose only discounts are loyalty ones.
- `Points\ContextFactory` sums every loyalty line as `loyalty_discount`. With `base` `after_discounts`, `BaseCalculator` adds the included lines' share of it back, so spending points never lowers what the order earns.

## Storefront

**Placements**, one template (`templates/redeem-card.php`):
- Classic: `woocommerce_cart_totals_before_order_total` and `woocommerce_review_order_before_order_total`, so a cart of virtual products still gets it.
- Blocks: `extensions.yayloyalty.card` carries the server's markup to a plain-script slot fill in the blocks' discounts area (`ExperimentalDiscountsMeta`). No inner block is built and its position is fixed.
- Anywhere else: shortcode `[yayloyalty_redeem]` and action `yayloyalty_redeem_card`, for themes and page builders (an Elementor Shortcode widget, for example).

**Header:** "Your rewards" plus the balance as "9,850 Points" (`SettingsHelper::points_text()`), no money amount. A guest's header has no balance.

**Who sees what:**
- A guest: "Sign in to spend your Points." linking to the account page, no tabs.
- A shopper any `ProgramGate` refuses, an empty cart, or no live offer: no card.
- **Lite:** the slider alone. **Pro:** `RewardsPanel` adds a Rewards tab through `RedeemCard::PANELS_FILTER` when at least one reward is offered, so the card shows the tabs "Rewards" and "Points discount". The open tab is the one whose discount is applied, otherwise Rewards.

**Slider states** (`RedeemCard::view()`): `form`, starting at the maximum, with "{min} minimum" and "up to {money} here" (what the right end buys for this shopper), disabled when the maximum is below the minimum; `applied`, "{points} applied — {money} off", "{points} remaining" and Remove; `exclusive` while rewards are applied; `hidden` when `ceiling()` is refused, in which case a Pro card shows the Rewards tab alone and a card with nothing else renders nothing. A clamp shows `message_for( bound_by )` for `balance`, `reward_max`, `cart` and `reward_min`; `step` stays silent.

**Rewards tab:** one row per offered reward with a checkbox, its name, a one-line description ("On orders over $25", "Up to $10 off your order", "15% off your order", "$5 off your order"), its cost, an "Applied" tag, and "{value} off here" when the value is below the face value. A refused row is disabled with its reason ("Add $5 more to use this", "Items on sale don't qualify", "Nothing in your cart qualifies", "Not enough Points", "Not available for your account"). The footer reads "N selected · X Points" while ticking, "X Points will be used · Y remaining" once applied, and one Apply / Update button is enabled only while the ticks differ from what is applied. Ticking disables any row that would take the selection past the balance. While the slider is applied the tab shows "Remove your Points discount to use rewards." and disabled checkboxes; the slider tab shows "Remove your rewards to use a Points discount." while rewards are applied.

**Wire format:**
- Classic: `wc-ajax=yayloyalty_redeem` POST with `security` and either `points` (0 removes) or `rewards` (comma-separated ids; present and empty clears). It sets the intent, runs one totals pass and answers the card's new HTML. It never clamps and never touches a coupon.
- Blocks: Store API update callback `yayloyalty-redeem` with `{ points: int }` or `{ rewards: int[] }` (a `rewards` key wins), sent by `window.yayloyaltyRedeemTransport()`.
- `extensions.yayloyalty`: `requested`, `applied`, `value`, `currency`, `bound_by`, `reason`, `rewards` (`array<{ id, code, label, points, value }>` for the applied rewards) and `card`.

**Styles and copy:** the card has its own `assets/storefront/redeem.css` (`yayloyalty-redeem__*` classes) and reads nothing from the Design tab, which styles the launcher only. Money goes through `LauncherCopy::money()` (a whole amount prints without decimals), points through `SettingsHelper::points_text()`. No sentence hard-codes "points". The inputs have no `name`, so nothing rides on the cart or checkout form.

**Launcher:** the member panel's "Ways to redeem" and the balance's worth come from the `cart-discount` reward while it is live (`LauncherPayload::redeem_rule()`, `worth()`). Pro rewards are not listed there.

**Admin:** the customer drawer's Redemptions tab and count read `GET /yayloyalty/v1/customers/{id}/redemptions`, newest first; Lite shows the upgrade surface on that tab, and both builds show the `redeem` and `refund_redeem` rows on the Ledger tab.

## Integration points

- **Internal Pro seams** (not public API): `yayloyalty_register_reward_handlers`, `yayloyalty_reward_conditions_match`, `yayloyalty_redemption_gates`, `yayloyalty_redeem_card_panels` (`( array $panels, array $context )` → `array<string, array{ label, html, is_active }>`, context `{ service, cart, user_id, balance, is_slider_applied }`).
- **Public action:** `yayloyalty_points_redeemed( Outcome $outcome, \WC_Order $order )`.
- **WooCommerce hooks:** those named under Cart sync, Checkout and commit, and Reversals, plus `woocommerce_blocks_loaded`, `woocommerce_blocks_enqueue_cart_block_scripts_after` and `woocommerce_blocks_enqueue_checkout_block_scripts_after`.

## Edge cases

| Case | Behaviour |
|---|---|
| Slider request above a limit | Clamped to a positive amount, which passes checkout; the card names the limit |
| Slider request below the minimum, or nothing affordable at submit | Checkout stops with the bound's message; nothing written |
| Balance drops between Apply and submit (rewards) | Checkout stops with `rewards_balance`; nothing written |
| Program paused, coupons disabled, opted out or excluded role after Apply | The choice is dropped on the next totals run with the "was removed" notice; checkout then passes without a discount |
| `cart-discount` reward ended after Apply | Same: the slider amount is dropped with the notice |
| Reward ended, paused or deleted after Apply | Dropped with the notice; an order that still carries its line fails at commit with `reward_unavailable` |
| Cart falls below `min_order`, or only sale items remain | Dropped with the notice; nothing debited |
| Reward would give 0 | Not tickable, never on the cart, never debited |
| `max_discount` reached | The line is `fixed_cart` at the cap; the row stores the cap |
| Crafted `{ rewards: [A, B] }` above the balance | Only what the balance covers is applied, in the order sent |
| Same reward sent twice | Stored once; one line |
| Slider and rewards sent one after the other | The later wins; no cart or order carries both kinds |
| Merchant applies an individual-use coupon | The loyalty lines are re-added on the next totals run |
| Downgrade to Lite with rewards in the session | Denied `not_live`, dropped by `sync_cart()`; past orders still reverse |
| Two rows of one order refunded by one refund | Each restores under its own `reward_{id}` key |
| Admin adds or removes a coupon, or presses Recalculate | WooCommerce rebuilds loyalty lines from the order's saved `coupon_info`, never from our filter, so their amounts hold (verified 2026-10-16 on WooCommerce 10.9) |

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Only `LedgerService` writes ledger rows and balances, from its four construction sites.
- Lite names Pro only in `includes/YayLoyalty.php`; Pro attaches through hooks added in `YayLoyaltyPro::boot()`; nothing under `includes/Engine/` imports `YayLoyalty\Pro\*`.
- Orders go through WooCommerce CRUD only (HPOS). Classic checkout and Blocks are both supported.
- Money is `DECIMAL(19,4)` in storage and a string in PHP and REST. Points are integers. Datetimes are UTC.
- No `shop_coupon` post is created for any loyalty discount.
- The storefront scripts are plain JS with no build step and no JSX.

## Acceptance criteria

Fixtures: a Pro build; a signed-in customer with 9,850 Points; `cart-discount` live at 100 Points = $1; reward A `fixed_coupon` "$5 off", 500 Points, `amount` 5, `min_order` 25; reward B `percent_coupon` "15% off", 1,500 Points, `percent` 15, `max_discount` 10; a $100 cart, no merchant coupon, prices excluding tax.

1. **Slider.** Applying 500 Points on the cart shows "Points discount −$5" and "500 Points applied — $5 off"; asking for more than the balance, the cap or the subtotal applies the clamped amount and names the limit. Placing the order writes one `applied` row, one `redeem` row of −500 keyed `…:flexible_discount`, lowers the balance and sets the order meta. Re-firing the hook writes nothing.
2. **Card.** The Pro cart card reads "Your rewards" and "9,850 Points", tabs "Rewards" (selected) and "Points discount", rows A then B with their descriptions and costs, and a disabled Apply beside "0 selected". Lite shows the header and the slider with no tabs.
3. **Rewards applied.** Ticking A and B reads "2 selected · 2,000 Points". Apply shows "15% off −$10.00" then "$5 off −$5.00", total $85.00, and "2,000 Points will be used · 7,850 remaining"; the balance is still 9,850.
4. **Rewards committed.** Classic and block checkout both write two `applied` rows (A: 500, `5.0000`; B: 1,500, `10.0000`), `redeem` rows −500 and −1,500 keyed `…:reward_{A}` and `…:reward_{B}`, balance 7,850, `_yayloyalty_points_redeemed` 2000 and `_yayloyalty_redeem_value` `15.00`.
5. **Changing the selection.** Unticking B relabels the button "Update" and leaves only "$5 off"; unticking everything removes both. The classic "[Remove]" or the blocks' chip removes one reward only.
6. **Exclusivity.** Each kind closes the other with its notice, server side too; no cart or order carries both.
7. **Balance.** At 1,800 Points ticking either reward disables the other with "Not enough Points". Dropping the balance to 400 after Apply stops checkout with "You do not have enough Points for these rewards. Remove one and try again." and writes nothing. Dropping a slider shopper to 50 stops checkout with "You do not have enough Points for this discount. Choose a lower amount and try again.".
8. **Eligibility.** On a $20 cart A reads "Add $5 more to use this"; a cart that falls to $20 after Apply drops A with "$5 off was removed: this order no longer qualifies." and debits nothing for it.
9. **Partial value.** With a $97 merchant coupon and `min_order` cleared, A reads "$3 off here" and its row stores `3.0000` and 500 Points.
10. **Paused after Apply.** With the slider or a reward applied, pausing the program drops the choice with the "was removed" notice, and the next checkout passes.
11. **Cancel and un-cancel.** Cancelling the order of AC 4 restores 500 and 1,500 and sets both rows `cancelled`; returning it to processing debits them again; a second cycle restores again and the second un-cancel leaves the "already debited again once" note.
12. **Refunds.** A $42.50 refund restores 250 and 750 under one refund id with both rows `applied`; refunding the rest restores the remaining 250 and 750 and sets both `refunded`; deleting a refund debits back what it restored.
13. **Policy.** With the points discount's policy `none`, a refund restores nothing; with `full`, a half refund restores everything.
14. **Other shoppers.** A guest sees "Sign in to spend your Points."; an opted-out shopper or an excluded role sees no card.
15. **Downgrade.** An order placed under Pro with A and B still restores 500 + 1,500 on cancel after switching to Lite.
16. **Earning.** A `purchase` campaign with `exclude_coupon_orders` awards points on an order whose only discounts are A and B.
17. **Console.** No console errors on classic or block cart and checkout.
18. **End to end:** on the block cart tick A and B, Apply, see $85.00, place the order, see −500 and −1,500 in the drawer's Ledger tab and a balance of 7,850, cancel it and see 9,850; repeat on `/e2e-classic-cart/` and `/e2e-classic-checkout/` with a full refund instead. This is `apps/e2e/specs/reward-redemptions.spec.ts`; the slider's walk-through is `apps/e2e/specs/redemptions.spec.ts`.

## Open questions

Found by the review and manual testing of 2026-10-16. Each needs a product decision before it is fixed; none is fixed on this branch.

1. **An order reused after a failed payment keeps the wrong debit.** WooCommerce reuses the order when the cart hash matches, and the hash ignores coupon codes. Swapping reward A for an equal-value B debits B and leaves A's debit; a partial overlap ({A, B} then {A, C}) answers `duplicate` and C is never debited (`CommitWriter.php:94`, `:176`).
2. **Un-cancel after a refund that justified everything** (`full` policy, any partial refund) computes a debit of 0 and leaves the row `cancelled`; deleting that refund later skips the row, so the customer keeps the points on a live order (`Reversal.php:148`).
3. **A stop the shopper cannot clear.** After the balance falls below the slider's minimum, or below a picked reward's cost with nothing else to tick, the choice stays saved as the spec requires and checkout stops on every attempt until the balance rises.
4. **Earning after a refund.** An order refunded in full before it reached `completed` still earns its full points when it is completed later, because the award does not read existing refunds (earning side, not new on this branch).
5. **`refunded` → live.** An order set to Refunded by hand (no refund object) and then completed again keeps the restored points; only `cancelled` and `trash` count as dead statuses.
6. **Percent cap on an edited order.** A percent reward whose cap was not reached at checkout is stored as a percentage in `coupon_info`; an admin who adds items to that order raises its discount past `max_discount`. The debit is unchanged.
7. **Backlog item `2026-10-07-recalculate-drops-the-points-discount`** describes a drop that does not happen (see Edge cases); its Done-when boxes already hold.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Issue a `shop_coupon` per claimed reward | Adds a claim step, expiry and an `issued`/`used` lifecycle this version does not need |
| One combined virtual coupon for all rewards | Re-implements percent ordering, minimum spend, sale exclusion and the per-reward split refunds need |
| Negative cart fees | Odd tax handling, ignore sale and minimum rules, discouraged by WooCommerce |
| Debit from the session at checkout | The order's own coupon line is what the customer pays against; re-quoting it under the lock keeps debit and discount equal |
| Charge only when a reward gives its full face value | The user chose a partial value at full cost, shown before Apply |
| A React inner block for the card | A second renderer; the slot fill shows the server's markup |
| Rewards tab markup in Lite gated by handlers | Ships Pro UI in the Lite zip; the panel filter keeps it in `includes/Pro/` |
| A version bump so existing sites install the table | The plugin is unreleased at 1.0.0 |
