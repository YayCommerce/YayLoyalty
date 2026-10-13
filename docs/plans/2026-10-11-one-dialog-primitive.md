# One Dialog Primitive Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the backlog item linked below before starting; the plan argues from it.

**Status:** done 2026-10-11
**Goal:** Every SPA dialog renders through `apps/admin/src/components/ui/custom/dialog.tsx`, so the unsaved-changes dialog stacks above editor dialogs.
**Spec:** none, planned from `docs/backlog/2026-10-11-two-dialog-primitives.md`
**Architecture:** Move the two remaining importers of the base `components/ui/dialog.tsx` onto the custom wrapper and keep the base as the vendored file the wrapper copies. No z-index values change. Once both dialogs share the custom layers (overlay `z-100001`, content `z-100002`), the one opened later sits on top because Radix appends its portal later, and the unsaved dialog always opens after the editor dialog.
**Touches:** `apps/admin/src/components/ui/custom/unsaved-changed-dialog.tsx` (shared: rendered by `GeneralSettingsPage`, `DesignSettingsPage`, `EmailsSettingsPage`, `CampaignEditor`, `RewardEditor` through `useEditorSave`'s `leaveDialogProps`), `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx`
**Tests:** none. CLAUDE.md's test rule doesn't cover it: no money or ledger logic, and Vitest runs `.ts` files in `node` only, with no DOM. A browser check stands in.

## Requirements

- **Goal:** An unsaved-changes dialog opened over an editor dialog renders above it and can be clicked.
- **Out of scope:** `components/ui/dialog.tsx` stays as is. Other layer mismatches, such as `YayLoyaltyToolTip` (`components/ui/custom/tooltip.tsx:25`, z 100000) sitting below custom dialogs, are out of scope; neither migrated dialog uses it.
- **Acceptance criteria:**
  1. `git grep -nE "components/ui/dialog'|from '\.\./dialog'" -- apps/admin/src` prints nothing.
  2. In the campaign editor, with the form dirty and "Calculate from spend" open, the browser Back button shows "Unsaved Changes" above the calculator, and its buttons work.
- **Decided with the user:** the dead `bw:max-w-md` becomes `sm:max-w-md` (the narrower width its author meant), and `bw:border-b-0` is dropped. No tests.

## Global constraints

- Run from the repo root on Node 22 (`nvm use`), with pnpm only.
- Never hand-edit `assets/dist/`, `vendor/`, `vendor-prefixed/` or `node_modules/`.
- Stage files by name. `composer.json` may be modified by the admin-shell sync; leave it out.
- Commit messages follow Conventional Commits and end with the trailers CLAUDE.md names.

## Review focus

- Setup wizard step 4: the colour picker's Popover (`components/ui/popover.tsx:32`, z 100002) ties with the custom content. It must still open above the wizard. Checked in Task 1 Step 4.
- The WordPress admin bar (`#wpadminbar`, z 99999): the wizard must still cover it. Checked in Task 1 Step 4.
- Narrow screens: the custom `DialogHeader` drops `text-center`, so the unsaved dialog's text is left-aligned at every width. That is intended. Checked in Task 1 Step 4.

---

### Task 1: Move both dialogs onto the custom wrapper

**Files:**
- Modify: `apps/admin/src/components/ui/custom/unsaved-changed-dialog.tsx:4,27-28`
- Modify: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx:10,62-67`
- Delete: `docs/backlog/2026-10-11-two-dialog-primitives.md`

**Interfaces:**
- Consumes: `Dialog`, `DialogContent` (`showCloseButton`), `DialogDescription`, `DialogFooter`, `DialogHeader` and `DialogTitle` from `components/ui/custom/dialog.tsx`
- Produces: nothing new; `UnsavedChangeDialogProps` is unchanged

- [x] **Step 1: Repoint `UnsavedChangeDialog`**

Import from `'./dialog'` instead of `'../dialog'`. `DialogContent` gets `className="sm:max-w-md"`, which `cn` merges over the wrapper's `sm:max-w-lg`. Drop `className` from `DialogHeader`.

- [x] **Step 2: Repoint `SetupWizardModal`**

Import `Dialog`, `DialogContent`, `DialogDescription` and `DialogTitle` from `@/components/ui/custom/dialog`. Remove `overflow-y-auto` from `DialogContent`'s `className`, since the wrapper applies it, and `gap-0`, which does nothing on a grid with one child. Keep the rest: `showCloseButton={false}`, both `preventDefault` handlers, and `max-h-[calc(100vh-4rem)] p-0 sm:max-w-225`.

- [x] **Step 3: Confirm no importer of the base remains**

Run: `git grep -nE "components/ui/dialog'|from '\.\./dialog'" -- apps/admin/src`
Expected: no output

- [x] **Step 4: Verify in wp-admin**

Run `./scripts/run.sh dev pro`, then open wp-admin → YayLoyalty.

1. Open a purchase-trigger campaign in the editor and change any field. Click "Calculate from spend", then press the browser Back button. Expected: "Unsaved Changes" renders above the calculator, and "Discard Changes" works.
2. Settings → General: change a field, then click another menu item. Expected: the unsaved dialog is `max-w-md` (448px) wide, with left-aligned text. At a 375px viewport it fits, with no horizontal scroll.
3. Clear the setup-completed flag so the wizard shows. Expected: it covers the admin bar. On step 4 the colour picker's popover opens above the wizard. "Exit setup" closes it, and Escape and outside clicks do not.

- [x] **Step 5: Run the SPA checks**

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` and `pnpm --filter @yayloyalty/admin-page lint`
Expected: both already fail on `dev/edd`, so there should be no errors in either changed file and no new errors anywhere.

- [x] **Step 6: Close the backlog item**

Tick both boxes in `docs/backlog/2026-10-11-two-dialog-primitives.md`, with Step 3's and Step 4's results as evidence. Every box is then ticked, so delete the file.

- [x] **Step 7: Commit**

Stage: `apps/admin/src/components/ui/custom/unsaved-changed-dialog.tsx`, `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx`, `docs/backlog/2026-10-11-two-dialog-primitives.md`, `docs/plans/2026-10-11-one-dialog-primitive.md` (Status set to `done YYYY-MM-DD`)
Message: `refactor(admin): render every dialog through the custom dialog`
