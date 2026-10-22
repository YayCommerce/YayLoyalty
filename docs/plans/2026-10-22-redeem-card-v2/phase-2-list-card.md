# Phase 2: List card on the cart

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (both keys in the session, netted `quote()` / `ceiling()`, both keys on the wire)
**Delivers:** the v2 card everywhere it shows (classic cart and checkout, Cart and Checkout blocks, shortcode, action) in its instant cart mode: header with worth, restyled guest card, the Points discount row that expands into the slider, Pro reward rows, no tabs and no Apply buttons.
**Exit criterion:** `composer test -- --filter RedeemCardViewTest` passes; spec AC 1–8, 12 and 13 by hand on `/e2e-classic-cart/` and the Cart block page, Pro build, plus AC 1's Lite line on a Lite build (`./scripts/run.sh dev lite`); no console errors (AC 14).

### Task 1: Card view, header and template

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPayload.php` (`worth()` → `public static`)
- Modify: `includes/Engine/Storefront/RedeemCard.php`
- Modify: `includes/Engine/Storefront/templates/redeem-card.php`
- Test: `tests/unit/Engine/Storefront/RedeemCardViewTest.php`

**Interfaces:**
- Consumes: phase 1 `quote()` / `ceiling()`, `SessionIntent::get()`.
- Produces: `RedeemCard::view( ?int $balance, Result $quote, Result $ceiling, int $requested ): array`, `heading( ?int $balance, ?float $worth ): string`, `ROWS_FILTER`, template key `summary`, and the markup hooks (README contract). Removes `PANELS_FILTER`, `SLIDER_PANEL`, `tabs()`, `panels()`.

Key decisions for the steps: `view()` folds `form` and `applied` into `slider`, whose `max` is the ceiling's points (net of rewards) and whose `points`/`value` are the quote's when allowed, else 0; `summary.value` sums `$cart->get_coupon_discount_amount( $code, ! $cart->display_prices_including_tax() )` over the cart's loyalty codes, and `summary.points` the slider quote's points plus each applied reward's `cost_points`; worth comes from `( new RewardRepository() )->find_live_cart_discount( Dates::now() )` and is null when that is null; the guest card keeps `message_for( no_customer )` and adds "Sign in"; new strings "Flexible amount", "Min %s", "Max %s", "%1$s = %2$s" (label, money), "Sign in", each with a translators comment where it has placeholders.

Steps: expand after Phase 1 lands. They include updating the five existing `RedeemCardViewTest` cases to the `slider` state (form → `requested` 0; applied → `points` 500, `value` 5.0) and running `composer test -- --filter RedeemCardViewTest`.

### Task 2: Pro reward rows on the new filter

**Files:**
- Modify: `includes/Pro/Storefront/RewardsPanel.php` (`panel()` → `rows()` on `RedeemCard::ROWS_FILTER`)
- Modify: `includes/Pro/Storefront/templates/rewards-panel.php`

**Interfaces:**
- Consumes: `RedeemCard::ROWS_FILTER` and its context (README contract).
- Produces: one `<label>` row per reward with `[data-yl-reward]`, wrapped once in `[data-yl-rewards]` (`data-balance`, `data-short-text`); no footer, no Apply/Update, no "Applied" tag, no selection line; denied rows carry `data-denied`, a struck-through name and the reason in place of the description.

Steps: expand after Phase 1 lands.

### Task 3: Instant behaviour and styles

**Files:**
- Modify: `assets/storefront/redeem.js`
- Modify: `assets/storefront/redeem.css`

**Interfaces:**
- Consumes: the markup hooks (README contract); `send()` and `lock()` as today.
- Produces: cart mode — `[data-yl-slider-toggle]` change → `{ points: max }` / `{ points: 0 }`; `[data-yl-range]` `change` and `[data-yl-points]` `change`/Enter → `{ points }` debounced 500 ms, `input` only updates `[data-yl-off]`; `[data-yl-reward]` change → `{ rewards: [ticked ids] }`; short rows recomputed client-side (cost > balance − other ticked costs). Removes tab, Apply/Update and selection-line code. `send()` posts `points` and `rewards` independently when both are present (phase 3 relies on it). CSS per the mock with `--yl-redeem-accent: #4353ff` and `accent-color`; the list works at 320 px.

Steps: expand after Phase 1 lands. Verification covers spec AC 2, 3 (one request per release, checked in the Network tab), 5–8 on classic and block cart.

### Task 4: Phase exit

Run the exit criterion above. Steps: expand after Phase 1 lands.
