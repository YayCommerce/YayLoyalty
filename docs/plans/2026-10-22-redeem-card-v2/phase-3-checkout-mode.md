# Phase 3: Checkout mode, e2e and docs

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 2 (markup hooks, `summary`, instant cart mode, `send()` with both keys)
**Delivers:** on classic and block checkout the card collapses to "✓ −$X · N Points used" + Change while something is applied, and edits are a draft applied by one Update; the e2e specs cover the new card; the docs record the reversed decision.
**Exit criterion:** spec AC 15 and 16 by hand on `/e2e-classic-checkout/` and the Checkout block page; then spec AC 17: `pnpm --filter @yayloyalty/e2e test -- reward-redemptions redemptions` passes against `env:start` with a fresh `pro` build (`./scripts/run.sh build pro`).

### Task 1: Collapsed summary and draft at checkout

**Files:**
- Modify: `includes/Engine/Storefront/templates/redeem-card.php` (summary row, Change, Update, balance line)
- Modify: `assets/storefront/redeem.js`
- Modify: `assets/storefront/redeem.css`

**Interfaces:**
- Consumes: template `summary`; markup hooks; `send()` with `{ points, rewards }` (README contract).
- Produces: checkout mode — set `data-mode="checkout"` on `[data-yl-redeem]` when it sits inside `form.checkout` or `.wp-block-woocommerce-checkout`, at init and after every replacement (a `MutationObserver` on the blocks' slot, or re-run in `replaceCard()` and on the blocks' redraw); `data-expanded` toggled by `[data-yl-change]` with `aria-expanded`; collapsed by default when `summary.points > 0`; in checkout mode no control sends a request — `[data-yl-update]` sends `{ points: ticked ? field value : 0, rewards: [ticked ids] }` once, collapses without a request when nothing changed, and is disabled while nothing is applied and nothing changed. New strings "−%1$s · %2$s used" (money, points text; the ✓ is an `aria-hidden` icon, rendered "✓ −$10 · 1,000 Points used" as in spec AC 15), "Change", "Update".

Steps: expand after Phase 2 lands. Verification includes the Review focus items: block cart stays in cart mode while block checkout is in checkout mode, and changing the shipping address with a draft open re-renders without a console error.

### Task 2: Rewrite the e2e specs

**Files:**
- Modify: `apps/e2e/specs/reward-redemptions.spec.ts`
- Modify: `apps/e2e/specs/redemptions.spec.ts`
- Modify: `apps/e2e/support/storefront.ts` (helpers for ticking a row and setting slider points, if both specs need them)

**Interfaces:**
- Consumes: the card's accessible names: checkboxes named by their row label, the spinbutton "Points to spend", buttons "Change" and "Update".
- Produces: `reward-redemptions.spec.ts` covers spec AC 17 (block cart tick A + Points discount 500 → $90.00 → order → −500, −500, balance 8,850 → cancel → 9,850; classic cart/checkout with Change → tick B → Update and a full refund); `redemptions.spec.ts` uses the Points discount tick and the spinbutton instead of Apply/Remove.

Steps: expand after Phase 2 lands.

### Task 3: Decision record and doc amendments

**Files:**
- Create: `docs/decisions/2026-10-22-stack-points-discount-and-rewards.md`
- Modify: `docs/decisions/2026-10-14-loyalty-discounts-as-virtual-coupons.md` (mark the exclusivity part superseded, linking the new record)
- Modify: `docs/specs/2026-10-16-redemption-design.md` (add "**Amended by:** [redeem card v2](2026-10-22-redeem-card-v2-design.md)"; update Goal, Session, Cart sync, Checkout and commit, Storefront, AC 2, 3, 5, 6, 7)
- Modify: `docs/architecture.md` (the reward-coupons bullet at line 91, the `yayloyalty_redeem_card_panels` bullet at line 99, the decision-record bullet at line 102)
- Modify: `docs/plans/2026-10-22-redeem-card-v2/README.md` (Status `done 2026-MM-DD` in the PR that finishes it)

**Interfaces:** none.

Steps: expand after Phase 2 lands. Follow [the decision-record practice](../../decisions/2026-10-04-decision-record-practice.md) for the record's format.

### Task 4: Phase exit

Run the exit criterion above, then `/yayplancheck docs/plans/2026-10-22-redeem-card-v2`.
