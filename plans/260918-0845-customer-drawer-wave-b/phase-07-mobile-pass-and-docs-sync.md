# Phase 07 — Mobile pass (5c) + docs sync

## Context Links

- Brief → [`brainstorm-260918-0845-customer-drawer-wave-b.md`](../reports/brainstorm-260918-0845-customer-drawer-wave-b.md)
- Artboard **5c**
- Wave A phase 04 — shipped the full-screen-below-`sm` behaviour this builds on
- Wave A phase 06 — shipped `LedgerTabRows`' `flex-wrap` + `order-*` stacking technique
- Wave A phase 07 — the docs-sync pattern (`docs/data-model.md`, `docs/codebase-summary.md`, `docs/project-changelog.md`)

## Overview

- Priority: P3
- Status: pending
- Last phase. A pass over everything phases 01–06 added, plus the two 5c behaviours Wave A never
  implemented, then documentation.

## Key Insights

- **This must be last.** Every phase before it adds panels and tables that need mobile treatment.
  Doing it earlier means doing it five more times.
- **Two 5c behaviours were never built**, and Wave A did not record them as gaps:
  1. *"The four stats become a 2×2 grid"* — note **four**, not five. 5c's drawer shows Balance,
     Pending, Lifetime earned, Expires. The current grid is `auto-fit/minmax(150px,1fr)`, which
     gives 3 columns at 782px and 2 below, so the count differs from the artboard rather than the
     layout. Decide: drop a stat below `sm`, or accept 5 in a 2-column grid. **Recommend accepting
     5** — hiding a figure to match an artboard's count is worse than a slightly taller grid, and
     5c's own rule (callout 5) says "nothing is silently dropped".
  2. *"The three filter controls collapse into one Filters (2) button with the active count"* —
     this is the ledger tab's filter row. Not built at all.
- **5c states the rule for every future table** (callout 5): "keep the identity, the number the
  screen is about, and one action. Everything else moves into an expandable row or a detail view —
  nothing is silently dropped, and the hidden columns are named in the expander." The six-column
  tables from phases 05 and 06 are exactly what this rule exists for.
- Docs must record what Wave B **did not** build as loudly as what it did — the forecast row, the
  cut summary strips, the still-wrong tier ordering. Otherwise they get rediscovered as bugs.

## Requirements

Functional
1. Every panel added in phases 02, 03 and 04 is usable below `sm` (full-screen drawer).
2. The six-column tables from phases 05 and 06 stack below `sm` without dropping data.
3. The ledger tab's filter controls collapse into a single "Filters (N)" trigger below `sm`, with N
   the active-filter count; the search field keeps full width (5c callout 2).
4. The header stat grid behaves sensibly at 2 columns; the decision on 4-vs-5 stats is recorded.
5. Docs updated: `docs/data-model.md`, `docs/codebase-summary.md`, `docs/project-changelog.md`.

Non-functional
- Every file < 200 lines. The filter-collapse logic is its own component, not inlined.
- No new dependency for a disclosure/sheet — the app already has `sheet.tsx` and `popover.tsx`.

## Architecture

```
apps/admin/src/pages/customers/drawer/
  tabs/LedgerTabFilters.tsx          MODIFY — collapse below `sm`
  tabs/DrawerFiltersCollapse.tsx     NEW    — the "Filters (N)" trigger + container
  (phase 02/03/04 panels)               MODIFY — spacing/stacking only
  (phase 05/06 tables)               MODIFY — mobile wrap, if not already done in-phase

docs/
  data-model.md                      MODIFY
  codebase-summary.md                MODIFY
  project-changelog.md               MODIFY
```

Prefer fixing the mobile behaviour **inside** phases 05 and 06 as they are built (both phase files
already require it) and using this phase to verify and fill gaps, rather than deferring all of it
here and rewriting five components at the end.

## Related Code Files

Create
- `apps/admin/src/pages/customers/drawer/tabs/DrawerFiltersCollapse.tsx`

Modify
- `apps/admin/src/pages/customers/drawer/tabs/LedgerTabFilters.tsx`
- Any phase 02–06 component whose mobile pass is incomplete
- `docs/data-model.md`
- `docs/codebase-summary.md`
- `docs/project-changelog.md`

Delete
- none

## Implementation Steps

1. Audit every component added in phases 01–06 at the `sm` breakpoint by reading its classes —
   there is no browser here (see Verification limits). List what is unverifiable rather than
   claiming it works.
2. `DrawerFiltersCollapse.tsx`: below `sm`, render a single trigger labelled with the active-filter
   count; above `sm`, render children inline. Use `popover.tsx` — a `Sheet` inside a `Sheet` is a
   nested-dialog problem not worth having.
3. `LedgerTabFilters.tsx`: wrap the type segmented control and the date range in it; leave search
   outside at full width (5c callout 2 is explicit that search is the exception).
4. Compute the active count from non-default filter state: type ≠ `all` and a set date range. Search
   is not counted — it is visible, so counting it would double-report.
5. Header stats: confirm the 2-column behaviour below `sm` and **record the 4-vs-5 decision** in the
   component docblock, with 5c's "nothing is silently dropped" rule as the reason.
6. Apply 5c callout 5's rule to any six-column table still overflowing: identity + the number the
   screen is about + one action stay; the rest move into an expander **that names them**.
7. `docs/data-model.md`: update the Activity log section — the row shape is now served by a real
   endpoint (still fixture-backed); add the `lots`/expiry wire block from phase 03 and the
   redemptions/referrals wire rows from 05/06, and the `negative_balance` block from phase 04,
   as designed-but-unbacked contracts.
8. `docs/codebase-summary.md`: the three new REST controllers, the new drawer components, and an
   explicit note that all four tab bodies are fixture-only with `counts` truthfully `0` on the real path.
9. `docs/project-changelog.md`: one dated Wave B entry. Include a **"Recorded, not built"** section
   naming: the 4b forecast ledger row, 5a's two disabled actions (write-off is one `adjust`
   call away — say so), 3e's summary strip and "Rewards X could use now", 3f's
   summary strip and "Revenue referred", real Anonymise logic, the tier ordering/basis bug, and
   every still-absent table.
10. Mark `plan.md`'s phase rows complete and set `status: complete` in its frontmatter.

## Todo List

- [x] Every phase 01–06 component audited at `sm`; unverifiable items listed, not assumed
- [x] `DrawerFiltersCollapse` built on `popover.tsx`, no nested dialog
- [x] Search stays full-width outside the collapse
- [x] Active count excludes search
- [x] 4-vs-5 stat decision recorded in the component docblock with its reason
- [x] Six-column tables satisfy 5c callout 5 — nothing silently dropped, hidden columns named
- [x] `docs/data-model.md` carries every new wire contract
- [x] `docs/codebase-summary.md` lists the three new controllers
- [x] `docs/project-changelog.md` has a "Recorded, not built" section
- [x] `plan.md` frontmatter and phase table updated
- [x] Every new file < 200 lines

## Mobile audit (classes read, not rendered — no browser tool)

Grepped every drawer component's `sm:`-prefixed classes. Findings:

- **Phases 02/03/04 panels already carry correct mobile classes**, built in-phase per the plan's own
  preference (`CustomerDrawerActions`/`CustomerDrawerExpiryPanel`/`CustomerDrawerNegativeActions`:
  `sm:flex-row sm:flex-wrap`; `CustomerDrawerNegativePanel`: `sm:grid-cols-2`;
  `CustomerDrawerDeletedActions`: full `sm:` row treatment). No changes needed.
- **`CustomerDrawerLooseEnds`/`CustomerDrawerDeletedBanner`/`CustomerDrawerHeader`/
  `CustomerDrawerExpiryStats`/`CustomerDrawerLotBatches` carry no `sm:` classes at all** — read
  individually and confirmed each is either a `flex-col`/`dl` block with short label/value pairs
  (stacks naturally at any width, nothing to collapse) or already uses the same `auto-fit`/
  `minmax()` grid or `overflow-x-auto`+`min-w-max` table technique the header stats and the
  six-column tables use. No gap found; no changes made.
- **Phases 05/06's six-column tables** (`RedemptionsTabRows`/`ReferralsTabRows`, plus 01's
  `ActivityLogTabRows` and Wave A's `LedgerTabRows`) already use the `flex-wrap` + `order-*` +
  `w-1/2 sm:w-auto` stacking technique below `sm` — every column renders, none hidden behind an
  expander. This satisfies 5c callout 5's rule more strongly than the rule requires (the rule
  permits moving non-essential columns into a named expander; these tables never hide anything at
  all). No changes needed.
- **The two genuinely never-built 5c behaviours** (header stat count, filter collapse) are this
  phase's actual work — see below.

**Unverifiable, needs a human check:** every claim above is "the classes say X, they follow an
established working pattern from an earlier phase," not "it renders as X." This environment has no
browser-automation tool and dev port 3030 is occupied, so none of phases 01–07's mobile layout has
been seen rendered. In particular: `DrawerFiltersCollapse`'s `Popover` positioning/sizing inside the
already-narrow full-screen mobile Sheet, and the reflow point where the five-stat grid actually
drops from 3 to 2 columns, are the two highest-risk unverified claims.

## Deviations

- **`LedgerTabFilters.tsx`'s desktop (`sm`+) grouping changed**, not just its mobile behaviour. The
  plan's step 3 asks to "wrap the type segmented control and the date range" in the new collapse
  and "leave search outside" — following that literally moves the date range from sitting beside
  Search (the original 3c grouping) to sitting beside the type control instead, at every width, not
  only below `sm`. The phase's own risk note warns the collapse "must not alter above-`sm` behaviour
  at all"; those two instructions conflict for this component, since the two collapsible controls
  (type + date range) don't already share a row with each other in the original design — only one
  of them (date range) shares a row with search. Resolved in favour of following the plan's explicit
  grouping instruction, and documented the tradeoff in the component's own docblock rather than
  silently changing the layout. Functionally nothing is lost — search is still full width, both
  other controls are still reachable and un-collapsed at `sm`+ — but the visual grouping did change
  and needs a human's eyes, not just `tsc`/`eslint`/`vite build`, to confirm it still reads well.
- **"Filters (N)" label lives as a component-local `__()`/`sprintf()` call in
  `DrawerFiltersCollapse.tsx`, not in `customer-drawer.copy.ts`.** That shared file is at its
  200-line hard cap (confirmed before this phase started); routing one more short label through a
  new dedicated copy file for a single string felt like more indirection than the string warrants.
  Followed the precedent `DrawerBackLink.tsx` already sets (a trivial, component-local `__()` call)
  rather than expanding the copy-file-per-feature pattern further.
- **`sprintf()`'s TypeScript overloads require string arguments even for `%s`-only formats** —
  `activeCount`/`campaign.window_days` (a phase 06 leftover, already fixed) both needed `String()`
  wrapping to satisfy `@wordpress/i18n`'s types; `%s` accepts a number at runtime but not at the
  type level. Same fix pattern in both places.

## Success Criteria

- No horizontal page scroll anywhere in the drawer below `sm`.
- No data is hidden without being named.
- Docs describe the drawer as it actually is: complete-looking, fixture-backed, with the gaps named.
- `composer lint`, `composer test`, `tsc --noEmit`, `vite build` clean.

## Risk Assessment

- **This phase is where unverifiable mobile claims accumulate.** Wave A's scroll bug (phase 04) was
  exactly this: a layout defect invisible to `tsc`, `eslint` and `vite build`, found only by the
  user clicking. Expect the same class of bug here and say so in the report rather than implying
  the pass was verified.
- **Filter collapse changes a shipped, working component.** `LedgerTabFilters` is in use; the
  collapse must not alter above-`sm` behaviour at all.
- **Docs drift** if written from the plan rather than the code. Write them last, from the diff.
- **Temptation to fix the tier ordering while in the docs.** It stays wrong this wave — record it,
  do not change it.

## Security Considerations

- Presentation only; no new endpoint, capability or data path.
- Docs must not record any credential, store URL or real customer data from the dev server.
- The changelog must not imply Wave B shipped data features it did not — an inaccurate changelog is
  how an unimplemented capability gets assumed present later.

## Next Steps

- Wave C decides the real-data work Wave B deferred: ledger filter SQL, `type_counts` at scale,
  `customers.deleted_at`, the Export job, and the Activity log table.
- The tier ordering and tier basis bug is still open and now has three independent design citations.

## Verification limits

No browser-automation tool exists here and dev port 3030 is occupied, so **the mobile pass cannot be
visually verified at all** — which is this phase's central weakness. Every claim must be phrased as
"the classes say X", not "it renders as X", and the phase report must list the breakpoint behaviours
a human still needs to check.

`composer lint`, `composer test`, `tsc --noEmit` (same 4 pre-existing unrelated errors as every
prior phase), ESLint, `vitest` (63/63), and `vite build` are all clean; none of them can catch a
layout defect the way Wave A's own horizontal-scroll bug proved (found only by a human clicking,
invisible to every automated check run here).

## Code Review

Spawned `code-reviewer`, scoped to the 3 touched code files (`DrawerFiltersCollapse.tsx`,
`LedgerTabFilters.tsx`, `CustomerDrawerStats.tsx`) plus the 4 docs files as diff-only checks. No
high/critical findings. One low-severity fix applied, one medium finding stays open as a flagged
risk rather than something code can resolve:

- **LOW, fixed** — `DrawerFiltersCollapse.tsx`'s docblock overstated its kinship with the tables'
  mobile technique: it claimed the same `order-*`/`w-1/2` CSS-reorder approach, but it actually
  mounts `children` TWICE (once in the popover, once inline), running two live
  `Segmented`/`DateRangePicker` instances simultaneously (one `display:none`) rather than
  CSS-reordering one set of nodes. Functionally safe (confirmed: no name collisions, the hidden
  copy correctly drops from the tab order and a11y tree) but a heavier technique than described.
  Corrected the docblock and named the one real edge case it introduces: the hidden copy's own
  nested date-range calendar popover (portalled to `document.body`) can be left open and orphaned
  if the viewport crosses `sm` while it's open — a window-resize/tablet-rotation scenario, not a
  phone one, noted rather than handled.
- **MEDIUM, open — flagged, not fixed by code.** `LedgerTabFilters.tsx`'s desktop (`sm`+) layout
  changed, not just its mobile behaviour (see this file's own Deviations section above) — the
  reviewer independently confirmed the conflict between the plan's two instructions is real (the
  original 3c grouping only ever paired date-range with search, never with type, so satisfying
  "collapse type+date into one trigger below `sm`" while leaving `sm`+ pixel-identical would need a
  second, differently-arranged, non-shared JSX tree — real duplication for a purely cosmetic
  desktop win) and that resolving in favour of the explicit collapse instruction was the reasonable
  call. The reviewer's own unresolved question — "has a human actually looked at the regrouped
  desktop layout?" — has no work I can do to answer inside this environment (no browser-automation
  tool, dev port 3030 occupied, and this session is executing Wave B's phases without pausing for
  user confirmation between them). Recorded here explicitly so it surfaces in the phase report
  rather than being silently treated as resolved: **the `LedgerTabFilters.tsx` desktop grouping
  change needs a human to actually look at it** before this is treated as fully verified, same as
  every other "classes say X" claim in this phase's Mobile audit section above.

Everything else the reviewer checked came back clean: the `sm:hidden`/`sm:flex` CSS toggle is
mutually exclusive at every width; Popover-in-Sheet (and the further Popover-in-Popover for the
date-range calendar) is a supported, non-modal-in-non-modal Radix pattern with no focus-trap
conflict; `PopoverContent`'s `w-72` fits a 320px viewport with Radix's own collision-avoidance
handling the rest; `activeCount`'s conditions were cross-checked against `LedgerTab.tsx`'s actual
default values (`type: 'all'`, `range: undefined`) and correctly report / don't misreport; the
`CustomerDrawerStats.tsx` change is confirmed docblock-only, no logic delta; and every docs claim
sampled against the actual PHP source (schema fields, fixture dispatch list, the `scenario=''`
400 behaviour) checked out accurate, including that nothing genuinely shipped this wave is
mislabelled "not built" in the changelog.

Re-verified after the fix: `tsc --noEmit` clean (same 4 pre-existing unrelated errors), ESLint
clean on the touched file.
