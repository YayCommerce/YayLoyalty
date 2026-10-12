# The shared archive dialog is imported by a relative path

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-12 docs-sync audit of `dev/edd` @ `656cbbf`, branch `trace/docs/sync-docs-with-code-2`.

## Today

`.claude/rules/react-admin.md` says imports are relative inside a page or Pro feature folder, and use `@/` for anything outside it. `ArchiveOrDeleteDialog` lives in `apps/admin/src/pro/shared/`, outside both features that use it, yet `RewardArchiveDialog.tsx` (`apps/admin/src/pro/rewards/archive/`) and `CampaignArchiveDialog.tsx` (`apps/admin/src/pro/campaigns/archive/`) import it as `'../../shared/ArchiveOrDeleteDialog'`. Other cross-folder Pro imports, such as in `RewardEditor.tsx`, use `@/pro/…`.

## Why it matters

Relative paths that climb out of a feature break when a folder moves, and they hide that a file depends on code outside its feature.

## Suggested fix

Import it as `@/pro/shared/ArchiveOrDeleteDialog` in both files.

## Done when

- [ ] `git grep -n "'\.\./\.\./shared/" -- apps/admin/src/pro` prints nothing.
