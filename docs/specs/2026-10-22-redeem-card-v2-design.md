# Redeem Card v2 Design

**Status:** accepted 2026-10-22
**Related:** Figma "Redeem card (cart and checkout) v2" and its checkout frames "02 / checkout_applied" and "02 / Points selected _ edit"; amends [the redemption design](2026-10-16-redemption-design.md) (Goal, Session, Cart sync, Checkout and commit, Storefront, AC 2, 3, 5, 6 and 7); reverses the exclusivity in [loyalty discounts are virtual coupons](../decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md)

## Goal

A signed-in customer at the cart and checkout sees one list: a "Points discount" row that expands into the slider, then (Pro) one row per reward. They can tick the points discount and any rewards together. On the cart every tick or slider change applies at once; on the checkout the card collapses to a one-line summary while something is applied, and edits are a draft applied by one Update button. Guests see a restyled sign-in card.

## Context

- Card: `includes/Engine/Storefront/RedeemCard.php`, `templates/redeem-card.php`, `assets/storefront/redeem.js`, `redeem-blocks.js`, `redeem.css`. Today it is a tab bar ("Rewards" / "Points discount") with a slider form, an applied block with Remove, and Apply buttons.
- Pro rows: `includes/Pro/Storefront/RewardsPanel.php`, `templates/rewards-panel.php`, attached through `RedeemCard::PANELS_FILTER` (`yayloyalty_redeem_card_panels`) as a tab.
- Engine: `SessionIntent` holds slider points or reward ids, never both; `CouponProvider::sync_cart()` applies one kind; `RedemptionService::commit()` skips an order carrying both with `reward_unavailable`; `CommitWriter::commit()` and `commit_rewards()` each run their own transaction.
- `ContextFactory::from_order()` already nets every non-slider coupon line (reward lines included) out of the slider's base; `from_cart()`'s `cart_subtotal()` excludes every loyalty code.
- `Reversal` settles per row with per-line ledger keys (`flexible_discount`, `reward_{id}`), so it already handles an order with both kinds.
- `LauncherPayload::worth()` (private) turns a balance into money at the `cart-discount` rate.

## Scope

**In:**
- Slider and rewards applied together on one cart and one order (Lite has only the slider, so nothing to stack).
- The single-list card, instant apply on the cart, the header worth, the restyled guest card.
- On classic and block checkout: the collapsed summary ("✓ −$X · N Points used" + Change) while something is applied, and the draft edit mode with Update.
- A combined commit in one transaction.
- Classic cart/checkout, Cart and Checkout blocks, shortcode and action (all share the template).

**Out:**
- Earn line for guests ("Earn 180 points with this order").
- Relabelling totals rows: they stay "Points discount" (merchant label) and the reward's name.
- A collapsed summary on the cart, or a "Use points" summary at checkout when nothing is applied (the checkout then opens expanded).
- The mock's "Points & rewards" title at checkout: the card keeps "Your rewards" everywhere.
- Keeping an unsaved checkout draft across a page reload.
- Any Design-tab styling of the card.
- The redemption spec's open questions 1–4 (reused order after a failed payment, etc.) — unchanged.
- Schema, ledger key or reversal changes.

## Acceptance criteria

Fixtures: the redemption spec's — Pro build; customer with 9,850 Points; `cart-discount` live at 100 Points = $1; reward A `fixed_coupon` "$5 off", 500 Points, `amount` 5, `min_order` 25; reward B `percent_coupon` "15% off", 1,500 Points, `percent` 15, `max_discount` 10; $100 cart, no merchant coupon, prices excluding tax.

1. **Card.** The Pro cart card shows "Your rewards", "9,850 Points" and "⇄ $98.50"; then an unticked "Points discount" row with a "Flexible amount" chip; then rows A and B, each with name, "{cost} Points" chip, description and an unticked checkbox. No tab, no Apply button, no "selected" line. Lite shows the header and the Points discount row alone.
2. **Instant reward.** Ticking A, with no other click, shows the "$5 off −$5" totals row and total $95; A's row is ticked with a soft background. Unticking it removes the row; total $100.
3. **Instant slider.** Ticking Points discount applies the maximum, `ceiling()`: 9,850 Points, $98.50 (the cart allows 10,000, the balance binds), and expands the row with "Min {min}", "Max {max}", range, number field and "Points = ${value}". Typing 500 and pressing Enter shows "Points discount −$5". Dragging the range updates "= $X" live and applies once on release (one request per release, ≥ 500 ms apart). Unticking removes the discount.
4. **Stacking.** Ticking A and B, then Points discount with 500, shows "15% off −$10.00", "$5 off −$5.00", "Points discount −$5", total $80.00. The slider's Max reads the ceiling after rewards: "Max 7,850 Points" (the balance left, 9,850 − 2,000, binds before the cart left, $85 = 8,500 Points).
5. **Slider shrinks.** Balance 2,500; slider applied at 2,500 ($25). Ticking B (1,500) leaves B applied and the slider re-clamped to 1,000 ($10) with the `balance` note "You do not have enough Points for this discount. Choose a lower amount and try again." (`message_for( 'balance' )`). With balance exactly 2,000 and A+B ticked, the slider row stays ticked, applies nothing, shows the `balance` note, and checkout passes.
6. **Short rows.** Balance 1,800: ticking B (1,500) disables A with "Not enough Points" (cost 500 > 1,800 − 1,500).
7. **Denied rows.** On a $20 cart A is disabled, its name struck through, reason "Add $5 more to use this" in place of its description.
8. **Remove one line.** With A, B and the slider applied, the classic "[Remove]" (or blocks chip) on "Points discount" leaves A and B applied and the slider row unticked; removing A's line leaves B and the slider (which re-clamps up).
9. **Combined commit.** Placing the order of AC 4 (classic and block checkout) writes three `applied` redemption rows (A: 500, `5.0000`; B: 1,500, `10.0000`; slider: 500, `5.0000`), `redeem` ledger rows −500 `…:reward_{A}`, −1,500 `…:reward_{B}`, −500 `…:flexible_discount`, balance 7,350, `_yayloyalty_points_redeemed` 2500 and `_yayloyalty_redeem_value` `20.00`. Re-firing the hook writes nothing (`duplicate`).
10. **Commit balance.** Dropping the balance to 2,200 between Apply and submit for AC 4's cart does not stop checkout: the rewards (2,000) are covered, so the slider applies 200 ($2) at the next totals run, and the order debits 2,200. Dropping it to 1,900 stops checkout with "You do not have enough Points for these rewards. Remove one and try again." and writes nothing.
11. **Reversal.** Cancelling the order of AC 9 restores 500, 1,500 and 500 and sets all three rows `cancelled`; a $40 refund (half of $80) on an un-cancelled copy restores 250, 750 and 250.
12. **Guest.** A guest sees a black circle with the gift icon, "Your rewards", "Sign in to spend your Points." and a "Sign in" button linking to My Account; no balance, no worth.
13. **Worth hidden.** With `cart-discount` paused on Pro, the header shows "9,850 Points" without "⇄", and the Points discount row is absent; reward rows still show.
14. **Console.** No console errors on classic or block cart and checkout.
15. **Checkout collapsed.** With A and the slider at 500 applied, the classic and block checkout card shows the gift icon, "Your rewards", "✓ −$10 · 1,000 Points used" and a "Change" button with `aria-expanded="false"`, and no list. With nothing applied it opens expanded with no Change button.
16. **Checkout draft.** Change expands the list (`aria-expanded="true"`) with a secondary "Update" button in the header row and the balance line "9,850 Points ⇄ $98.50" under the title. Ticking B and moving the slider sends no request and leaves the totals unchanged; Update sends one request with `{ points, rewards }`, the totals show the new lines, and the card collapses to the new summary. Update with no change collapses without a request. With nothing applied and no change, Update is disabled.
17. **End to end:** on the block cart tick A, tick Points discount and set 500, see total $90.00, place the order, see −500 and −500 in the drawer's Ledger tab and a balance of 8,850, cancel it and see 9,850; repeat on `/e2e-classic-cart/` and `/e2e-classic-checkout/` with a full refund instead, editing the selection at checkout once through Change → tick B → Update. This is `apps/e2e/specs/reward-redemptions.spec.ts`; the Lite slider walk-through stays `apps/e2e/specs/redemptions.spec.ts`.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments, union/`mixed`/`static` types.
- Only `LedgerService` writes ledger rows and balances, from its four construction sites; `CommitWriter` stays the only inserter of redemption rows.
- Lite names Pro only in `includes/YayLoyalty.php`; nothing under `includes/Engine/` imports `YayLoyalty\Pro\*`; Pro attaches through `yayloyalty_redeem_card_rows`.
- The storefront scripts and styles are plain JS/CSS with no build step and no JSX.
- No sentence hard-codes "points": counts go through `SettingsHelper::points_text()`, the label through `points_label_plural()`; money through `LauncherCopy::money()`.
- The card's inputs have no `name`. The card reads nothing from the Design tab.
- Every new string uses the `yayloyalty` text domain with a translators comment where it has placeholders.
- Classic checkout and Blocks are both supported; orders through WooCommerce CRUD only (HPOS).

## Approach

**Chosen:** stack within the existing engine.

**Architecture:** The session holds both kinds. Rewards are quoted first against the full balance (unchanged); the slider is quoted against what the allowed rewards leave — balance minus their cost, cart minus their value — inside `RedemptionService::quote()`/`ceiling()`, so the card, cart sync, Store API data and checkout validation agree from one place. `CouponProvider` puts both kinds on the cart (the existing coupon sort already applies the slider last). One new `CommitWriter::commit_all()` debits every line of a stacked order in one transaction under one customer lock. The card becomes a single checkbox list that applies on every change.

**Components:**
- `includes/Engine/Redemption/SessionIntent.php` — `set()` no longer unsets `REWARDS_KEY`; `set_rewards()` no longer unsets `KEY`; `clear()` unchanged (commit only). Docblocks drop "never both".
- `includes/Engine/Redemption/RedemptionService.php`:
  - `quote_for()` takes the allowed picked rewards from `quote_rewards()` and passes the context `with_balance( balance − Σ cost )` and a cart subtotal reduced by `Σ value` (new `Context` wither, e.g. `with_cart_subtotal()`). With no rewards picked the result is identical to today.
  - `quote_rewards()` is memoised per request, keyed on the picked ids plus `$cart->get_cart_hash()` plus the applied non-loyalty coupon codes.
  - `checkout_error()`: when rewards are picked, `rewards_error()` first (non-null → return it); then the slider's rules, except that a slider applying 0 points returns null when at least one picked reward applies.
  - `commit()`: an order with slider and reward lines goes to `writer->commit_all( $order, offered_rewards_of( codes ), flexible_reward() )` (`no_reward` when the flexible reward is missing); single-kind orders unchanged.
  - Remove `NOTICE_EXCLUSIVE_SLIDER`, `NOTICE_EXCLUSIVE_REWARDS` and their `message_for()` cases.
- `includes/Engine/Redemption/CouponProvider.php` — `sync_cart()` builds `merchant + wanted_rewards() + wanted_slider()` (each only when its intent is set); `on_removed()` for `CODE` calls `intent->set( 0 )` instead of `clear()`.
- `includes/Engine/Redemption/CommitWriter.php` — new `commit_all( \WC_Order $order, array $rewards, Reward $flexible ): Outcome`: one `Transaction::run()`, `ensure_and_lock()` once; duplicate when every reward code and the slider already have rows; re-quote each reward line as `commit_rewards()` does; re-quote the slider with `from_order( $order, $flexible, $locked − Σ reward cost )` and the same points/value checks as `commit()`; `rewards_balance` when Σ all costs > locked balance; then insert rows and `redeem` drafts (rewards in line order, slider last), summed `META_POINTS`/`META_VALUE`, `freeze()` for the slider's rate; any non-AWARDED debit rolls back all. Shared per-line helpers are extracted from `commit()`/`commit_rewards()` rather than copied.
- `includes/Engine/Redemption/Adapters/StoreApiAdapter.php` — `on_update()` applies `rewards` and `points` independently when present (no "rewards wins").
- `includes/Engine/Storefront/RedeemCard.php`:
  - `on_apply()` applies `rewards` and `points` independently when present.
  - `view()` returns `guest`, `hidden` or `slider`; `slider` carries `requested` (SessionIntent points), `points` and `value` (applied), `balance`, `min`, `step`, `max`, `max_value`, `rate`, `is_disabled` and `bound_by`. `max` is `ceiling()` (net of rewards).
  - `heading()` adds the worth when non-null.
  - `markup()` adds `summary` to the template's view: `{ points, value }`, the applied slider points plus every applied reward's `cost_points`, and the applied slider value plus every applied reward line's value; `points` 0 when nothing is applied.
  - `PANELS_FILTER` → `ROWS_FILTER = 'yayloyalty_redeem_card_rows'`, `( string[] $rows, array{service, cart, user_id, balance} $context ): string[]`; `tabs()`, `SLIDER_PANEL` and `is_slider_applied` removed.
- A shared worth helper — `LauncherPayload::worth()` made public static (or moved to `Clamp`) so `RedeemCard` and the launcher use one rule.
- `includes/Engine/Storefront/templates/redeem-card.php` — header, guest card, list with the Points discount row, then the filter's rows.
- `includes/Pro/Storefront/RewardsPanel.php`, `templates/rewards-panel.php` — `panel()` → `rows()` on the new filter; prints one `<label>` row per option; no footer, no button, no `data-locked`; the "Applied" tag removed.
- `assets/storefront/redeem.js` — instant apply (below); remove tab, Apply/Update and selection-line code.
- `assets/storefront/redeem.css` — rewritten to the mock.
- `assets/storefront/redeem-blocks.js` — unchanged unless the transport needs the combined payload (it does not; each action sends one field).

**Data and storage:** None new. Session keys `yayloyalty_redeem_points` and `yayloyalty_redeem_rewards` may now coexist. Order meta `_yayloyalty_points_redeemed` / `_yayloyalty_redeem_value` hold the sum of every loyalty line. Redemption rows and ledger keys unchanged.

**Integration points:**
- Renamed internal Pro seam: `yayloyalty_redeem_card_panels` → `yayloyalty_redeem_card_rows` (Pro is the only consumer).
- Wire: classic `wc-ajax=yayloyalty_redeem` POST with `security` and `points`, `rewards`, or both; Store API callback `yayloyalty-redeem` with `{ points }`, `{ rewards }`, or both. `extensions.yayloyalty` fields unchanged.
- Public action `yayloyalty_points_redeemed( Outcome, \WC_Order )` still fires once per order; for a stacked order the Outcome carries the slider's (last) debit and quote.
- WooCommerce hooks unchanged.

**UI:**
- Header: gift icon (inline SVG, `aria-hidden`), "Your rewards", right-aligned `points_text( balance )` and "⇄ {money}" (worth; only while `cart-discount` is live).
- Guest: black circle with the gift icon, "Your rewards", the existing `message_for( no_customer )` line, and a "Sign in" button (new string) linking to `account_url`.
- Points discount row: `CouponProvider::display_label()`, chip "Flexible amount" (new string), checkbox right. Ticked when `requested > 0`; expanded body: "Min {points_text(min)}" / "Max {points_text(max)}" (new strings with translators comments), range, number field, "{label} = {money}" (new string), `bound_by` note. Disabled and struck through with "{min} minimum" when `is_disabled`. Not rendered for `hidden`.
- Reward rows: checkbox, name, "{points_text(cost)}" chip, description, "{value} off here"; ticked → soft background; denied → disabled, struck through, reason replaces description; short → disabled, "Not enough Points".
- `redeem.js`, cart mode: tick Points discount → `{ points: max }` (the field's `max`); untick → `{ points: 0 }`; range `change` and number `change`/Enter → `{ points }`, debounced 500 ms, while `input` only updates the "= $X" preview; reward tick/untick → `{ rewards: [ticked ids] }`; `lock()` during each request, then the card is replaced as today. Short-row recomputation on tick stays client-side.
- Checkout mode: the card is in checkout mode when it sits inside `.woocommerce-checkout` (classic checkout body/form) or `.wp-block-woocommerce-checkout` (Checkout block); the script sets `data-mode="checkout"` on the root at init and after each replacement. The server prints one markup for both modes — the Store API's `card` cannot tell the Cart block from the Checkout block — and `redeem.css` shows the summary or the list from `data-mode` and `data-expanded`.
  - Collapsed (checkout, `summary.points > 0`, not expanded): gift icon, "Your rewards", "✓ {money(summary.value)} off · {points_text(summary.points)} used" (new string, translators comment; rendered as "✓ −$10 · 1,000 Points used"), and a "Change" button (new string) with `aria-expanded="false"` and `aria-controls` the list.
  - Edit (checkout, expanded or nothing applied): gift icon, "Your rewards", the balance line `points_text( balance ) ⇄ worth` under the title, and an "Update" button (new string, secondary style so it does not compete with the checkout CTA); then the list. Ticks and slider changes are a draft: no request, no debounce; the "= $X" preview and short rows update locally. Update sends `{ points: draft or 0, rewards: [ticked ids] }` in one request, then the replaced card collapses (it is applied) or stays open (nothing applied). Update with no change collapses without a request; disabled while nothing is applied and nothing changed.
  - The draft slider's Max is the server's last ceiling; if the drafted rewards leave less, the server clamps on Update and the row shows the limit note.
- Styles: card-local `--yl-redeem-accent: #4353ff`, `accent-color` for checkbox and range; layout per the mock; works at 320 px width.
- Totals rows unchanged.

**Edge cases and failure modes:**

| Case | Behaviour |
|---|---|
| Rewards use the whole balance with the slider ticked | Slider applies 0, row stays ticked with the `balance` note; checkout passes; unticking a reward restores the slider |
| Reward ticked while the slider is applied | Slider re-clamps to the balance left, `balance` note |
| Slider max < min even with rewards unticked | Row disabled, "{min} minimum"; a stale request is dropped by today's `ceiling()`-refused rule |
| Slider's totals row removed | `set( 0 )`; rewards stay |
| One reward's totals row removed | That id removed; slider stays and re-clamps |
| Balance drops before submit | Rewards uncovered → `rewards_balance`; otherwise the slider applies what is left, and `commit_all()` checks the locked balance |
| Crafted `{ points: 99999, rewards: [A, B] }` | Rewards in order against the balance, slider clamped to the rest; total ≤ balance |
| A stacked order's reward line denied at commit | `reward_unavailable`, nothing written, every loyalty line removed from the order, checkout fails (as today) |
| Downgrade to Lite with both in the session | Rewards denied `not_live` and dropped; slider re-clamps up |
| Rapid clicks / drags | `lock()` during each request; slider changes debounced |
| Request fails | Controls unlock, card unchanged (today's `send()` behaviour); a checkout draft stays open with its ticks |
| Checkout totals refresh (address change) while a draft is open | The card is replaced and the draft is lost; it reopens collapsed or expanded from the server's state |
| Shortcode or action placed on a non-checkout page | Cart mode |

**Decision record:** the implementing PR adds `docs/decisions/2026-10-22-stack-points-discount-and-rewards.md`, superseding the "exclusive of the slider" part of `2026-10-14-loyalty-discounts-as-virtual-coupons.md`, and marks that record accordingly. It also amends `docs/specs/2026-10-16-redemption-design.md` (adds "**Amended by:** this doc" and updates the sections listed under Related) and `docs/architecture.md` (the reward-coupons bullet and the decision-record bullet).

**Tests** (accepted by the user):
- `tests/integration/Engine/Redemption/CommitWriterTest.php` — `commit_all()` writes reward and slider rows, their `redeem` rows and summed meta in one transaction; re-fire answers `duplicate`; summed cost above the locked balance writes nothing.
- `tests/integration/Engine/Redemption/StackedQuoteTest.php` (new) — with rewards picked, the slider's `quote()`/`ceiling()` use balance − Σ cost and cart − Σ value; `checkout_error()` returns null when the slider applies 0 beside an applied reward.
- `tests/unit/Engine/Storefront/RedeemCardViewTest.php` — updated for the `slider` state.
- `apps/e2e/specs/reward-redemptions.spec.ts` rewritten for the list UI and AC 15; `apps/e2e/specs/redemptions.spec.ts` adjusted (tick in place of Apply/Remove).

## Alternatives considered

| Option | Why rejected |
|---|---|
| Two existing commits back to back (`commit_rewards()` then `commit()`) | A failure after the first transaction leaves the order half debited with no clean rollback |
| One combined `yayloyalty-redeem` coupon line | Loses WooCommerce's percent/cap math, one totals row per reward and per-reward refund keys |
| Slider first, rewards after | Needs reordered coupon weights and rewards re-quoted after the slider; rewards would lose priority |
| Keep exclusive (visual-only redesign) | User chose stacking, as the mock shows |
| One Apply button for a draft selection on the cart | User chose instant apply on the cart, as the mock shows |
| Instant apply at checkout with a "Done" button | User chose a draft with one Update at checkout, as the checkout mock shows |
| Summary "✓ 2 rewards selected" (mock) | Counts the points discount as a reward; value and points tell the shopper more |
| Collapsed summary on the cart too, or always collapsed at checkout | User chose checkout only, collapsed only while something is applied |
| Server-side checkout detection | The Store API's card HTML is shared by the Cart and Checkout blocks, so only the client knows which page it is on |
| Mock's "Rewards discount (…)" totals labels | User kept today's labels |
| Guest earn line | User chose sign-in only |

## Rollout

None: the plugin is unreleased at 1.0.0, no schema or option change. A session holding one kind keeps working. Release note: points discount and rewards can now be used together.

## Open questions

None.
