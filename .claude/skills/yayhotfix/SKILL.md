---
name: yayhotfix
description: Fixes a bug in the live release of a YayCommerce plugin edition without the unreleased dev work: a release branch from the live tag for the next patch, the fix, a client build (X.Y.Z-hotfix.N) the tester checks and clients install now, which the real release later updates, the fix brought to the dev branch, then the release prepared and held until the user decides. Use when production has a bug, a hotfix is needed, or on YayHotfix.
# Purpose: production fixes that reach clients now, never strand them, and never ship untested dev work.
# Update when: lib/sh/hotfix.sh, the client version rule (lib/php/version.php) or the support-log guards change.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yayhotfix/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YayHotfix

`YR` = `.claude/release-kit/bin/yr`, run from the repo root. Interactive; never inside yayorchestrate. Never uploads, sends or tags anything; every push, merge into a long-lived branch and support-log commit waits for the user's yes.

## The version rule (explain it when you hand out a client build)

| What | Version inside the zip |
|---|---|
| live now | 3.6.2 |
| client builds (to clients now) | 3.6.3-hotfix.1, 3.6.3-hotfix.2, … |
| the hotfix release (wp.org / EDD / Woo) | 3.6.3 |

WordPress and the EDD/Woo updaters compare with PHP `version_compare`: 3.6.2 < 3.6.3-hotfix.1 < 3.6.3-hotfix.2 < 3.6.3 < 3.7.0. So a client build replaces the buggy version, a later client build replaces an earlier one, and the real release (or any later one) is offered to every client as a normal update. Clients never hold the real 3.6.3, so a store rejection can still be resubmitted as 3.6.3 `-r1`. The repo's own build script builds the branch unchanged; the kit then writes the client version into the version lines inside the zip. Nothing in git changes.

## Steps

1. **Edition.** `YR config resolve [--plugin=<p>] --edition=<e>`; ask if the plugin has several editions and none was named. Ask in the same message for the bug (one line) and, if known, the tickets of affected clients.
2. **Plan.** `YR hotfix start --plugin=<p> --edition=<e> --dry-run`. Show: live version and tag, the hotfix version (default: next patch; the user may pick a higher one), the branch, and the dev-branch commits that are *not* going in. No tag or confirmed baseline → stop: `/yayinit`. Under a shared version policy or a branch group, say which sibling editions get the same version (a branch group gets them on the same branch automatically).
3. **Start** (after a yes): `YR hotfix start --plugin=<p> --edition=<e> [--version=X.Y.Z]`. It creates `release/<stem>-X.Y.Z` from the live commit, a worktree, and an empty start commit that marks it as a hotfix. The files keep the live code exactly (no version bump yet), so the fix cherry-picks onto dev cleanly. Exit 3 = the branch exists: ask whether to resume it (use the existing worktree) or stop.
4. **Fix** in that worktree, minimal and only the bug. Commit: `YR git commit --worktree=<wt> --paths=<files> --message="fix: <what> (hotfix X.Y.Z)"`. A fix that needs a shared-code (admin-shell) change: stop and say so.
5. **Build the zip to test:** `YR build --plugin=<p> --edition=<e> --type=hotfix --ref=<branch>` (background) → `…-X.Y.Z-hotfix.N-<sha>.zip`, version X.Y.Z-hotfix.N inside (written into the zip after the repo's build; git is unchanged). It sorts above the live version, so the tester installs it over production. Write a short tester note (what was fixed, how to reproduce the bug, what else to check). The tester tests this exact zip, so clients get what was tested. More fixes → commit, build again (hotfix.N+1).
6. **Bring the fix home** right after the tester approves (ask):
   - dev branch: `YR git cherry-pick --commits=<fix shas> --onto=<dev branch> --approved`. A conflict (exit 4) means a hand port: the developer ports it and puts `(cherry picked from commit <sha>)` in the commit message, so the kit can tell the fix is there (finalize and the release guards rely on it);
   - siblings: `YR ports --plugin=<p> --edition=<e>`; for each sibling, check the bug exists in its live tag (`git apply --check` of `git format-patch -1 --stdout <sha>` in a temporary worktree of that tag, or read the code) and offer a hotfix for it too (another `/yayhotfix`).
7. **Send to clients** (when they need it before the release): the approved hotfix.N zip from step 5; one build serves every client of that round. In a worktree of the dev branch, log every client that receives it: `YR support add --plugin=<p> --edition=<e> --tree=<dev wt> --ticket=<t> --customer-version=<what they run> --build-version=X.Y.Z-hotfix.N --fix="<one plain line>" --support-commit=<fix shas, comma-separated> --dev-commit=<dev sha or 'not yet'>`, and commit it (`YR git commit … --paths=<support log> --message="chore(support): hotfix X.Y.Z-hotfix.N to <tickets>" --approved`, after a yes). Fill `.claude/release-kit/templates/customer-note.md` per client. The kit sends nothing.
   Changed something after clients got a build? Commit, test, build again: the next one is hotfix.N+1, and those clients update to it or to the release.
8. **Prepare the release** when the user wants it ready: follow `/yayrelease` steps 5–10 on this branch's worktree: bump every location to X.Y.Z (`YR version bump`; it also corrects tags whose files still say an older version), the changelog with only the hotfix's fixes (`YR changelog collect --ref=<branch>` gives them since the live tag), commit, `YR build --type=release --ref=<branch>`, HANDOFF.md, push after a yes. The release build refuses if a client build is above X.Y.Z or a logged client fix is not on the branch (`yr support check`): fix that, or with the user's decision mark a row `YR support set-status --ticket=<t> --status=wont-release --note=<why>`.
9. **Hold.** The zip waits in `dist/` until the user decides to hand it over. `/yaystatus` shows the hotfix branch and the client builds out. When the user hands it over: `YR builds mark-submitted --build-json=…`. Store feedback → `/yayrelease-fix`; live → `/yayfinalize` (it merges back into dev and settles the version/changelog conflicts).

## Overtaken

If a normal release goes live first (say 3.7.0), the hotfix branch is superseded: `/yaystatus` says so, it is never tagged, and nothing is deleted. Clients on X.Y.Z-hotfix.N update to 3.7.0, which must contain their fix: the release guards (`yr support check`, run by every release build) refuse 3.7.0 otherwise.

## Failure handling

- **Not above live** (a client build from a branch that is not a release/hotfix branch above the live version, or from one already released): start the hotfix with step 3 first.
- **Guard failed** on a release build: show the listed tickets; port the fix or record the user's wont-release decision. Never edit the support log by hand.
- **Push rejected / merge conflict:** never force; show the state and ask.
