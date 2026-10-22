# Phase 3: Checkout mode, e2e and docs

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 2 (markup hooks, `summary`, instant cart mode, `send()` with both keys)
**Delivers:** on classic and block checkout the card collapses to "✓ −$X · N Points used" + Change while something is applied, and edits are a draft applied by one Update; the e2e specs cover the new card; the docs record the reversed decision.
**Exit criterion:** spec AC 15 and 16 by hand on `/e2e-classic-checkout/` and the Checkout block page; then spec AC 17: `pnpm --filter @yayloyalty/e2e test -- reward-redemptions redemptions` passes against `env:start` with a fresh `pro` build (`./scripts/run.sh build pro`).

### Task 1: Collapsed summary and draft at checkout

**Files:**
- Modify: `includes/Engine/Storefront/templates/redeem-card.php` (summary, Change, Update, `data-applied`, `data-requested`)
- Modify: `assets/storefront/redeem.js`
- Modify: `assets/storefront/redeem.css`
- Modify: `assets/storefront/redeem-blocks.js` (header comment only: it still names Apply and Remove)

**Interfaces:**
- Consumes: template `summary`; markup hooks; `send()` with `{ points, rewards }` (README contract).
- Produces: checkout mode — `data-mode="checkout"` (else `cart`) on `[data-yl-redeem]` when it sits inside `form.checkout` or `.wp-block-woocommerce-checkout`, set at init and after every replacement; `data-expanded` set by `[data-yl-change]` with `aria-expanded`; collapsed by default when the server printed `data-applied` (`summary.points > 0`); in checkout mode no control sends a request — `[data-yl-update]` sends `{ points, rewards }` once, collapses without a request when nothing changed, and is disabled while nothing is applied and nothing changed. New strings "−%1$s · %2$s used" (money, points text; the ✓ is an `aria-hidden` span, rendered "✓ −$10 · 1,000 Points used" as in spec AC 15), "Change", "Update".

Key decisions for the steps:
- The server prints one markup for both modes (the Store API card is shared by the Cart and Checkout blocks). CSS picks what shows from `data-mode`, `data-applied` and `data-expanded`. The checkout balance line "9,850 Points ⇄ $98.50" under the title is the header's existing balance and worth spans, restyled in checkout mode, not a second copy.
- `data-mode` comes from one `MutationObserver` on `document.body` (`childList`, `subtree`) that marks every `[data-yl-redeem]:not([data-mode])`, plus one call at script start. It covers the classic order-review refresh, `replaceCard()` and the blocks' redraw, which replaces the slot's `innerHTML` and drops the attribute. Event handlers read the mode from the attribute.
- "Changed" compares the DOM with the server's state as the markup printed it: each checkbox's `checked !== defaultChecked`, and for a ticked, enabled slider field `clamp( range, field.value ) !== Number( field.defaultValue )`.
- The draft's `points`: 0 when the Points discount box is unticked; the clamped field value when it is ticked and the field is enabled; the toggle's `data-requested` when it is ticked and the field is disabled (spec AC 5: rewards left nothing, and Update must not overwrite the shopper's request with the minimum). `rewards`: the ticked reward ids. Update sends both keys.

- [ ] **Step 1: Template**

In `redeem-card.php`, customer branch:
- Assign the list id once, before the header: `$yayloyalty_list_id = wp_unique_id( 'yayloyalty-redeem-' ) . '-list';`, used by the list's `id` and both buttons' `aria-controls`.
- `$yayloyalty_is_applied = $view['summary']['points'] > 0;` and the root gains `data-applied` when it is true (print the attribute for customers only; the guest root never has it).
- Inside `.yayloyalty-redeem__header`, after the `<h3>`: when applied, `<p class="yayloyalty-redeem__summary" data-yl-summary><span class="yayloyalty-redeem__tick" aria-hidden="true">✓</span> {text}</p>` with text `sprintf( __( '−%1$s · %2$s used', 'yayloyalty' ), LauncherCopy::money( summary.value ), SettingsHelper::points_text( summary.points ) )` (translators: 1: money amount, as "$10", 2: points amount with the store's label, as "1,000 Points"), then `<button type="button" class="yayloyalty-redeem__change" data-yl-change aria-expanded="false" aria-controls="{list id}">` "Change". Always, for a customer: `<button type="button" class="yayloyalty-redeem__update" data-yl-update aria-controls="{list id}">` "Update", `disabled` when not applied.
- The Points discount checkbox gains `data-requested="{requested}"`.
- Update the top docblock: the summary row, Change and Update are drawn for every customer card and shown only in checkout mode by `redeem.css`; `data-applied` marks a card with something applied.

- [ ] **Step 2: Lint the template**

Run: `composer lint -- includes/Engine/Storefront/templates/redeem-card.php`
Expected: no errors or warnings.

- [ ] **Step 3: `redeem.js` mode marking**

- `CHECKOUT_SELECTOR = "form.checkout, .wp-block-woocommerce-checkout"`.
- `markModes()`: for each `[data-yl-redeem]:not([data-mode])`, `data-mode` = `checkout` when `root.closest( CHECKOUT_SELECTOR )`, else `cart`.
- `isCheckout( root )`: `root.getAttribute( "data-mode" ) === "checkout"`.
- At script start, `markModes()`, then `new MutationObserver( markModes ).observe( document.body, { childList: true, subtree: true } )`.

- [ ] **Step 4: `redeem.js` draft and buttons**

- `draftOf( root ): { points: number, rewards: number[] }` and `isChanged( root ): boolean` per the key decisions. A card without the slider row sends `points: 0`; a card without reward rows sends `rewards: []`.
- `refreshUpdate( root )`: `[data-yl-update].disabled = ! root.hasAttribute( "data-applied" ) && ! isChanged( root )`.
- In the existing `keydown`, `change` and `input` handlers, branch on `isCheckout( root )`:
  - Toggle: show or hide the body as today; in checkout mode, `sync( root, true )` when ticked, `refreshUpdate( root )`, no `send()`.
  - Range and field `change`, Enter: in checkout mode `sync( root, true )` and `refreshUpdate( root )`, no `schedule()`. Enter keeps its `preventDefault()`.
  - Reward box: `refreshShort( list )` in both modes; in checkout mode `refreshUpdate( root )` in place of `send()`.
  - `input`: unchanged (preview only), plus `refreshUpdate( root )` in checkout mode.
- A delegated `click` listener:
  - `[data-yl-change]`: set `data-expanded` on the root and `aria-expanded="true"` on the button.
  - `[data-yl-update]`: if `! isChanged( root )`, remove `data-expanded` and set Change's `aria-expanded="false"`; else `send( root, draftOf( root ) )`. The answered card has no `data-expanded`, so it collapses when applied and opens when not; a failed request leaves the draft open (`send()` unlocks as today).
- Rewrite the file's header comment: cart mode applies at once; checkout mode holds a draft until Update.

- [ ] **Step 5: `redeem.css` for checkout mode**

Add, under the existing rules:
- Outside checkout mode, `[data-yl-summary]`, `[data-yl-change]` and `[data-yl-update]` are `display: none`.
- Collapsed, `[data-mode="checkout"][data-applied]:not([data-expanded])`: the list, Update, `__balance` and `__worth` hidden; summary and Change shown on the header row (summary under the title, Change pushed right).
- Edit, `[data-mode="checkout"]:not([data-applied])` and `[data-mode="checkout"][data-expanded]`: summary and Change hidden; the heading's title takes the full line and the balance and worth wrap under it (left-aligned); Update on the header row, right.
- `__tick` in the accent colour; `__summary` in the muted colour, 13px.
- `__change` as a text-style button in the accent colour; `__update` as a secondary button (accent border and text on a transparent background, the soft accent on hover), so it does not compete with the checkout's Place order button. Both 32px tall, and both wrap under the title below 360px.

- [ ] **Step 6: `redeem-blocks.js` header comment**

Replace "Apply, Remove and the rewards' Apply/Update stay in redeem.js" with what is true now: the card's ticks, slider and Update stay in `redeem.js`, and their requests are routed through the cart store, which redraws the card. No code change.

- [ ] **Step 7: Verify on the classic checkout**

Pro build, spec fixtures. Apply A and the slider at 500 on `/e2e-classic-cart/`, then open `/e2e-classic-checkout/` with DevTools Network and Console open.
Expected, in order:
- The card shows the gift icon, "Your rewards", "✓ −$10 · 1,000 Points used" and "Change" (`aria-expanded="false"`); no list (AC 15).
- Change → the list opens, `aria-expanded="true"`, "Update" on the header row, "9,850 Points ⇄ $98.50" under the title. Tick B and move the slider: no `yayloyalty_redeem` request, totals unchanged. Update → one request carrying `rewards` and `points`, the totals show "15% off", and the card collapses to the new summary (AC 16).
- Change, then Update with no change → collapses, no request (AC 16).
- Untick everything, Update → the card reopens expanded with no Change button and Update disabled (AC 15, 16).
- Review focus: with a draft open (Change, tick B), change the billing postcode → `update_order_review` replaces the card; it comes back collapsed from the server's state, and no console error shows.
- No console error (AC 14).

- [ ] **Step 8: Verify on the blocks**

Repeat step 7's first three bullets on `/checkout/` (the request is the Store API `cart/extensions` call). Then the Review focus item: on `/cart/` the same applied cart is in cart mode (`data-mode="cart"`, list shown, a tick applies at once), and on `/checkout/` the card is `data-mode="checkout"`. With a draft open on `/checkout/`, change the shipping address → the card reopens from the server's state with no console error. At 320px wide the collapsed and edit headers have no horizontal scroll.

- [ ] **Step 9: Commit**

Stage `includes/Engine/Storefront/templates/redeem-card.php`, `assets/storefront/redeem.js`, `assets/storefront/redeem.css`, `assets/storefront/redeem-blocks.js`.
Message: `feat(storefront): collapse the redeem card at checkout and edit it as a draft`

### Task 2: Rewrite the e2e specs

**Files:**
- Modify: `apps/e2e/support/storefront.ts`
- Modify: `apps/e2e/specs/reward-redemptions.spec.ts`
- Modify: `apps/e2e/specs/redemptions.spec.ts`

**Interfaces:**
- Consumes: the card's accessible names: checkboxes named by their row label ("Points discount Flexible amount", "$5 off 500 Points …"), the spinbutton "Points to spend", the buttons "Change" and "Update", the link "Sign in".
- Produces: `reward-redemptions.spec.ts` covers spec AC 17; `redemptions.spec.ts` uses the Points discount tick and the spinbutton instead of Apply/Remove.

Key decisions: every expectation reads the totals or the card's text after the answer, never a fixed wait; ticking the Points discount applies the ceiling first, so a test waits for the "Points discount" totals row before it types a number. Removed lines with no v2 equivalent ("500 Points applied — $5 off", "remaining", "selected", the exclusivity notices, "up to $20 here") are replaced as listed, not kept.

- [ ] **Step 1: Helpers in `support/storefront.ts`**

- `pointsDiscountBox( page: Page ): Locator` — the card's checkbox named `/^Points discount/`.
- `setPoints( page: Page, points: number ): Promise<void>` — fills the card's "Points to spend" spinbutton and presses Enter (cart mode: applies after the debounce).

- [ ] **Step 2: `reward-redemptions.spec.ts`**

Delete `applyRewards()`. Keep `rewardBox()`, `blockDiscounts()`, `addWidgetsToCart()` and the reward fixtures. The tests:
- `the points discount and rewards stack on the block checkout and a cancellation gives the points back` (9,850 Points, $100 cart):
  - Step "the cart card lists the points discount and both rewards" (AC 1): on `/cart/`, the card contains "Your rewards", "9,850 Points" and "$98.50"; `pointsDiscountBox` and both reward boxes are unchecked; the reward labels contain "500 Points" and "1,500 Points"; no `tab` role and no "Apply" button in the card.
  - Step "ticks apply at once" (AC 2, 3, 17): check A → `blockDiscounts` contains "$5 off" and the footer total "$95.00"; check `pointsDiscountBox` → `blockDiscounts` contains "Points discount"; `setPoints( 500 )` → footer total "$90.00".
  - Step "the block checkout collapses to the summary and the order debits both" (AC 15, 17): on `/checkout/`, the card contains "−$10 · 1,000 Points used", the "Change" button has `aria-expanded="false"`, and `[data-yl-list]` is hidden. Place the order; balance 8,850; `redeemRows` equals `[-500, -500]`; `redemptions` match two `applied` rows of −500 / `5.0000`; the order's `discount_total` "10.00", `total` "90.00".
  - Step "the drawer's ledger shows both debits" (AC 17): admin opens `#/customers/{id}`, the drawer's "Points ledger" tab, and two rows contain "-500".
  - Step "cancelling the order restores both" : balance 9,850, both rows `cancelled`.
- `the points discount and rewards stack on the classic pages, edited once at checkout, and a full refund gives the points back` (AC 8, 16, 17):
  - Cart step on `/e2e-classic-cart/`: check A, check `pointsDiscountBox`, `setPoints( 500 )` → two `tr.cart-discount` rows. The "[Remove]" on the "Points discount" row → one row ("$5 off"), `pointsDiscountBox` unchecked (AC 8). Check it again and `setPoints( 500 )` → two rows.
  - Checkout step on `/e2e-classic-checkout/` (wait for `update_order_review` and no `.blockUI`, as today): the card contains "−$10 · 1,000 Points used"; click "Change" (`aria-expanded="true"`); check B; `#order_review tr.cart-discount` still has two rows; click "Update" → three rows and the card contains "−$20 · 2,500 Points used". Place the order with cheque → balance 7,350; three `applied` rows (500 / `5.0000`, 1,500 / `10.0000`, 500 / `5.0000`, in any order: match with `expect.arrayContaining`).
  - Refund step: refund all five widgets, "Refund $80.00 manually" → balance 9,850, three rows `refunded`.
- `the slider's maximum is what the rewards leave` (AC 4), replacing the exclusivity test: on `/cart/`, check A and B, then `pointsDiscountBox` → the card contains "Max 7,850 Points" and `blockDiscounts` contains "15% off", "$5 off" and "Points discount".
- `rewards beyond the balance cannot be ticked` (AC 6): unchanged.

- [ ] **Step 3: `redemptions.spec.ts`**

Replace `applyPoints()` with `applyPointsAtCheckout( page, points )`: check `pointsDiscountBox`, fill the spinbutton, click "Update". On the cart use `pointsDiscountBox` + `setPoints()`.
- Block test: cart step — check the box → "Points discount" row shows; `setPoints( 500 )` → the spinbutton has value "500" and the card contains "Points = $5"; uncheck → the "Points discount" text is gone, the box unchecked, balance 1,000. Checkout step — `applyPointsAtCheckout( 500 )` → the card contains "−$5 · 500 Points used"; the rest unchanged.
- Classic test: cart step — check, `setPoints( 500 )`, then "[Remove]" → no `tr.cart-discount`, the box unchecked. Checkout step — `applyPointsAtCheckout( 500 )` → "−$5 · 500 Points used" and the "Points discount" order-review row; the rest unchanged.
- `the card offers no more than the balance and the cart allow`: guest — "Sign in to spend your Points." and the "Sign in" link, no checkbox in the card; balance 50 — `pointsDiscountBox` disabled and the card contains "100 Points minimum"; balance 4,950 — check the box → the card contains "Max 2,000 Points" and the spinbutton has value "2000".
- `checkout stops when the balance no longer covers the applied points`: `applyPointsAtCheckout( 500 )` and expect "−$5 · 500 Points used" in place of the old text; the rest unchanged.
- `redemption gates` `beforeEach`: expect `pointsDiscountBox` visible in place of the Apply button.

- [ ] **Step 4: Type-check and lint the e2e package**

Run: `pnpm --filter @yayloyalty/e2e exec tsc --noEmit`
Expected: no errors. (If the package has no `tsconfig.json`, Playwright's own transform at step 5 is the check.)

- [ ] **Step 5: Run the two specs**

Run: `./scripts/run.sh build pro`, `pnpm --filter @yayloyalty/e2e env:start`, then `pnpm --filter @yayloyalty/e2e test -- reward-redemptions redemptions`
Expected: every test passes. A failure is fixed in the spec only when the spec misreads the card; a product failure goes back to Task 1.

- [ ] **Step 6: Commit**

Stage the three files.
Message: `test(e2e): drive the redeem card's list, summary and Update`

### Task 3: Decision record and doc amendments

**Files:**
- Create: `docs/decisions/2026-10-22-stack-points-discount-and-rewards.md`
- Modify: `docs/decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md` (Status line only)
- Modify: `docs/specs/2026-10-16-redemption-design.md`
- Modify: `docs/architecture.md`

**Interfaces:** none.

Key decision: [the decision-record practice](../../decisions/2026-10-04-decision-record-practice.md) supersedes a record only whole. So the new record restates every decision of the 2026-10-14 record still in force (virtual coupons, the per-reward key, where the code lives) with the exclusivity replaced, and the old record's Status becomes `superseded by` the new one. This replaces the "mark the exclusivity part superseded" wording of the README's Touches list.

- [ ] **Step 1: Write the new record**

Format per the practice: title "Loyalty discounts are virtual coupons, the points discount and rewards stack, and each reward has its own ledger key"; **Purpose** and **Update when** lines; `## Status` "Accepted, 2026-10-22, by {`git config user.name`}, with the redeem card v2 design. It supersedes {a link to the 2026-10-14 record} whole."; `## Context` with the old record's facts that still hold, the shopper's need to use both on one order (the v2 mock), and the options turned down from the v2 spec (two commits back to back, one combined coupon, slider first, keep exclusive) plus the old record's (a `shop_coupon` per claim, negative fees); `## Decision` with four rules: virtual coupons (as before), **Stacking** (the session holds both; rewards are quoted against the full balance, the slider against what the allowed rewards leave, balance minus their cost and cart minus their value; the slider's coupon still applies last; a stacked order is debited by `CommitWriter::commit_all()` in one transaction under one customer lock, all or nothing; merchant coupons stack with both), per-reward key (as before), where the code lives (as before, with `yayloyalty_redeem_card_rows` adding Pro's rows to the card's one list); `## Consequences`, including that the slider can shrink or apply nothing when rewards are ticked, and that each slider quote now dry-runs the picked rewards (memoised per request). Paths and symbols only, no line numbers, no plan IDs.

- [ ] **Step 2: Supersede the old record**

In `2026-10-14-loyalty-discounts-as-virtual-coupons.md`, change only the Status section's line to "Superseded by {a link to the new record}, 2026-10-22. Accepted 2026-10-14 by Jason, with the reward redemption design."

- [ ] **Step 3: Amend the redemption spec**

In `docs/specs/2026-10-16-redemption-design.md`:
- Header: add an "**Amended by:**" line linking the v2 spec, dated 2026-10-22, and link the new record in **Decision records** in place of the 2026-10-14 one.
- Goal: "in one of two exclusive ways" → the two ways, usable together on one order.
- Data and storage: **Order meta** is the sum of every loyalty line; **Session** holds both keys, `set()` and `set_rewards()` no longer clear each other.
- Cart sync: `sync_cart()` builds merchant + rewards + slider (drop "Never both"); `on_removed()` for the slider's code sets 0 and keeps the rewards.
- Checkout and commit: validation runs the rewards first, and a slider applying 0 passes beside an applied reward; commit's "Both … `skipped( reward_unavailable )`" becomes `commit_all()` for a stacked order.
- Storefront: header with the worth; the guest card; the one list (Points discount row, then `ROWS_FILTER` rows) replacing "Who sees what"'s tabs, "Slider states" and "Rewards tab"; cart mode and checkout mode as the v2 spec's UI section describes, by reference to it rather than copied; wire format: `points`, `rewards` or both, each applied (drop "a `rewards` key wins").
- Integration points: `yayloyalty_redeem_card_panels` → `yayloyalty_redeem_card_rows` with its v2 signature.
- Edge cases: "Balance drops between Apply and submit (rewards)" and "Slider and rewards sent one after the other" rows rewritten to the v2 spec's table.
- Acceptance criteria 2, 3, 5, 6, 7: each replaced by a pointer to the v2 spec's AC that now holds it (2 → v2 AC 1; 3 → v2 AC 2, 4; 5 → v2 AC 2, 8; 6 → v2 AC 4, 9; 7 → v2 AC 6, 10), keeping each number.

- [ ] **Step 4: Amend `docs/architecture.md`**

- The reward-coupons bullet: the session holds points and reward ids together; `CouponProvider` puts both kinds on the cart; the slider is quoted net of the allowed rewards.
- The `CommitWriter` bullets: a stacked order goes through `commit_all()`, one transaction and one lock.
- The Apply and Remove bullet: the card's ticks, slider and checkout Update only set `SessionIntent`, with `points`, `rewards` or both.
- The `yayloyalty_redeem_card_panels` bullet: `yayloyalty_redeem_card_rows` (`RedeemCard::ROWS_FILTER`) adds rows after the Points discount row in one list; Pro's `RewardsPanel` adds a row per reward; Lite's card is the header and the Points discount row; on checkout `redeem.js` collapses the card to a summary and holds edits until Update (mode from the page, since the blocks' card HTML is shared).
- The decision-record bullet: the second link points to the new record ("why rewards are virtual coupons that stack with the slider, with a key per reward").

- [ ] **Step 5: Check the links**

Run: `git grep -n "redeem_card_panels\|PANELS_FILTER\|2026-10-14-loyalty-discounts-as-virtual-coupons" -- docs ':!docs/plans' ':!docs/decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md'`
Expected: only the redemption spec's history lines and the new record's Status and Context link to the old record; no current-tense mention of the panels filter. `./scripts/run.sh help` lists a harness check; if it lists `check-harness`, run it and expect no new failure.

- [ ] **Step 6: Commit**

Stage the four files.
Message: `docs(redemption): record that the points discount and rewards stack`

### Task 4: Phase exit

- [ ] **Step 1: By hand**

Spec AC 15 and 16 on `/e2e-classic-checkout/` and `/checkout/`, as in Task 1 steps 7 and 8.

- [ ] **Step 2: End to end**

Run: `./scripts/run.sh build pro`, `pnpm --filter @yayloyalty/e2e env:start`, `pnpm --filter @yayloyalty/e2e test -- reward-redemptions redemptions`
Expected: every test passes (spec AC 17).

- [ ] **Step 3: Plan check**

Set the README's phase 3 Status to `implemented` and commit it (`docs(redemption): mark redeem card v2 phase 3 implemented`). Then `/yayplancheck docs/plans/2026-10-22-redeem-card-v2`; this is the final review, so it offers `/yaytidy` first.

- [ ] **Step 4: Close the plan**

Once the review passes: the README's phase 3 Status `done`, and the plan's **Status** `done YYYY-MM-DD`, in the PR that finishes it. Commit: `docs(redemption): close the redeem card v2 plan`.
