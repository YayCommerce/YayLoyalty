---
name: yayfinalize
description: Closes a YayCommerce release once the manager confirms it is live: verifies the accepted zips' checksums, merges the release branch into the released-code branch (no fast-forward), tags the exact commit of the accepted zip, merges back into the dev branch, marks support fixes released, and pushes only after confirmation. One plugin at a time. Run only when the user invokes /yayfinalize.
disable-model-invocation: true
# Purpose: the only place release tags are created, so tags always match what customers have.
# Update when: the finalize order, tag message or support-log update changes.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yayfinalize/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YayFinalize

`YR` = `.claude/release-kit/bin/yr`. One plugin edition (or branch group) at a time, start to finish, before the next. Everything that changes a long-lived branch or creates a tag is shown first and needs the user's yes.

## Steps (per release)

1. **What went live.** Ask: which plugin/edition/version, and which zip was accepted at each target (WordPress.org / EDD / Woo). List candidates with `YR builds list --plugin=<p> --edition=<e>` (release builds, newest first; `submitted` ones were handed to the manager).
2. **Verify.** For each accepted zip: `YR builds verify --zip=<path>`. A checksum mismatch stops this release: the file the manager uploaded is not the one the kit built. Ask the user to compare with what was uploaded.
   The tag goes on `BUILD.json → git.sha` of the accepted zip (for a resubmission, the last accepted one). Check that sha is on the release branch: `git merge-base --is-ancestor <sha> <release branch>`.
3. **Plan, then confirm.** Show, in one block:
   - merge `<release branch>` → `<released_branch>` (`--no-ff`). If the config names a released branch that doesn't exist yet (a first release), propose creating it at the tagged commit instead of merging; if the config names none, say so and skip this step;
   - tag `<tag name>` (from `tag_format`) on `<sha>` with a message carrying version, zip names, sha256 and shared-code pin;
   - merge `<release branch>` → `<dev branch>` (`--no-ff`) so the bump and changelog are on dev. Conflicts in version locations and at the top of the changelog are settled automatically (higher version kept, both changelog entries kept, newer first); code conflicts only when dev provably has every fix of the branch (a hotfix whose fixes were cherry-picked, or hand-ported with a `cherry picked from commit` trailer), keeping dev's side. Anything else stops;
   - support log: unreleased fixes that are in the tagged commit → `released in X.Y.Z` (client fixes not in it stay unreleased);
   - the push list.
   Ask for one yes. If the release branch's tip is ahead of `<sha>` (commits after the accepted build), say so: they are merged but are not in the tagged zip.
4. **Do it**, stopping at the first failure:
   1. `YR git merge --from=<release branch> --into=<released_branch> --approved --message="Merge <release branch> (X.Y.Z live)"`. For a released branch that doesn't exist yet: after the user's yes, `git -C <repo> branch --no-track <released_branch> <sha>` (the only long-lived branch the release skills create, and only here).
   2. Write the tag message to a temp file; `YR git tag --name=<tag> --sha=<sha> --message-file=<file> --approved`. Exit 3 = the tag exists: stop; the kit never moves tags.
   3. `YR git merge --from=<release branch> --into=<dev branch> --approved --resolve-release-files --plugin=<p>`. When it settled conflicts, show `git show <merge sha>` for the version files and changelog so the user sees the result.
   4. In a worktree of the dev branch (the merge printed where; or `YR worktree add --branch=<dev branch>` when it is not checked out anywhere): `YR support mark-released --plugin=<p> --edition=<e> --tree=<that worktree> --version=X.Y.Z --ref=<tagged sha> [--tickets=…]`, then `YR git commit --worktree=<it> --paths=<support log> --message="chore(support): X.Y.Z released" --approved`.
5. **Push (confirm).** `YR git push-plan --refs=<released_branch>,<dev branch>,tags/<tag>,<release branch>`; show it; after a yes, `YR git push --refs=… --confirmed`.
6. **Clean up (offer).** Offer `YR git delete-branch --name=<release branch> --confirmed` (local; git refuses if unmerged) and `YR worktree remove --path=…` for its worktree. The remote branch stays; say how to delete it on GitHub if they want.
7. Next release in the list.

## Failure handling

- **A superseded hotfix** (`/yaystatus`: a release branch below the latest released version, e.g. hotfix 3.6.3 after 3.7.0 went live): never finalize or tag it. Offer to delete the local branch once the user agrees; the release guards already checked its client fixes are in 3.7.0.
- **Merge conflict (exit 4):** nothing was changed for that step; earlier steps stay done. Report exactly which steps completed, then stop. Resolve by hand, then rerun /yayfinalize: completed steps are detected (merged branch, existing tag) and skipped.
- **Dirty checkout of a long-lived branch:** the kit refuses; ask the user to commit or stash.
- **Push rejected:** never force; show the state and ask.
