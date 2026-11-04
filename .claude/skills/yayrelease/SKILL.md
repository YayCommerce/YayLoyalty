---
name: yayrelease
description: Prepares the final release zip of one YayCommerce plugin edition after testing passed: approved test build, proposed version bump, release/<plugin>-<edition>-X.Y.Z branch, every version location bumped, a plain-language changelog with support fixes for the user to edit, a gated release zip and HANDOFF.md for the manager. Never uploads or tags. Use when testing passed, the user wants to prepare the release, or mentions YayRelease.
# Purpose: release preparation for one edition (or one branch group), interactive, or as a worker
#   step inside yayorchestrate (then every question becomes a report entry).
# Update when: the release steps, branch naming or HANDOFF content change.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yayrelease/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YayRelease

`YR` = `.claude/release-kit/bin/yr`, run from the repo root. The kit never uploads, publishes or tags here: tags come from `/yayfinalize` once the manager confirms the release is live.

**Worker mode** (yayorchestrate passed you a task file): never ask. Use the approved build, version and draft the task gives; where a step below says "ask", take the default and record the question in the report instead. Insert the changelog draft as written and put it in the report for the consolidated review.

## Steps

1. **Edition and baseline.** `YR config resolve --plugin=<p> --edition=<e>`; ask for the edition if the plugin has several and none was named. `YR version propose --plugin=<p> --edition=<e>` must find a tag or confirmed baseline; if not, stop: "run /yayinit to confirm what is released first".
   Editions that share a dev branch (`branch_group`, e.g. Pro and Lite built from one branch) are released together: one branch, one bump, one changelog, one zip per edition.
2. **Approved test build.** `YR builds list --plugin=<p> --edition=<e>`. Ask which test build the tester approved (default: the latest). Then `git rev-list --count <its sha>..<dev branch>`: if the dev branch has newer commits, list them and warn that they are not tested; release from the approved commit unless the user wants a new test build first.
3. **Version.** `YR version propose --plugin=<p> --edition=<e> --ref=<approved sha>`. Show: current release → proposed version, the bump reason in one line, every `uncertain` item, and `notes`. Under a shared version policy, list each sibling and whether it would be left behind (`siblings[].left_behind`); offer to release them too (one `/yayrelease` each, or `/yayorchestrate release`). The user may override the version; it must be higher than the released one and than every client build in `client_builds`.
   **Client guards:** `YR support check --plugin=<p> --edition=<e> --ref=<approved sha> --version=X.Y.Z`. It fails when a client received a client build at or above X.Y.Z, or a logged client fix (yaysupport/yayhotfix) is not in the approved commit; the release build refuses in the same cases. Show the tickets. The fix is to bring the fix to the dev branch (and test again), or, with the user's decision, `YR support set-status --ticket=<t> --status=wont-release --note=<why>`. In worker mode: report `blocked` with the problems.
4. **Release branch.** Name: `release/<plugin>-<edition>-X.Y.Z` (a branch group: `release/<group>-X.Y.Z`; the kit computes it from the config). `YR git branch-create --name=<b> --from=<approved sha>`.
   - Exit 3 = it exists. Ask: resume it (use it as is) or start fresh (`--fresh` moves the old one aside to `<b>-old-<timestamp>`; nothing is deleted).
   - Then `YR worktree add --repo=<repo> --name=<b with / → -> --branch=<b>`; work only in that worktree.
5. **Bump.** `YR version bump --plugin=<p> [--edition=<e>] --to=X.Y.Z --tree=<worktree>` (for a branch group, once per edition; they share the files). It verifies every location after writing; stop if it reports a mismatch.
6. **Changelog.** `YR changelog collect --plugin=<p> --edition=<e> --ref=<approved sha>`. Write the draft JSON following `.claude/release-kit/reference/changelog-style.md`: merge related commits into one line, plain words, groups New / Improved / Fixed / Security, include every `support_fixes` entry, and fold in `existing_unreleased` text if present. Save it as `<worktree>/../<b>-changelog.json` (outside the repo). `YR changelog render --draft=<file>` and show the user the rendered entry plus a "left out" list (internal commits you dropped). Apply their edits. Then `YR changelog insert --draft=<file> --tree=<worktree>` (add `--replace-unreleased` when the file had an Unreleased/TBD block and the user agreed to replace it).
7. **Commit** on the release branch: `YR git commit --worktree=<wt> --paths=<the changed version files and changelog file> --message="chore(release): <plugin> <edition> X.Y.Z"`. (The build runs from a commit, so this comes before the build.)
8. **Build and gates.** For each edition in the group: `YR build --plugin=<p> --edition=<e> --type=release --ref=<b>` (background; it takes minutes). If a candidate of this version exists that the manager never received, the build stops with exit 3: re-run with `--supersede`. Gate failures: fix on the release branch (commit with `YR git commit`), rebuild. Don't skip gates without the user's explicit OK.
9. **HANDOFF.md.** Write the rendered changelog to `<file>.txt`, and notes on anything unusual (gate warnings, skipped gates, untested newer commits, a `Woo:` header missing, a shared-code pin with a warning, a sibling left behind) to `<notes>.md`. Then `YR handoff write --build-json=<dist/…/X.Y.Z.BUILD.json> --changelog=<file>.txt --notes=<notes>.md`. Print its path and the zip path + sha256.
10. **Push (ask).** `YR git push-plan --refs=<b>` and show the output. Push with `YR git push --refs=<b> --confirmed` only after the user agrees. **Do not tag.**
11. **Hand over.** Ask whether the zip goes to the manager now. When the user confirms: `YR builds mark-submitted --build-json=<…BUILD.json>` (from now on, a rebuild of this version is a resubmission `-r1`). Remind: store feedback → `/yayrelease-fix`; live everywhere → `/yayfinalize`.
12. **Siblings.** `YR ports --plugin=<p> --edition=<e>`: list fixes in this release that sibling editions don't have, using the configured propagation method. Offer to cherry-pick (`YR git cherry-pick --commits=… --onto=<sibling dev branch> --approved`) only with the user's yes.

## Failure handling

- **No baseline:** stop; `/yayinit`.
- **Client guards fail:** see step 3; never release over a client build or without a client's fix.
- **A bug in what is live now** (not a new release): that is `/yayhotfix`, from the live tag instead of the dev branch.
- **Version mismatch after bump:** a pattern in the config doesn't match the file any more. Show the problem; fix the config with the user (`/yayinit` re-run for this plugin), never hand-edit around it.
- **Gates fail:** as in yaybuild: explain, fix the plugin on the release branch, rebuild.
- **Push rejected** (branch moved on the remote): never force. Show the state and ask.
- Anything you'd normally ask, in worker mode: default + report entry.

## Reference

- `.claude/skills/yayrelease/references/handoff-notes.md`: what goes into the notes per target.
- Kit: `.claude/release-kit/reference/changelog-style.md`, `.claude/release-kit/reference/gates.md`, `.claude/release-kit/templates/handoff-<target>.md`.
