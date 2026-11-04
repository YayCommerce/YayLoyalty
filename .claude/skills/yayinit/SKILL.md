---
name: yayinit
description: Sets up or updates the release kit for a YayCommerce plugin repo: detects edition branches without assuming names, proposes each edition (dev and released branch, wp.org/EDD/Woo target, slug, build command), asks about version policy and fix flow in small batches, confirms what is released now and tags those baselines, then writes .claude/release.config.json and the registry entry. Use to set up releases, add a plugin, or on YayInit.
# Purpose: produce and maintain .claude/release.config.json, so the other release skills never ask
#   for these values again.
# Update when: the config schema (.claude/release-kit/reference/config-reference.md) or the baseline method changes.
#   Shared by the YayCommerce plugins; canonical copy YayCommerce/Yay-Release-Kit .claude/skills/yayinit/,
#   replaced whole elsewhere by `yr kit sync`, never edited.
---

# YayInit

`YR` = `.claude/release-kit/bin/yr` (from the kit copy in this repo; if the repo has no kit yet, copy it first from a checkout of YayCommerce/Yay-Release-Kit: `<kit checkout>/install --to=<this repo or a branch worktree>`).

Nothing long-lived is created, renamed or pushed without the user's yes. Ask in batches of 2–3 questions (AskUserQuestion), each standing on its own.

## Steps

1. **Tools.** `YR kit doctor`. Missing php/composer/zip stops; missing branchpress/Docker only disables the Plugin Check gate (say so).
2. **Scan.** `YR detect --repo=<repo>`. It inspects each non-feature branch's files: plugin name and version, licensing adapter (`LicenseConfigAdapter` = Pro/EDD licensing; `PluginMenuAdapter` = Lite or Woo), `Woo:` header, readme.txt, build scripts and slug hints, admin-shell constraint; plus tags, ignore rules and local SVN checkouts (`~/Documents/svns`). Read the repo's CLAUDE.md / README / `docs/*release*` / `.claude/rules/release.md` for the documented release commands too.
3. **Propose the edition map** as a table: edition key (short, lowercase: `pro-edd`, `free`, `woo-pro`, `lite` …), label, target, dev branch, released branch (or none), slug (zip folder), build command, output path. Two patterns exist; detect, don't assume:
   - **one branch per edition** (e.g. dev/pro-edd, dev/free, dev/woo-pro);
   - **one branch, build flag** (e.g. `scripts/run.sh release pro|lite`): those editions share a dev branch, so they form a branch group with a shared version and one release branch; set `group_names` for the release branch stem.
   Confirm targets per edition explicitly (Woo Pro and Woo Lite are both `woo`; ask when a branch has no `Woo:` header but its name says woo). Ask the user to confirm or correct.
4. **Ask (batched):**
   - version policy: do the editions share one version number, or are they independent? (branch groups are always shared);
   - how fixes move between editions: cherry-pick, merge from a base edition, or by hand; and in which direction (`propagation.flow`, e.g. pro-edd → free → woo-free);
   - released-code branches: confirm each one; for an edition without one, propose creating it from the commit of its last release (step 6) and wait. Create it only after a yes, with plain `git branch <name> <sha>` (never move an existing branch).
5. **Fill the rest of each edition** (show it; the user can correct): `version_files` (header `Version:`, the `*_VERSION` define, readme `Stable tag`, README badges: patterns per `.claude/release-kit/reference/config-reference.md`), `prepare` (deps the repo's script doesn't install itself), `build.env`, `changelog` (wporg → `readme.txt` in the repo's existing heading/prefix style, read from its last entries; edd/woo → `CHANGELOG.md` with the same line style), `zip_rules` from the repo's own Pro/Lite guards (e.g. `includes/Pro/`, `@premium-start`, `vendor-prefixed/src/License/` in Lite), `extra_gates` for its validators (e.g. `php scripts/validate-build.php {zip} lite`), `shared_code`, `store` (EDD item / Woo product, ask).
6. **Baselines** (what is released now), per edition:
   - find the released version: Stable tag / header on the released branch; local SVN `~/Documents/svns/<slug>/trunk/readme.txt` and its `tags/`; for wporg the public API (read-only GET) `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=<slug>`; for EDD/Woo ask the user (store records).
   - propose the commit: `YR detect baseline --plugin=<p> --edition=<e> --version=<v> [--svn-tag-dir=~/Documents/svns/<slug>/tags/<v>]` ranks candidates (released branch, last commit before the version changed, byte-for-byte match with the SVN tag). Show the top candidates with their evidence.
   - the user confirms each one (or says "not sure": then leave that edition without a baseline; release skills skip it). Record `baseline {version, sha, tag, confirmed: true, source}`.
   - create the confirmed tags: `YR git tag --name=<tag_format with v> --sha=<sha> --message-file=<"Baseline: <label> <v>, confirmed by <user> on <date> from <source>"> --approved`. Pushing them waits for step 9.
7. **Write and register.** Write `.claude/release.config.json` (in the worktree you are committing from), `YR config validate`, then `YR registry add --plugin=<p> --repo=<main checkout> --config-ref=<one dev branch that will carry it>`. Shared code: `YR shared show`; if no pin, ask which admin-shell tag to pin (`YR shared pin --ref=<tag> --save`; show any warning it prints, e.g. a tag whose code reports another VERSION).
8. **Ignore rules.** Propose `.gitignore` changes and apply after a yes: `/dist/` must be ignored; if `.claude` is ignored wholesale, change it to `.claude/*` plus `!.claude/release.config.json`, `!.claude/release-kit/`, `!.claude/skills/`, `!.claude/agents/` (keep everything else ignored). Check with `git check-ignore -v`.
9. **Commit and push (ask).** The kit + config must be identical on every long-lived edition dev branch. Follow the repo's rule (CLAUDE.md): usually one PR per dev branch from a branch like `<name>/feat/release-kit`; never commit straight to a dev branch without the user's yes. Offer `YR kit hook-install` (blocks publishing commands in this repo; ask). Show `YR git push-plan --refs=<branches>,tags/<baseline tags>` and push only with `--confirmed` after a yes.
10. **Summary:** the config table, baselines (confirmed / skipped), the pin, and what is left to do (PRs to merge, editions without a baseline).

**Re-run for one plugin:** load the existing config, show that plugin's section, change only what the user asks (or re-detect it), and keep every other plugin untouched.

## Failure handling

- Detection is a guess. Whenever a branch could be two things (stale vs live, Woo vs EDD), ask; never pick silently.
- `config validate` errors: fix the config with the user; every problem is listed at once.
- A tag already exists (exit 3): show where it points; if that is not the confirmed commit, stop and ask. The kit never moves tags.
