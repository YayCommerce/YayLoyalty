# Clean up the Rewards page

**Status:** done 2026-10-07
**Branch:** `trace/feat/rewards-page-cleanup`, from `origin/dev/edd`
**Tests:** none (copy and markup only). Verify by hand.

## Context

The Rewards list page (Pro, `apps/admin/src/pro/rewards/RewardTable.tsx`) carries more chrome than it needs. While the catalog is empty (`screen.isInitCase`), it shows a "slider is live" banner, an empty state with a long description, and a "Start with one of these" row of template cards that repeats the Add reward flow. Badges are customised with `className="uppercase"` instead of using the UI kit's Badge as it is. The goal is a quieter page, with the same Add reward entry point.

## Changes

1. **Remove "Start with one of these":** in `pro/rewards/RewardCatalogEmptyState.tsx`, delete `RewardCatalogTemplateCards` along with its now-unused imports (`useNavigate`, `Badge`, `Card`/`CardContent`, `templateExample`, `REWARD_TEMPLATE_ORDER`, `templateTitle`, `typeLabel`). Trim the file docblock to one line. In `RewardTable.tsx`, render only `<RewardCatalogEmptyState />` in the init case and drop the import. `templateExample`, `REWARD_TEMPLATE_ORDER` and `templateTitle` stay, because `add/RewardTemplatePicker.tsx` and the editor still use them.
2. **Remove the slider banner:** in `RewardTable.tsx`, the info `Alert` renders only when `!screen.isInitCase`. It keeps the non-init sentence ("The customer picks a reward…"), which the user didn't ask to remove. The Alert's init-only classes and the "Check the earning rate" button go. Delete `sliderLiveLabel` from `pro/rewards/reward-labels.ts`, plus any helper that only it used. Check `numberConfig`, `pointsPhrase` and `resolveRewardDisplayStatus` for other callers before removing them.
3. **Remove the Default badge (rewards table only):** in `RewardTable.tsx`'s `RewardRow`, delete the `Default` `<Badge>`, and keep the lock icon and its "cannot be archived" tooltip. Leave the badge in the editor header (`editor/RewardEditor.tsx`) as it is.
4. **Badges from the UI kit as-is:** on the Rewards page, the Type column badge becomes `<Badge variant="outline">{typeLabel(reward.type)}</Badge>`, with no `className="uppercase"`. Status badges already use the kit's variants through `CampaignStatusBadge`, so they stay. The template-card badge disappears with change 1.
5. **Shorter empty-state description:** `RewardCatalogEmptyState` gets "Customers spend points on these for a coupon to use later."

**Out of scope:** the Add reward picker (`add/RewardTemplatePicker.tsx`) on `/rewards/new`. It also has an `outline uppercase` badge, but it's a different page.

## Steps

1. Run `git switch --no-track -c trace/feat/rewards-page-cleanup origin/dev/edd`. The uncommitted `CampaignRestController.php` fix moves with the working tree, and stays unstaged.
2. Save this plan as `docs/plans/2026-10-07-rewards-page-cleanup.md`.
3. Make changes 1–5.
4. Commit only when asked: `refactor(rewards): trim the rewards page's empty state, banner and badges`.

## Verification

- Run eslint and prettier on the touched files (`RewardTable.tsx`, `RewardCatalogEmptyState.tsx`, `reward-labels.ts`). Run `tsc --noEmit` filtered to `pro/rewards`, which should show no new errors. Run Vitest, which should stay at 64 passed.
- Run `grep -rn "sliderLiveLabel\|RewardCatalogTemplateCards" apps/admin/src`. It should find nothing.
- In the browser at `#/rewards`:
  1. With no catalog rewards, there's no banner and no template cards, and the empty state shows the new one-line description. Add reward still opens `/rewards/new`.
  2. With catalog rewards, the "customer picks a reward" alert still shows.
  3. The built-in row has no Default badge, and still has its lock icon and tooltip. The Type badges are outline and sentence case.
  4. The built-in reward's editor header still shows Default.
