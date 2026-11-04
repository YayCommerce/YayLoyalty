---
name: yaybuild
description: Builds a test zip of one YayCommerce plugin edition from its dev branch for the tester, with no version bump: preflight, pinned admin-shell, throwaway build tree, QA gates, a numbered X.Y.Z-test.N zip with BUILD.json in dist/, and a tester note with changes and a checklist. Use when the user wants a test build, a rebuild after a tester fix, or mentions YayBuild. Not for release zips (yayrelease) or customer patches (yaysupport).
# Purpose: tester builds, without touching versions or branches. Tester-round fixes are ordinary
#   commits on the dev branch followed by another yaybuild.
# Update when: lib/sh/build.sh changes its steps or output, or the tester note changes.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yaybuild/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YayBuild

`YR` = `.claude/release-kit/bin/yr`, run from the repo root. Never uploads, sends or pushes anything.

## Steps

1. **Edition.** Resolve with `YR config resolve [--plugin=<p>] --edition=<e>`. If the plugin has several editions and the user named none, ask which (one question, list the editions with their targets). Never guess.
2. **Plan (dry run).** `YR build --plugin=<p> --edition=<e> --type=test [--ref=<branch>] --dry-run`. It prints the commit, version, file name and steps, and stops on:
   - uncommitted changes in a checkout of that branch (they would not be in the build): tell the user; don't stash for them;
   - the branch behind its upstream: say "pull first";
   - no shared-code pin: show `YR shared show`, and ask which admin-shell tag to pin (`YR shared pin --ref=<tag> --save`). Never build against "latest".

   Show the user the one-line plan (version, commit, shared code) and continue unless they object.
3. **Build.** Same command without `--dry-run`. It can take 5–15 minutes: run it in the background and wait for it to finish (don't poll). On exit 5 (gates failed) or 1 (build failed), go to Failure handling.
4. **Tester note.** Read `.claude/release-kit/templates/tester-note.md` in the kit, and fill it:
   - changes: `YR commits --range=<previous test build sha>..<this sha>` (previous = `YR builds latest --type=test` before this build; first build: since the release tag, `YR version propose` shows the base). Write user-facing lines like a changelog (see the kit's `.claude/release-kit/reference/changelog-style.md`), leaving internal commits out.
   - checklist: 3–8 concrete things to try, derived from the changes (the screen, the action, the expected result), plus "the previous fixes still work" when this is a re-test after a fix.

   Print the note and the zip path. Don't write it anywhere else unless asked.
5. **Cross-edition reminder.** If the commits since the last test build include fixes, run `YR ports --plugin=<p> --edition=<e>` and list sibling editions that don't have them yet ("these fixes are only in <e>; <sibling> may need them"). Offer the propagation method from the config (`YR git cherry-pick … --approved` only after the user says yes).

## Failure handling

- **Gates failed (exit 5):** show the failing gates and their details from `dist/<p>/<e>/.staging/<id>/GATES.json`. Explain in plain words, propose the fix in the plugin's code (not in the gate), and stop. After the fix is committed, run yaybuild again. Don't skip a gate unless the user explicitly asks; then use `--skip-gates=<id>` and say it in the tester note.
- **Build failed (exit 1):** show the last log lines (`dist/<p>/<e>/.staging/<id>/build.log`). The kept worktree path is printed; remove it with `YR worktree remove --path=<p>` once done.
- **A build of this name already exists (exit 3):** nothing to do; the kit never overwrites.

## Scripts

`.claude/release-kit/lib/sh/build.sh` (preflight, worktree, pin, build, gates, dist), `.claude/release-kit/lib/php/gates.php` (see `.claude/release-kit/reference/gates.md`), `.claude/release-kit/lib/php/builds.php` (names, BUILD.json, build-info.json).
