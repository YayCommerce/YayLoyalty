# Two dialog primitives

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-11 duplicate-logic sweep (branch `trace/refactor/share-spa-logic`), which left the dialogs unmerged.

## Today

The SPA has two dialog primitives:

- `apps/admin/src/components/ui/dialog.tsx`, the upstream one. `SetupWizardModal` and `UnsavedChangeDialog` use it. Its overlay and content sit at `z-99999`.
- `apps/admin/src/components/ui/custom/dialog.tsx`, the wrapper most dialogs use. Its overlay is `z-100001` and its content `z-100002`. It adds `forwardRef` on Trigger and Content, a `DialogCloseIcon` export, different padding and rounding, and centred header text.

## Why it matters

The two stack at different levels. An unsaved-changes dialog opened from inside a custom dialog renders beneath it, and every new dialog has to pick one.

## Suggested fix

Move `UnsavedChangeDialog` and `SetupWizardModal` onto `custom/dialog.tsx`. Check that the WordPress admin bar and the wizard's own layers still stack correctly, then delete nothing upstream: keep `ui/dialog.tsx` as the vendored base the wrapper is built from.

## Done when

- [ ] Every dialog in the SPA renders through `components/ui/custom/dialog.tsx`.
- [ ] An unsaved-changes dialog opened over an editor dialog renders above it.
