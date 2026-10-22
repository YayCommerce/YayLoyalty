# Phase 2: List card on the cart

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1 (both keys in the session, netted `quote()` / `ceiling()`, both keys on the wire)
**Delivers:** the v2 card everywhere it shows (classic cart and checkout, Cart and Checkout blocks, shortcode, action) in its instant cart mode: header with worth, restyled guest card, the Points discount row that expands into the slider, Pro reward rows, no tabs and no Apply buttons.
**Exit criterion:** `composer test -- --filter RedeemCardViewTest` passes; spec AC 1–8, 12 and 13 by hand on `/e2e-classic-cart/` and the Cart block page (`/cart/`), Pro build, plus AC 1's Lite line on a Lite build (`./scripts/run.sh dev lite`); no console errors (AC 14).

Tasks 1 and 2 land in one commit: `RewardsPanel` names `RedeemCard::PANELS_FILTER`, so a Pro site fatals at boot between the two. Until Task 3 lands the old `redeem.js` drives the new markup and nothing applies; check in the browser only from Task 2 step 5 on.

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

One more decision, for spec AC 5: `is_disabled` is `max < min && $requested <= 0`. A row the shopper ticked stays ticked and can be unticked when the rewards leave nothing for it (ceiling 0); it shows its `bound_by` note, and only its range and number field are disabled (they disable whenever `max < min`). An unticked row that cannot reach its minimum is the disabled, struck-through "{min} minimum" row.

- [ ] **Step 1: Update the existing view tests to the `slider` state**

Every `RedeemCard::view( … )` call gains the fourth argument, the session's points. Per case:

```php
// test_the_form_offers_what_clamp_grants_for_the_whole_balance — view( 9850, $quote, $ceiling, 0 )
$this->assertSame( 'slider', $view['state'] );
$this->assertSame( 0, $view['requested'] );
$this->assertSame( 0, $view['points'] );
$this->assertSame( 0.0, $view['value'] );
// then the existing balance, min, step, max 9800, max_value 98.0, rate, is_disabled false, bound_by '' assertions

// test_a_balance_below_the_minimum_disables_the_form — view( 50, $quote, $ceiling, 0 ): state 'slider', max 0, is_disabled true
// test_the_slider_minimum_is_the_first_step_at_or_above_the_reward_minimum — view( 9850, $quote, $ceiling, 0 )['min'] is 200

// test_applied_points_leave_the_rest_of_the_balance → rename test_applied_points_are_the_quotes — view( 9850, $quote, $ceiling, 5000 )
$this->assertSame( 'slider', $view['state'] );
$this->assertSame( 5000, $view['requested'] );
$this->assertSame( 5000, $view['points'] );
$this->assertSame( 50.0, $view['value'] );
$this->assertSame( 9800, $view['max'] );
$this->assertArrayNotHasKey( 'remaining', $view );
$this->assertSame( '', $view['bound_by'] );

// test_a_lowered_request_names_the_limit_that_lowered_it — view( 9850, $quote, $ceiling, 5000 ): points 2000, bound_by Clamp::BOUND_CART
// test_a_guest_gets_the_sign_in_line_and_a_refused_customer_nothing — both calls pass 0; expectations unchanged
```

The fixture keeps its 5,000-point request (50.0); the README's "applied → 500, 5.0" shorthand does not match the test's numbers.

- [ ] **Step 2: Add the AC 5 case**

```php
/** Rewards took the whole balance: the ticked row stays ticked, applies nothing and says why. */
public function test_a_ticked_slider_the_rewards_leave_nothing_for_stays_ticked_with_the_balance_note(): void {
    $reward  = $this->reward();
    $ceiling = Clamp::apply( $reward, PHP_INT_MAX, 0, 85.0, 'USD', 2 );
    $quote   = Clamp::apply( $reward, 500, 0, 85.0, 'USD', 2 );

    $view = RedeemCard::view( 2000, $quote, $ceiling, 500 );

    $this->assertSame( 'slider', $view['state'] );
    $this->assertSame( 500, $view['requested'] );
    $this->assertSame( 0, $view['points'] );
    $this->assertSame( 0.0, $view['value'] );
    $this->assertSame( 0, $view['max'] );
    $this->assertFalse( $view['is_disabled'] );
    $this->assertSame( Clamp::BOUND_BALANCE, $view['bound_by'] );
}
```

- [ ] **Step 3: Run the tests to confirm they fail**

Run: `composer test -- --filter RedeemCardViewTest`
Expected: FAIL on every case but the guest/hidden one (state `form`/`applied`, no `requested` key).

- [ ] **Step 4: `LauncherPayload::worth( Reward $reward, int $balance ): ?float` → `public static`**

Body unchanged. Docblock: the money a balance buys at the reward's rate with no cart limit; null when it buys nothing; shared by the launcher and the redeem card's header.

- [ ] **Step 5: `RedeemCard::view( ?int $balance, Result $quote, Result $ceiling, int $requested ): array`**

`guest` and `hidden` as today. Otherwise one `slider` array with the README's keys: `requested` is `$requested`; `points` / `value` the quote's when `$quote->is_allowed()`, else 0 / 0.0; `balance`, `min`, `step`, `max`, `max_value`, `rate` and `bound_by` computed as today's `form` branch; `is_disabled` per the decision above. Rewrite the docblock's `@return`.

- [ ] **Step 6: Run the tests to confirm they pass**

Run: `composer test -- --filter RedeemCardViewTest`
Expected: PASS, 7 tests.

- [ ] **Step 7: `RedeemCard::heading( ?int $balance, ?float $worth ): string`**

Title span as today; for a customer the balance span as today, then, when `$worth` is non-null, `<span class="yayloyalty-redeem__worth"><span aria-hidden="true">⇄</span> {LauncherCopy::money( $worth )}</span>`. Every value escaped. Docblock: the worth is the only money the heading prints, and only while `cart-discount` is live.

- [ ] **Step 8: `ROWS_FILTER` and `rows()` in place of the panels**

Delete `PANELS_FILTER`, `SLIDER_PANEL`, `panels()` and `tabs()`. Add `public const ROWS_FILTER = 'yayloyalty_redeem_card_rows';` with a one-line docblock, and `private function rows( \WC_Cart $cart, int $user_id, int $balance ): array` that applies it to `[]` with the context `[ 'service' => …, 'cart' => …, 'user_id' => …, 'balance' => … ]` and keeps only the string entries, in order. The hook docblock moves over from `panels()`: internal seam for Pro, not a public API; each string is printed as given inside the card's list, so whoever adds it escapes it; `@param string[] $rows` and the context shape.

- [ ] **Step 9: `markup()` and the template's view**

Keep the cart checks, the `try`/`catch` and the buffer cleanup. A guest: `template( view( null, $guest, $guest, 0 ) + [ 'heading_balance' => null, 'worth' => null, 'rows' => [], 'summary' => [ 'points' => 0, 'value' => 0.0 ] ] + chrome() )`. A customer: `$view = view( $balance, $quote, $ceiling, $this->intent->get() )` (ceiling and quote from the service, as `slider_view()` read them; drop `slider_view()`); `$rows = $this->rows( … )`; return `''` when the view is `hidden` and `$rows` is empty; else the template with `heading_balance` `$balance`, `worth` (below), `rows`, `summary` (below) and `chrome()`.

- `worth`: `null === ( $reward = ( new RewardRepository() )->find_live_cart_discount( Dates::now() ) ) || $balance <= 0 ? null : LauncherPayload::worth( $reward, $balance )` (assign first; no assignment in the condition).
- `private function summary( \WC_Cart $cart, int $user_id, Result $quote ): array` returning `array{points: int, value: float}`: `value` is the README decision's sum over `$cart->get_applied_coupons()` filtered by `EarnContext::is_loyalty_code()` (import as `RedemptionService` does), rounded to `wc_get_price_decimals()`; `points` is `$quote->is_allowed() ? $quote->get_points() : 0` plus `get_cost_points()` of every `quote_rewards()` row whose reward is non-null and whose id is in `CouponProvider::applied_reward_ids( $cart )`.

Rewrite the class docblock (one list: the Points discount row, then the rows `ROWS_FILTER` adds) and `markup()`'s docblock (`''` when there is neither a slider nor a row).

- [ ] **Step 10: Rewrite `templates/redeem-card.php`**

Top docblock: the card's three shapes (guest; customer list), the `$view` keys of step 9, the no-`name` rule. Drop the tab bar, panel wrappers, `$yayloyalty_open_panel`, the `form` and `applied` branches and their strings. The markup, top to bottom:

- Root as today (`.yayloyalty-redeem`, `[data-yl-redeem]`, `data-state`, `data-endpoint`, `data-nonce`), with the money attributes and `data-rate` printed when the state is `slider`; `data-off-template` is `sprintf( __( '%1$s = %2$s', 'yayloyalty' ), $yayloyalty_label, '%s' )` (translators: 1: the store's points label, as "Points", 2: money amount), so the script replaces `%s` alone.
- An inline gift SVG (`aria-hidden="true"`, `focusable="false"`), one copy in a local variable, used by both shapes.
- Guest (`guest`): `.yayloyalty-redeem__guest` with the icon in `.yayloyalty-redeem__badge`, `<h3>` with `heading( null, null )`, the `message_for( no_customer )` line in `.yayloyalty-redeem__text`, and `<a class="yayloyalty-redeem__signin" href="account_url">` "Sign in". Nothing else.
- Customer: `.yayloyalty-redeem__header` with the icon and `<h3 class="yayloyalty-redeem__heading">` from `heading( heading_balance, worth )`; then `<div class="yayloyalty-redeem__list" data-yl-list id="{wp_unique_id( 'yayloyalty-redeem-' )}-list">`.
- The Points discount row, only for `slider`: `<div class="yayloyalty-redeem__item" data-yl-slider>` holding a `<label class="yayloyalty-redeem__option">` with `.yayloyalty-redeem__option-body` (`.yayloyalty-redeem__name` `CouponProvider::display_label()`, `.yayloyalty-redeem__chip` "Flexible amount", and, when `is_disabled`, `.yayloyalty-redeem__desc` with today's "%s minimum" string) and the checkbox `.yayloyalty-redeem__check[data-yl-slider-toggle]`, `checked` when `requested > 0`, `disabled` when `is_disabled`. Then `<div class="yayloyalty-redeem__body" data-yl-slider-body>`, `hidden` unless `requested > 0`: `.yayloyalty-redeem__limits` with "Min %s" / "Max %s" (translators: points amount with the store's label, as "100 Points"; `points_text( min )`, `points_text( max )`), the range and number field as today (`aria-label` "Points to spend", min, step, `max( min, max )` on the range and `max` on the field) with value `points > 0 ? points : max( min, max )` and `disabled` when `max < min`, the unit label, `[data-yl-off]` reading the off-template with `money( points > 0 ? value : max_value )`, and the `bound_by` note (`role="status"`) as today.
- Each string in `$view['rows']`, printed as given (keep today's phpcs ignore comment, reworded for the rows filter).

- [ ] **Step 11: Lint**

Run: `composer lint -- includes/Engine/Storefront/LauncherPayload.php includes/Engine/Storefront/RedeemCard.php includes/Engine/Storefront/templates/redeem-card.php tests/unit/Engine/Storefront/RedeemCardViewTest.php`
Expected: no errors or warnings in these files. `git grep -n "PANELS_FILTER\|SLIDER_PANEL\|data-yl-tab" includes` shows only `includes/Pro/Storefront/RewardsPanel.php` (Task 2 removes it).

### Task 2: Pro reward rows on the new filter

**Files:**
- Modify: `includes/Pro/Storefront/RewardsPanel.php` (`panel()` → `rows()` on `RedeemCard::ROWS_FILTER`)
- Modify: `includes/Pro/Storefront/templates/rewards-panel.php`

**Interfaces:**
- Consumes: `RedeemCard::ROWS_FILTER` and its context (README contract).
- Produces: one `<label>` row per reward with `[data-yl-reward]`, wrapped once in `[data-yl-rewards]` (`data-balance`, `data-short-text`); no footer, no Apply/Update, no "Applied" tag, no selection line; denied rows carry `data-denied`, a struck-through name and the reason in place of the description.

- [ ] **Step 1: `RewardsPanel::rows( array $rows, array $context ): array`**

Replaces `panel()`, registered on `RedeemCard::ROWS_FILTER` (10, 2). Same guards (`service` / `cart` instances, no option, every option refused by a gate → `$rows` unchanged), then appends one string: the template's HTML. The private helper of today's name becomes `row_views()`, unchanged except its docblock (no "Apply"; `is_short` is still re-enabled by redeem.js as other ticks drop). The template's view is `[ 'rows' => row_views(…), 'balance' => $balance ]`. Delete `KEY`. Rewrite the class docblock: the card's reward rows, ticked to apply; `applied_points()`'s docblock drops "exclusivity notice".

- [ ] **Step 2: Rewrite `rewards-panel.php`**

Docblock: the reward rows, `$view` is `rows` and `balance`. Keep the wrapper `.yayloyalty-redeem__rewards[data-yl-rewards]` with `data-balance` and `data-short-text` only (drop `data-none-text`, `data-selection-template`, `data-points-template`, `data-label-one`, `data-label-many`, `data-thousand-sep`, and the `$yayloyalty_none` / `$yayloyalty_line` strings). Drop the `.yayloyalty-redeem__options` wrapper, the "Applied" tag and the footer. Each row is `<label class="yayloyalty-redeem__option">` with `.yayloyalty-redeem__option-body` first (name, then `.yayloyalty-redeem__chip` with `points_text( cost )`, `[data-yl-desc]` as today, the "off here" line as today) and the checkbox last, its attributes as today (`data-yl-reward`, `value`, `data-cost`, `data-denied`, `checked`, `disabled`), the same order as the Points discount row.

- [ ] **Step 3: Lint**

Run: `composer lint -- includes/Pro/Storefront/RewardsPanel.php includes/Pro/Storefront/templates/rewards-panel.php`
Expected: no errors or warnings in these files. `git grep -n "PANELS_FILTER\|SLIDER_PANEL\|data-yl-apply\|data-yl-selection\|is_slider_applied" includes` shows nothing.

- [ ] **Step 4: Run the unit suite**

Run: `composer test`
Expected: PASS, with no failure that `dev/edd` does not have.

- [ ] **Step 5: Verify the markup**

Pro build, spec fixtures (9,850 Points, A and B, $100 cart). Open `/e2e-classic-cart/` and `/cart/`; view the card's DOM.
Expected: header "Your rewards", "9,850 Points", "⇄ $98.50"; a list with the unticked "Points discount" row ("Flexible amount" chip, body hidden), then rows A and B, each with name, cost chip, description and an unticked checkbox; no tab, no Apply, no selection line; no PHP notice in `wp-content/debug.log`. Signed out: the guest card of AC 12 with "Sign in" linking to My Account.

- [ ] **Step 6: Commit Tasks 1 and 2**

Stage the files of both tasks.
Message: `feat(storefront): draw the redeem card as one list`

### Task 3: Instant behaviour and styles

**Files:**
- Modify: `assets/storefront/redeem.js`
- Modify: `assets/storefront/redeem.css`

**Interfaces:**
- Consumes: the markup hooks (README contract); `send()` and `lock()` as today.
- Produces: cart mode — `[data-yl-slider-toggle]` change → `{ points: max }` / `{ points: 0 }`; `[data-yl-range]` `change` and `[data-yl-points]` `change`/Enter → `{ points }` debounced 500 ms, `input` only updates `[data-yl-off]`; `[data-yl-reward]` change → `{ rewards: [ticked ids] }`; short rows recomputed client-side (cost > balance − other ticked costs). Removes tab, Apply/Update and selection-line code. `send()` posts `points` and `rewards` independently when both are present (phase 3 relies on it). CSS per the mock with `--yl-redeem-accent: #4353ff` and `accent-color`; the list works at 320 px.

- [ ] **Step 1: `send( root, payload )` with both keys**

`payload` is `{ points?: number, rewards?: number[] }`. The classic body appends `rewards` (joined, present-and-empty clears) when `"rewards" in payload` and `points` when `"points" in payload`, independently. The blocks transport already passes the payload through. Update its JSDoc.

- [ ] **Step 2: Delete the tab and button code**

Remove `selectTab()`, the tabs `keydown` listener, `apply()`, `applyRewards()`, `pointsText()`, the click listener for `[data-yl-apply]` / `[data-yl-remove]` / `[data-yl-apply-rewards]` / `[data-yl-tab]`, and the selection-line and Apply-button parts of `refreshRewards()`, which becomes `refreshShort( list )`: the short-row loop alone. Rewrite the file's header comment for the list card.

- [ ] **Step 3: Cart-mode handlers**

- `change` on `[data-yl-slider-toggle]`: show or hide `[data-yl-slider-body]` at once, then `send( root, { points: checked ? Number( field.max ) : 0 } )`, `field` the root's `[data-yl-points]`.
- `input` on `[data-yl-range]` / `[data-yl-points]`: as today (field follows the range, `sync( root, false )`, preview only).
- `change` on `[data-yl-range]`, `change` on `[data-yl-points]` (after `sync( root, true )`) and Enter on `[data-yl-points]` (keep today's `preventDefault`, which stops the checkout form submitting): `schedule( root, points )`, a trailing 500 ms debounce kept per root in a `WeakMap`, which calls `send( root, { points } )` once. A release that fires both `change` and Enter sends once.
- `change` on `[data-yl-reward]`: `refreshShort( list )`, then `send( root, { rewards: ticked ids as numbers } )`.

- [ ] **Step 4: Rewrite `redeem.css` for the list**

Keep the header comment's rules (everything nested under the root, px sizes, colours as root variables) and the theme resets. Keep the `--yayloyalty-redeem-*` names and set `--yayloyalty-redeem-accent: #4353ff`, with a hover shade and `--yayloyalty-redeem-accent-soft` for ticked rows; `accent-color: var(--yayloyalty-redeem-accent)` on the checkboxes and the range. Delete the rules for `__tabs`, `__tab`, `__panel`, `__box`, `__title`, `__row`, `__remove`, `__apply`, `__options`, `__tag`, `__footer` and `__selection`. Add: `__header` (icon, title, balance and worth on one line, right-aligned numbers, wrapping under 360 px); `__guest` and `__badge` (black 40 px circle, white icon) and `__signin` as a full-width button; `__list` (bordered, rounded, rows divided by 1 px); `__option` as a two-column grid (body, checkbox right) with `:has(:checked)` giving `__item` / `__option` the soft background; `__chip`; a denied row (`:has([data-denied])`) striking `__name` through; a disabled row muted; `__body` (limits as two ends of one line, range full width, field + unit + preview on one line); `__note`; `[data-busy]` dimmed. Check at 320 px: no horizontal scroll, the chip wraps under the name.

- [ ] **Step 5: Verify on the classic cart**

Pro build, spec fixtures, `/e2e-classic-cart/`, DevTools Network and Console open.
Action and expected, in order:
- Tick A → one `yayloyalty_redeem` request; "$5 off −$5", total $95; A ticked on a soft background. Untick → row gone, $100 (AC 2).
- Tick Points discount → body opens with "Min …", "Max …" and the discount at the maximum, the ceiling's points and value of AC 3; type 500, Enter → one request, "Points discount −$5". Drag the range: "Points = $X" follows live; release → one request after 500 ms. Untick → discount gone (AC 3).
- Tick A and B, then Points discount at 500 → "15% off −$10.00", "$5 off −$5.00", "Points discount −$5", $80.00; Max reads 7,850 Points (AC 4).
- Balance 2,500 (adjust in the customer drawer), slider at 2,500; tick B → slider re-clamps to 1,000 ($10) with the `balance` note. Balance 2,000 with A + B ticked → Points discount stays ticked, applies nothing, shows the note (AC 5).
- Balance 1,800, tick B → A disabled, "Not enough Points" (AC 6). $20 cart → A disabled, name struck through, "Add $5 more to use this" (AC 7).
- A, B and slider applied: "[Remove]" on the "Points discount" totals row → A and B stay, Points discount unticked; remove A's line → B and the slider stay, the slider re-clamps up (AC 8).
- No console error (AC 14).

- [ ] **Step 6: Verify on the Cart block**

Repeat step 5 on `/cart/` (the blocks chip in place of "[Remove]" for AC 8; the requests go to the Store API `cart/extensions`). Then on `/checkout/` and `/e2e-classic-checkout/`, tick A: it applies at once (cart mode until phase 3) and no console error shows. At 320 px wide (DevTools device toolbar) the list has no horizontal scroll.

- [ ] **Step 7: Commit**

Stage `assets/storefront/redeem.js` and `assets/storefront/redeem.css`.
Message: `feat(storefront): apply the redeem card's ticks and slider instantly`

### Task 4: Phase exit

- [ ] **Step 1: Unit test**

Run: `composer test -- --filter RedeemCardViewTest`
Expected: PASS, 7 tests.

- [ ] **Step 2: Pro, by hand**

Spec AC 1–8 on `/e2e-classic-cart/` and `/cart/`, as in Task 3 steps 5 and 6. Then AC 12 signed out, and AC 13: pause `cart-discount` (Rewards admin) → header "9,850 Points" with no "⇄", no Points discount row, A and B still listed; set it live again. No console error (AC 14).

- [ ] **Step 3: Lite, by hand**

Run `./scripts/run.sh dev lite`, open `/e2e-classic-cart/` as the fixture customer.
Expected: the header and the Points discount row alone (AC 1's Lite line); ticking it applies the discount. Back to `./scripts/run.sh dev pro` afterwards, and `git status` shows no change from the admin-shell sync ([known issue](../../known-issues.md)).

- [ ] **Step 4: Plan check**

Set the README's phase 2 Status to `implemented`, commit it (`docs(redemption): mark redeem card v2 phase 2 implemented`), then run `/yayplancheck docs/plans/2026-10-22-redeem-card-v2`. The two e2e specs failing here is expected (README).
