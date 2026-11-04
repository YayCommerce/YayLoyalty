---
name: yayrelease-fix
description: Fixes a YayCommerce release a store sent back (usually Woo review) on its open release branch: applies the fix, asks where X.Y.Z is live, rebuilds as a -r<n> resubmission or proposes a patch bump, reruns gates, updates HANDOFF.md, and reminds to bring the fix to the dev branch and siblings. Use when Woo sent feedback, a review rejected the zip, the user says resubmit, or mentions YayReleaseFix. Not for tester bugs or customers.
# Purpose: store-feedback rounds on a release branch, without ever reusing a version for a different zip.
# Update when: resubmission naming, the live-version rule or the HANDOFF changes section change.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yayrelease-fix/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YayReleaseFix

`YR` = `.claude/release-kit/bin/yr`. Never uploads, never tags.

**Worker mode** (from yayorchestrate `release-fix`): the task gives the feedback, where X.Y.Z is live, and the fix instructions. Don't ask; record decisions and doubts in the report.

## Steps

1. **Find the release.** Edition from the user (ask if ambiguous). The open branch: `YR status --plugin=<p> --edition=<e> --json` → `open_branches`, or `git branch --list 'release/<stem>-*'`. If there are several, ask which. Get or create its worktree: `YR worktree add --repo=<repo> --name=<b with / → -> --branch=<b>` (exit 4 = it's checked out somewhere: work there).
2. **Previous submission.** `YR builds latest --plugin=<p> --edition=<e> --type=release`: the zip the store reviewed. Note its sha and file.
3. **Apply the fix** in the release worktree, guided by the feedback. Keep it to what the store asked. If the fix needs a change to shared code (admin-shell), stop: it must be made once in the shared repo, released, then every affected plugin rebuilt (`blocked: needs-shared-change` in worker mode).
4. **Where is X.Y.Z live?** Ask: "Has X.Y.Z been published anywhere (WordPress.org, EDD store, Woo, or the X.Y.Z zip itself sent to customers)?" Client builds (X.Y.Z-hotfix.N) don't count: they sort below X.Y.Z, so those clients update to whatever X.Y.Z finally is.
   - **Nowhere:** keep X.Y.Z. The rebuild is a resubmission: the file name gets `-r<n>`; the plugin's version stays X.Y.Z.
   - **Live somewhere:** never ship a different zip as the same version. Propose X.Y.(Z+1) (`YR version propose` for context), bump with `YR version bump --to=… --tree=<wt>`, and add a changelog entry for it (draft per `.claude/release-kit/reference/changelog-style.md`, show, then `YR changelog insert`). Under a shared policy, list siblings that would be left behind and offer to bump them too. Rename nothing: the branch name keeps X.Y.Z; the new version lives in the files and the zip.
5. **Commit:** `YR git commit --worktree=<wt> --paths=<files> --message="fix(release): <what the store asked>"`.
6. **Build:** `YR build --plugin=<p> --edition=<e> --type=release --ref=<b>` (background). Naming follows automatically: the previous zip was handed over (`submitted`), so this one is `-r<n>`; if it never was, the build asks for `--supersede`. Gates must pass.
7. **HANDOFF.md** with a "Changes since previous submission" section: write 2–5 plain lines (what the store asked → what changed) to a file and run `YR handoff write --build-json=<new BUILD.json> --changelog=<changelog text> --notes=<notes> --changes-since=<that file>`.
8. **Push (ask):** `YR git push-plan --refs=<b>`, then `YR git push --refs=<b> --confirmed` after a yes. When the user hands the zip over: `YR builds mark-submitted --build-json=…`.
9. **Bring it back.** Remind that the fix must reach the dev branch, or the next release reintroduces the problem: offer `YR git cherry-pick --commits=<fix sha> --onto=<dev branch> --approved` (with the user's yes). Then `YR ports --plugin=<p> --edition=<e>` and list sibling editions that may need the same fix, using the configured propagation method.

## Failure handling

- **Cherry-pick back to dev conflicts (exit 4):** nothing changed; say so and leave the port to the developer.
- **Gates fail:** fix on the release branch and rebuild; never skip without the user's OK.
