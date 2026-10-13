# No lint rule catches `toLocaleString()` or a relative import that leaves its folder

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the `/simplify` altitude review on branch `trace/chore/clear-small-backlog`, 2026-10-12, after two backlog items for exactly these drifts were fixed by hand.

## Today

`apps/admin/eslint.config.js` sets no rule for either convention:

- [docs/conventions.md § Money](../conventions.md) says numbers use the store's separators through `formatStoreNumber`, `formatStoreDecimal`, `formatPercent` or `formatPoints` (`apps/admin/src/lib/helpers/format.helper.ts`), never `toLocaleString()`. The only remaining calls are in vendored UI kit files: `formatMonthDropdown` in `apps/admin/src/components/ui/calendar.tsx`, and the tooltip value in `apps/admin/src/components/ui/chart.tsx`.
- `.claude/rules/react-admin.md` says imports are relative inside a page or Pro feature folder, and use `@/` for anything outside it. Climbing imports inside one page are allowed: `AdjustPointsBulkPanels.tsx` imports `'../../customers-page.filter-rows'` within `apps/admin/src/pages/customers/`.

## Why it matters

Both rules are kept only by review, and both have drifted before: three admin strings used `toLocaleString()`, and two Pro dialogs imported `pro/shared` by a relative path. A merchant whose store separators differ from their browser locale sees mixed number formats. A relative path that leaves its feature breaks when a folder moves.

## Suggested fix

- In `apps/admin/eslint.config.js`, add a `no-restricted-syntax` selector for calls to `.toLocaleString`, scoped to `src/**` with `src/components/ui/**` ignored.
- For imports, a plain `no-restricted-imports` pattern such as `../../*` would flag the allowed climbs inside a page. Use a rule that knows folder boundaries instead, for example `eslint-plugin-boundaries`, or per-folder `no-restricted-imports` overrides for `src/pro/*/` and `src/pages/*/`.

This changes the lint config, so it was kept out of the backlog sweep.

## Done when

- [ ] From `apps/admin`, `echo "export const s = (1).toLocaleString();" | node node_modules/eslint/bin/eslint.js --stdin --stdin-filename src/pages/x.ts` reports an error.
- [ ] From `apps/admin`, `echo "export { ArchiveOrDeleteDialog } from '../../shared/ArchiveOrDeleteDialog';" | node node_modules/eslint/bin/eslint.js --stdin --stdin-filename src/pro/rewards/archive/x.ts` reports an error.
- [ ] `node node_modules/eslint/bin/eslint.js src/components/ui/calendar.tsx src/components/ui/chart.tsx src/pages/customers/components/adjust-points/AdjustPointsBulkPanels.tsx`, run from `apps/admin`, reports no error from either new rule.
