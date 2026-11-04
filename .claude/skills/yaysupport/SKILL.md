---
name: yaysupport
description: Builds a patched zip of a YayCommerce plugin edition for one customer from the release they run: a support/<plugin>-<edition>-<ticket> branch from that release tag, a minimal fix, a gated client build (X.Y.Z-hotfix.N, so the next release still updates it), a support-log entry, the fix brought to the dev branch after asking, siblings listed, and a customer note. Never emails anyone. Interactive only. Run only when the user invokes /yaysupport.
disable-model-invocation: true
# Purpose: customer patch builds that are traceable (zip name + build-info.json) and never lost
#   (support log → next release's changelog).
# Update when: support branch naming, the log format or the customer note changes.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yaysupport/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YaySupport

`YR` = `.claude/release-kit/bin/yr`. Interactive only; never run inside yayorchestrate or in parallel.

## Steps

1. **Ask, in one message:** ticket/customer reference (used in names: letters, digits, dashes), the edition, and the version the customer runs (from their Plugins screen or `build-info.json` if they have a patched build already).
2. **Already fixed?** If `/yaystatus` shows an open hotfix branch (`release/<stem>-X.Y.Z (hotfix)`) that has this fix, use its client build instead (`/yayhotfix` step 7): one build for every affected client.
3. **Base.** The release tag for that version: `YR config resolve` → tag_format, then `git rev-parse --verify <tag>`. No tag for that version (e.g. released before the kit) → show the confirmed baseline / released branch commit that matches and ask the user to confirm it as the base. If the customer runs an older version than the latest release, say so and recommend patching the latest release: the client build is labelled above the latest release either way, so an older base gives the customer old code under a newer version number; let the user choose.
4. **Branch.** `YR git branch-create --name=support/<stem>-<ticket> --from=<base>` (exit 3 → ask: resume or `--fresh`). `YR worktree add --repo=<repo> --name=support-<stem>-<ticket> --branch=support/<stem>-<ticket>`.
5. **Fix** in that worktree. Keep it minimal. **Do not touch any version location**: the build sets the client version itself. Commit: `YR git commit --worktree=<wt> --paths=<files> --message="fix: <what> (support <ticket>)"`.
6. **Build:** `YR build --plugin=<p> --edition=<e> --type=support --ticket=<ticket> --ref=support/<stem>-<ticket>` (background). The zip is `<stem>-T-hotfix.N-support-<ticket>-<sha>.zip`, where T is the patch after the live release: inside it the plugin says T-hotfix.N (above the live version, below the next release, so WordPress offers the customer the next release as an update; git is unchanged). `build-info.json` names the ticket. Gates must pass.
7. **Bring the fix to the dev branch (ask).** Offer the configured propagation (normally `YR git cherry-pick --commits=<fix sha> --onto=<dev branch> --approved`). A hand port (cherry-pick conflict) should carry `(cherry picked from commit <sha>)` in its message. After it lands, note the dev commit sha. Until the fix is on the dev branch, the next release from it is refused (it would remove the fix from this customer).
8. **Support log (ask, then commit on the dev branch):** in a worktree of the dev branch, `YR support add --plugin=<p> --edition=<e> --tree=<wt> --ticket=<t> --customer-version=<v> --build-version=<T-hotfix.N, the BUILD.json version> --fix="<one plain line>" --support-commit=<sha> --dev-commit=<sha or 'not yet'>`, then `YR git commit --worktree=<wt> --paths=support/<plugin>-<edition>.md --message="chore(support): log <ticket>" --approved`. The next `/yayrelease` puts it in the changelog and checks it is in the release; `/yayfinalize` marks it released.
9. **Siblings.** `YR ports --plugin=<p> --edition=<e>` and say which sibling editions may need the same fix.
10. **Customer note.** Fill `.claude/release-kit/templates/customer-note.md` (what was fixed in plain words, how to install, the version it shows, that the next official update includes it). Print it with the zip path. Support sends it; the kit sends nothing.
11. **Push (ask):** `YR git push-plan --refs=support/<stem>-<ticket>[,<dev branch>]`, then push with `--confirmed` after a yes.

## Failure handling

- **Cherry-pick to dev conflicts:** nothing changed; record `--dev-commit="not yet"` and tell the user the port is still open (yaystatus keeps showing it).
- **Gates fail:** fix on the support branch, rebuild.
- **The fix will never be released** (e.g. replaced by a different fix): with the user's decision, `YR support set-status --plugin=<p> --edition=<e> --tree=<dev wt> --ticket=<t> --status=wont-release --note=<why>` and commit it; then it no longer blocks releases.
