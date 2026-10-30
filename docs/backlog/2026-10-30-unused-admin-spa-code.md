# The admin SPA ships files, an export and preview state that nothing uses

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

No file under `apps/admin/src` imports these, checked with `git grep` on each module path and exported name:

- `apps/admin/src/pages/PagePlaceholder.tsx` (`PagePlaceholder`)
- `apps/admin/src/components/ui/custom/nps-scale.tsx` (`NpsScale`)
- `apps/admin/src/components/ui/custom/shop-skeleton.tsx` (`ProductImageSkeleton`, `AddToCartSkeleton`)
- `apps/admin/src/components/ui/image-picker/index.tsx` (`ImagePicker`)
- `apps/admin/src/components/icons/DeleteIcon.tsx` (`DeleteIcon`)
- `apps/admin/src/components/ui/custom/toggle-group.tsx` (`ToggleGroup`, `ToggleGroupItem`). It is the only importer of `apps/admin/src/components/ui/custom/toggle.tsx`, which is the only importer of the `@radix-ui/react-toggle` dependency. `components/ui/segmented.tsx` uses `@radix-ui/react-toggle-group` directly, so that dependency stays.

`CAMPAIGN_CONFIG_FIELDS` in `apps/admin/src/lib/schema/campaign.schema.ts` is exported and never imported.

In `apps/admin/src/pro/campaigns/CampaignTable.tsx`, `CampaignTable()` declares `const [newStore] = useState(false)` and `const [noLiveCampaign] = useState(false)` with no setter, so the branches they guard never render, along with the helper `pauseIfActivePurchase()`. The docblock calls them dev-only preview toggles.

## Why it matters

Dead modules are read, renamed and type-checked as if they mattered: this review spent time on them. `CAMPAIGN_CONFIG_FIELDS` claims to mirror `Campaign::config_schema()` and would drift unnoticed. The two always-false flags hide which empty states the campaigns table really has.

## Suggested fix

Delete the files, `CAMPAIGN_CONFIG_FIELDS` and the `@radix-ui/react-toggle` dependency. Remove `newStore`, `noLiveCampaign`, the branches they guard and `pauseIfActivePurchase()`, unless the "new store" empty state should be wired to real data, in which case that is a feature for a plan. Left out of the rename because deleting the preview branches removes UI paths.

## Done when

- [ ] `git ls-files apps/admin/src/pages/PagePlaceholder.tsx` prints nothing.
- [ ] `git ls-files apps/admin/src/components/ui/custom/nps-scale.tsx` prints nothing.
- [ ] `git ls-files apps/admin/src/components/ui/custom/shop-skeleton.tsx` prints nothing.
- [ ] `git ls-files apps/admin/src/components/ui/image-picker` prints nothing.
- [ ] `git ls-files apps/admin/src/components/icons/DeleteIcon.tsx` prints nothing.
- [ ] `git ls-files apps/admin/src/components/ui/custom/toggle-group.tsx apps/admin/src/components/ui/custom/toggle.tsx` prints nothing.
- [ ] `apps/admin/package.json` has no `@radix-ui/react-toggle` dependency.
- [ ] `git grep -n CAMPAIGN_CONFIG_FIELDS -- apps` returns nothing.
- [ ] `CampaignTable()` declares no `newStore` or `noLiveCampaign` state.
- [ ] `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` reports no new errors.
