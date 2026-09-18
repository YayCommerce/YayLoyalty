# Brainstorm — F09 Customer Drawer (Wave B)

Date: 2026-09-18 · Branch: `paul/feat/customer-drawer` · Design: `YayLoyalty_Customer_Drawer.dc.html` (12 artboards)
Predecessor: [`brainstorm-260917-1449-customer-drawer.md`](brainstorm-260917-1449-customer-drawer.md) · Wave A plan: [`plans/260917-1504-customer-drawer-wave-a/`](../260917-1504-customer-drawer-wave-a/plan.md) (complete)

---

## Problem statement

Wave A shipped the drawer shell, header, stats, actions row, 4-tab bar and a complete Points ledger
tab. Six artboards remain undrawn in code: **3d** (Activity log), **3e** (Redemptions), **3f**
(Referrals), **4a** (deleted account), **4b** (expiring points), **5a** (negative-balance
explanation). Wave B decides which of those get built, in what order, and how deep.

> **Correction, 2026-09-18.** This report first listed five artboards and omitted **5a**. Wave A's
> `plan.md` had deferred it correctly ("Out (later waves): … negative-balance explanation panel
> (5a)"), but it was dropped when this report's artboard list was re-derived from the design canvas.
> It is easy to miss because the design labels it **F23**, not F09 — "Negative balance, explained in
> the drawer · Ships 1.0 · Size S · Depends on F09 · FREE + PRO", with the note "Moved here from the
> Dashboard canvas". It is a separate feature renting space on the drawer canvas. Now phase 04.

**Correction to the Wave A hand-off.** The 12-item Wave B list produced at the end of Wave A was
derived from `plan.md`'s one-line "Out (later waves)" clause. That clause compresses five full
artboards into five bullets. On a full read of the design, **at least three of those bullets are
each larger than the entire Points ledger tab built in Wave A**. Anyone planning from the bullets
alone will under-size this wave by a wide margin. Specifics in "What the artboards actually
contain" below.

## Decisions taken

| Question | Decision |
|---|---|
| Wave B goal | **Keep building UI on fixtures.** Real data (SQL filters, `type_counts`, `deleted_at` column, Export job, Reset expiry endpoint) is explicitly NOT this wave. See "Accepted risk" |
| Build order | **3d → 4a/4b/5a → 3e/3f** (5a added after the first draft — see the correction above) |
| Free vs Pro | **Keep all four tabs in both builds.** Free wraps Redemptions/Referrals with the existing `ProGate`: `<ProGate inline />` badge beside the tab label, `<ProGate>` overlay over the body |
| 3e/3f depth | **Table only.** Drop the summary strips, "Rewards X could use now", and "Revenue referred" |
| Forecast ledger row (4b) | **Not built.** Ledger stays "written history only" |
| Zero-point ledger rows | **Not allowed.** Reminder emails live in the Activity log, per 3d |
| "Anonymise the record" (4a) | **Disabled button only.** Recorded as a separate feature, no GDPR logic in F09 |
| Tier ordering bug | **Left as-is** until a real tiers table. Recorded loudly below |
| 5c mobile pass | **End of Wave B**, once every new panel exists |
| Activity log paging | **Load more**, not paged (3d: "7 of 24 events") — opposite of the ledger, deliberately |

## What the artboards actually contain

Read this before sizing anything.

**3d Activity log** — columns Date (*with time*), Event (title + structured sub-line), By (actor +
`STAFF ONLY` badge + envelope glyph on email rows). Filter `Show: All / Admin actions / Tier changes
/ Expiry / Emails`. Load more. Footer note: *"Points movements are not repeated here — they live in
the points ledger with their running balance."*

**3e Redemptions** — summary strip (Points redeemed *net of refunds* / Discount given *$X across N
orders* / Redemption rate *% of lifetime earned*), two filters, "See these rows in the ledger" link,
**6-column** table, a `Points returned` status for refunded redemptions (positive points, **excluded
from the totals** — which is why the strip's figure does not equal the rows summed), and a
"Rewards X could use now" line requiring the reward catalog + tier gating.

**3f Referrals** — summary strip (Referred *N completed · M pending* / Points earned *% of lifetime*
/ **Revenue referred** *first orders only*), referral link + Copy + campaign description + settings
link, **6-column** table showing both sides of the reward (points to referrer, coupon to friend,
whether the coupon was used), pending rows naming what is missing *and when the window closes*.

**4a Deleted account** — banner ("This WordPress user was deleted on 8 September") + why the ledger
is kept, four stats, a **"What can still be done"** panel (Adjust/Reset disabled; Export ledger and
**Anonymise the record** live), and a **"Loose ends this leaves"** panel (unused coupons now void /
referrals still credited / counted in the member total? no). Wave A shipped only the header badge.

**4b Expiring** — warning banner stating the loss in money *and* proportion ("$63.00 … 64% of his
balance") plus the reminder schedule, **three actions** (Reset his expiry / **Exempt him from
expiry** / **Send the reminder now**), four stats with currency equivalents, a Pro FIFO lot-batch
table, and a forecast ledger row.

**5a Negative balance (F23)** — a "Why this balance is negative" panel: three numbered facts naming
the two orders involved, then two labelled columns ("What Tom sees" — 0 points, no debt, no message;
"What happens next" — the first points earned clear the deficit, nothing expires while negative, it
is not counted in liability), then two actions with no default (*Write off the negative balance* /
*Leave as is*) and the consequence sentence. The causing ledger row is visually marked below. Its
callout asserts a negative balance *always* has the shape earned → spent → reversed, which is false
for a manual adjustment pushed below zero.

**5c mobile** — "the four stats become a 2×2 grid" (four, not five) and "the three filter controls
collapse into one *Filters (2)* button". Neither is implemented.

## Three contradictions found in the design

1. **Free/Pro split.** 1e (Free) draws **two** tabs, two actions, no tier card. 1f (Pro) draws four
   tabs, three actions, the tier card. 3e's callout is explicit: *"Pro only … The tab appears when
   the reward catalog does."* Wave A locked the opposite ("all four tabs, both builds, no
   `IS_PRO_BUILD` branch"). **Resolved:** keep four tabs, gate the bodies with `ProGate`. That
   satisfies the design's intent (Free cannot use Pro features) without the branch Wave A avoided —
   `ProGate` already encapsulates `IS_PRO_BUILD` and already ships in both builds.
2. **Zero-point ledger rows.** 3d says points movements are never repeated in the Activity log and
   puts emails there. 4b draws *"Expiry reminder sent · 0 points"* as a **ledger** row.
   **Resolved:** 3d wins. The ledger only ever holds real point movements.
3. **Tier ordering.** 3d: *"Tier changed: **Explorer → Summit** · reached 5,000 lifetime points"*.
   1f: *"Summit · 5,000 / Alpine · 20,000"*. Design order is **Explorer → Summit → Alpine (top)**,
   measured on **lifetime earned** — 1f's callout says why: *"so redeeming points never demotes a
   member"*. The codebase's `TIER_SAMPLE_THRESHOLDS` is **Alpine → Summit → Explorer on balance** —
   Alpine and Explorer swapped, and the wrong basis. This drives both the drawer tier card and the
   **Customers list Tier column**. **Deliberately not fixed** this wave (it is throw-away sample
   data). Consequence accepted: every screenshot and demo until then shows the wrong tier order.

## Evaluated approaches

| Approach | Pro | Con | Verdict |
|---|---|---|---|
| Swap fixtures for real data first | Drawer becomes usable by merchants; no new unreviewable UI | Leaves five artboards undrawn; slowest to a complete-looking screen | Rejected |
| **Keep building UI on fixtures** | Whole design reviewable before any data work; fixture for 3d already written | Fixture↔real gap widens; UI unreviewable against reality | **Chosen** |
| Data foundation first (all missing tables) | Unblocks every tab at once | Longest time to anything visible; designs tables before their UI is settled | Rejected |
| One tab end-to-end (DB → endpoint → UI) | Proves the whole chain once | Slower than either pure track; no complete screen either way | Rejected |

## Accepted risk — stated plainly

The Wave A brainstorm warned: *"Three tabs are 1–2 phases out. Redemptions, Referrals and lot
batches depend on unimplemented tables. Building them now means UI sitting unreviewable against
reality."* **The chosen direction goes directly against that warning.** After Wave B the drawer will
look complete and still be ~75% fixture; a merchant on a real store sees empty tabs and dead
buttons. Deferring real data also defers the two decisions that need the most lead time: the
`GROUP BY` cost of `type_counts` at 250k members, and the Activity log table design.

This is the user's call and is recorded as accepted, not re-litigated. The mitigation is that every
scenario still parses through the same Zod schema as the real path, so the swap remains an input
change — the discipline Wave A established must not slip in Wave B.

## Proposed phases

| # | Phase | Notes |
|---|---|---|
| 01 | Activity log — contract, Zod, endpoint, UI | `DrawerActivity.php` (104 lines) **already exists and is dead code** — nothing routes to it. Needs `GET /customers/{id}/activity`, a Zod mirror, and the 3d UI (rows, grouped filter, Load more, `STAFF ONLY` badge, footer note). Highest visibility: second tab, present in both builds |
| 02 | 4a deleted-account panels | Banner + "What can still be done" (Anonymise disabled) + "Loose ends". No new endpoint — the `deleted` scenario already returns what is needed; confirm at plan time |
| 03 | 4b expiring panels | Warning banner, three actions (two new and disabled), four stats with currency, Pro FIFO lot table. **New fixture data required** (lot batches) — the `expiring` scenario does not carry them today. No forecast row |
| 04 | 5a negative-balance explanation | Third and last header-level panel, same composition as 02/03. Free AND Pro — no `ProGate`. Both actions disabled. The `negative` scenario already resolves, so no new endpoint |
| 05 | 3e Redemptions table | New fixture + endpoint + 6-column table + `Points returned` status. `ProGate` in Free. No summary strip |
| 06 | 3f Referrals table | New fixture + endpoint + 6-column table + pending-window copy. `ProGate` in Free. No "Revenue referred" |
| 07 | 5c mobile pass + docs sync | 2×2 stat grid, "Filters (N)" collapse, every new panel from 02–06 |

Order rationale: 3d first because its fixture is already written but unrendered, and it is one of
only two gaps a Free user sees (5a is the other). 4a/4b/5a next because they are panels over
scenarios that already resolve and share one composition. 3e/3f last because each needs a fixture,
an endpoint and a schema built from nothing.

**5a is where the wave's own rule bites hardest.** "Write off the negative balance" is the one action
in all of Wave B that could be implemented today — it is an adjustment of `+|balance|` and
`POST /customers/{id}/adjust` already works and already names the acting admin. It stays disabled,
to keep the wave honestly UI-on-fixtures, and the feasibility is recorded in the phase file so Wave C
does not rediscover it.

## Implementation considerations

- **`counts` already lies.** `CustomerRestController.php:239-241` hardcodes `activity`/`redemptions`/
  `referrals` to `0` on the real path while the fixture returns non-zero. Once bodies exist, a tab
  labelled `0` with rows inside is a visible bug. Decide per phase whether the count comes from the
  same source as the body.
- **200-line cap.** The drawer fixture is already five files / 527 lines. Phases 03–05 each add a
  fixture; split from the start, as Wave A did.
- **Every new payload needs a Zod mirror** parsed identically on the fixture and real paths. This is
  the single discipline that keeps the eventual data swap an input change.
- **Activity log filter groups overlap on purpose** — `expiring_soon_email_sent` belongs to both
  *Expiry* and *Emails*. It is a lens ("what am I looking for"), not a partition. Proposed mapping:
  Admin actions = `points_adjusted` + `expiry_reset` (+ `tier_changed` when manually assigned);
  Tier changes = `tier_changed`; Expiry = `expiry_reset` + `expiring_soon_email_sent`;
  Emails = `balance_email_sent` + `expiring_soon_email_sent`.
- **`staff_only`** is read as "this row quotes an internal admin note" — it appears only on the two
  rows carrying a quoted note. Not a signal that a customer-facing view is planned.
- **Figures are never abbreviated** anywhere in the drawer (`formatPoints` only) — unchanged.
- **Prose lives in `customer-drawer.copy.ts`.** 4a and 4b carry the heaviest prose in the whole
  design; the fixture must emit structured fields only.

## Success criteria

- All five remaining artboards render from `?scenario=` on the dev server.
- No tab renders a populated body whose count says zero.
- Free build: Redemptions/Referrals show the upgrade surface, not an empty state.
- Swapping any tab to real data would touch the controller only — no SPA change.
- Every file under 200 lines.
- The ledger contains only real point movements — no forecast rows, no zero-point rows.

## Next steps

1. Plan Wave B from this report (`/plan`), phases as proposed above.
2. Before phase 03, confirm what the `expiring` scenario returns today versus what the lot table needs.
3. Before phase 01, decide where `counts.activity` comes from once the tab has a body.

## Unresolved

- Whether `counts.*` should come from the fixture or stay `0` on the real path per tab — deferred to
  each phase rather than decided globally.
- 5a asserts "a negative balance ALWAYS has this shape — earned, spent, then the earning was
  reversed". That is false for a manual adjustment pushed below zero. Phase 04 carries a `shape`
  discriminator so the panel degrades to a generic explanation; whether the real path can ever
  assemble the three-step story by walking the ledger is left to Wave C.
- Lot-batch fixture shape for 4b (the Pro FIFO model) — not designed; `lots`/`lot_allocations` are
  unimplemented (Pro 1.0).
- Tier ordering and tier basis (lifetime earned vs balance) stay wrong until a real tiers table
  exists. Carried forward from Wave A, now with three independent design citations confirming it.
- Still open from Wave A: does `CampaignRestController::paginate_filtered()` return archived
  campaigns? Determines whether the SPA-side campaign-name lookup covers old ledger rows.
- Still open from Wave A: `CustomersAdjustPointsDialog` does not reset its state when closed by the
  parent.
