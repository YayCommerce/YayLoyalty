---
name: yaystatus
description: Read-only release status of YayCommerce plugin editions: released version (tag), dev-branch version, unreleased commits, open release/hotfix/support branches, latest test build, client builds and unreleased client fixes, fixes missing in sibling editions, and version or config drift. Use when the user asks what is released, unreleased or left to port, which version is live, or mentions YayStatus. Changes nothing.
# Purpose: read-only release status for plugin editions, from git refs, dist/ and the support logs.
# Update when: yr status output changes, or a new kind of drift should be reported.
#   The YayCommerce plugins share this skill: keep it repo-neutral; a repo's facts live in its
#   .claude/release.config.json. Canonical copy: YayCommerce/Yay-Release-Kit .claude/skills/yaystatus/;
#   a copy elsewhere is replaced whole by `yr kit sync`, never edited.
---

# YayStatus

Read-only. Never fetches, builds, commits or pushes unless the user asks for `--fetch` (which only updates remote-tracking refs).

`YR` below is `.claude/release-kit/bin/yr`, run from the repo root.

## Steps

1. **Scope.** Plugin and edition come from the user's words. None given: this repo's plugin, all editions. "All plugins" / "everything": `--all` (every plugin in `~/.config/yayrelease/registry.json`).
2. **Run.**
   - One plugin: `YR status [--plugin=<p>] [--edition=<e>]`
   - All: `YR status --all`
   - Add `--fetch` only if the user asked for fresh remote data. Add `--no-ports` if the scan is slow (big diverged histories) and the user doesn't need port info.
   - `--json` gives the raw data when you need a detail the table omits.
3. **Show** the table as printed, then the "Needs attention" list grouped by plugin. Explain each item in one plain sentence and say which skill fixes it:
   - no tag / no confirmed baseline → `/yayinit`
   - unported fixes → offer `yr git cherry-pick` (asks first), or record a "won't port" decision with `yr support port-decision`
   - unreleased client fixes → they go into the next `/yayrelease` changelog automatically
   - client builds out (X.Y.Z-hotfix.N) → the next release must be above the highest and contain their fixes; the release build checks it
   - a client fix not on the dev branch → cherry-pick it (asks first), or a release from dev will be refused
   - `(hotfix)` branch → `/yayhotfix` continues it; superseded hotfix → never tagged, `/yayfinalize` offers to delete it; a tagged release branch → can be deleted
   - config differs between dev branches → `yr kit sync`, then commit it on each branch (with approval)
   - latest test build is behind the dev branch → `/yaybuild`
4. Stop. Don't start any of those actions unless the user asks.

## Failure handling

- `not configured` for a plugin: say so, and that `/yayinit` sets it up. Keep showing the others.
- A dev branch missing locally: the repo may need `git fetch`. Ask before fetching.
- Slow port scans on two unrelated histories (e.g. YayExtra Pro vs Lite): rerun with `--no-ports`, and say the port column is not checked.
