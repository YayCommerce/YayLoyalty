# Two TypeScript copies of PHP don't name their source of truth

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-12 docs-sync audit of `dev/edd` @ `656cbbf`, branch `trace/docs/sync-docs-with-code-2`.

## Today

[docs/conventions.md](../conventions.md#typescript-copies-of-php-name-their-owner) says a TypeScript copy of a PHP shape or constant opens with a comment whose first sentence is `Mirrors <PHP symbol>, the source of truth.` Two files added since the last docs sync don't:

- `POINTS_LABEL_MAX_LENGTH` in `apps/admin/src/pages/settings/general-settings.form.ts` says only "Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH.";
- `setupSchema` in `apps/admin/src/lib/schema/setup.schema.ts` says "Mirrors SetupService::payload(): POST /setup takes it and returns the saved values."

## Why it matters

The fixed wording is what a search for TypeScript copies of PHP finds, so a PHP change can miss these two copies and let them drift.

## Suggested fix

Reword both opening sentences to the convention's form, keeping the rest of each comment.

## Done when

- [ ] The `POINTS_LABEL_MAX_LENGTH` comment in `apps/admin/src/pages/settings/general-settings.form.ts` opens with "Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH, the source of truth."
- [ ] The `setupSchema` comment in `apps/admin/src/lib/schema/setup.schema.ts` opens with "Mirrors SetupService::payload(), the source of truth."
