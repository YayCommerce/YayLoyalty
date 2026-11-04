---
name: yaysupport
description: Builds a patched zip of a YayCommerce plugin edition for one customer from the release they run: a support/<plugin>-<edition>-<ticket> branch from that release tag, a minimal fix, a gated zip with the version unchanged, a support-log entry, the fix brought to the dev branch after asking, sibling editions listed, and a short customer note. Never emails anyone. Interactive only. Run only when the user invokes /yaysupport.
disable-model-invocation: true
# Purpose: customer patch builds that are traceable (zip name + build-info.json) and never lost
#   (support log → next release's changelog).
# Update when: support branch naming, the log format or the customer note changes.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/YayLoyalty .claude/skills/yaysupport/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YaySupport

`YR` = `.claude/release-kit/bin/yr`. Interactive only; never run inside yayorchestrate or in parallel.

## Steps

1. **Ask, in one message:** ticket/customer reference (used in names: letters, digits, dashes), the edition, and the version the customer runs (from their Plugins screen or `build-info.json` if they have a patched build already).
2. **Base.** The release tag for that version: `YR config resolve` → tag_format, then `git rev-parse --verify <tag>`. No tag for that version (e.g. released before the kit) → show the confirmed baseline / released branch commit that matches and ask the user to confirm it as the base. If the customer runs an older version than the latest release, say so: patching the latest release (and asking them to update) is often simpler; let the user choose.
3. **Branch.** `YR git branch-create --name=support/<stem>-<ticket> --from=<base>` (exit 3 → ask: resume or `--fresh`). `YR worktree add --repo=<repo> --name=support-<stem>-<ticket> --branch=support/<stem>-<ticket>`.
4. **Fix** in that worktree. Keep it minimal. **Do not touch any version location**: the plugin keeps the customer's version so the next official update replaces this build normally. Commit: `YR git commit --worktree=<wt> --paths=<files> --message="fix: <what> (support <ticket>)"`.
5. **Build:** `YR build --plugin=<p> --edition=<e> --type=support --ticket=<ticket> --ref=support/<stem>-<ticket>` (background). The zip is `<stem>-<version>-support-<ticket>-<sha>.zip`; `build-info.json` inside names the ticket. Gates must pass.
6. **Bring the fix to the dev branch (ask).** Offer the configured propagation (normally `YR git cherry-pick --commits=<fix sha> --onto=<dev branch> --approved`). After it lands (or the user ports it by hand), note the dev commit sha.
7. **Support log (ask, then commit on the dev branch):** in a worktree of the dev branch, `YR support add --plugin=<p> --edition=<e> --tree=<wt> --ticket=<t> --customer-version=<v> --fix="<one plain line>" --support-commit=<sha> --dev-commit=<sha or 'not yet'>`, then `YR git commit --worktree=<wt> --paths=support/<plugin>-<edition>.md --message="chore(support): log <ticket>" --approved`. The next `/yayrelease` puts it in the changelog; `/yayfinalize` marks it released.
8. **Siblings.** `YR ports --plugin=<p> --edition=<e>` and say which sibling editions may need the same fix.
9. **Customer note.** Fill `.claude/release-kit/templates/customer-note.md` (what was fixed in plain words, how to install, that the next official update includes it). Print it with the zip path. Support sends it; the kit sends nothing.
10. **Push (ask):** `YR git push-plan --refs=support/<stem>-<ticket>[,<dev branch>]`, then push with `--confirmed` after a yes.

## Failure handling

- **Cherry-pick to dev conflicts:** nothing changed; record `--dev-commit="not yet"` and tell the user the port is still open (yaystatus keeps showing it).
- **Gates fail:** fix on the support branch, rebuild.
