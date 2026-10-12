# `run.sh clean` leaves the blocks' build behind

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** the 2026-10-12 docs-sync audit of `dev/edd` @ `656cbbf`, branch `trace/docs/sync-docs-with-code-2`.

## Today

`cmd_clean` in `scripts/run.sh` removes `assets/dist/admin` and `assets/dist/storefront`. The `build:blocks` step in `apps/admin/package.json` (`apps/admin/vite.blocks.config.ts`) writes `assets/dist/blocks/editor.js`, which `cmd_clean` never removes.

## Why it matters

After a clean, a stale blocks editor script stays on disk, so a developer who expects a clean tree can load an old block editor, or ship it in a local zip, without noticing.

## Suggested fix

Add an `assets/dist/blocks` branch to `cmd_clean`, like the other two. It was out of scope for the docs change that found it.

## Done when

- [ ] `cmd_clean` in `scripts/run.sh` removes `assets/dist/blocks`.
