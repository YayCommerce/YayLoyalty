---
name: yayrelease
description: Cuts a YayLoyalty release: preflight, version, the readme.txt changelog, the Pro and Lite zips, the release commit, then, behind one confirmation gate, the annotated tag, the push and the GitHub Release. Use when the user says "release", "ship a release", "cut a release", "cut 1.2.3", "bump the version", "prepare release notes", "generate the changelog", or asks for the Pro and Lite zips for a version. Not for a plain build, and not for a version bump with no release.
# Purpose: owns the git and GitHub side of a release, which scripts/run.sh does not do at all, and the
#   changelog entry (references/changelog-style.md). This header covers the whole skill directory.
# Update when: run.sh changes its release flags or what it bumps, the version gains or loses a location,
#   the changelog style or its file changes, the tag or branch model changes, or what gets attached to a
#   GitHub Release changes.
#   Named like the other yay* skills, but unlike them this one is NOT shared between the YayCommerce
#   plugins: every fact in it is YayLoyalty's own. Do not copy it to another repo; write that repo its own.
# This header sits in the frontmatter because Claude Code strips the frontmatter when it loads a
#   skill, but keeps HTML comments.
argument-hint: "[version] [both|free|pro] [--publish] [--draft] [--dry-run]"
disable-model-invocation: true
allowed-tools: Read, Edit, Bash, Grep, Glob
---

# /yayrelease: cut a YayLoyalty release

One skill, one linear flow, one irreversible step. The flow is not split into
changelog/build/publish skills on purpose: half a release — a tag with no bump, a bump
with no changelog — is worse than no release.

`argument-hint` in the frontmatter is display text only; nothing parses it. The table
below is the real contract. **Keep the two in step**: a stale hint is worse than none.

| Argument | Meaning | Default |
|---|---|---|
| `<version>` | `X.Y.Z`, `X.Y.Z.W` or `X.Y.Z-tag` | ask, proposing minor vs patch from the changelog |
| `both` / `free` / `pro` | which editions to build and attach | `both` |
| `--publish` | tag, push and create the GitHub Release — **the default**; the flag is the explicit form of it | on |
| `--draft` | create that GitHub Release as a draft | off |
| `--dry-run` | phases 0-5 for real, print 7, execute none of it | off |

**The editions have two names each, and the skill must not confuse them.** `free` and `pro`
are what the user says and what you say back. `run.sh` takes `free` as an alias and builds
it as `lite`, so **the artifact is `release/yayloyalty-lite-<version>.zip`** — there is no
zip with `free` in its name, and phase 7 attaches the `lite` path while calling it the free
edition. `premium` is an accepted alias for `pro`. Nothing here calls an edition "full":
`both` is the word for all of them.

**A plain `/yayrelease <version>` goes all the way**, through the tag, the push and the
GitHub Release. `--publish` names that out loud and changes nothing; accept it without
comment rather than treating it as a different mode. The one thing standing between the
commit and the first irreversible command is the **gate in phase 6**, and neither the flag
nor anything else stands in for the user's answer there.

To stop short, use `--dry-run`, which leaves nothing behind. If a run should build and
commit but not publish, say that is not a mode this skill has and let the user decide
whether to answer the gate with no — which leaves exactly that state, recoverable per
phase 8.

`disable-model-invocation: true` makes this `/yayrelease`-only. The cost: it will not
volunteer itself when the user describes a release in prose ("let's ship 1.1.0") — they
have to type the command. That is the right trade for a flow ending in pushed tags, but
say so if the user wonders why it never offers.

## What the repo already does, and what this skill owns

`scripts/run.sh release` packages and validates. It bumps the three version lines, builds
the SPA, assembles a staged copy, strips Lite, writes a SHA256 manifest, zips, and runs
`scripts/validate-build.php`, which fails the release on a bad package. Trust it for
packaging.

It **touches git not at all** — no clean-tree guard, no branch check, no commit, no tag,
no push. That whole half is this skill's, and it is the half that can go wrong
irreversibly.

Releases happen on `dev`, the default and only long-lived branch. There is no promotion
step and no release branch.

## Phase 0 — Preflight

Every check aborts with its own message. **Never make a check pass on the user's behalf**
— no stashing, no committing, no pulling, no tag deletion.

1. On `dev`: `git branch --show-current`. Abort naming the current branch.
2. Clean tree: `git status --porcelain` empty. Abort and list the paths; do not stash.
3. Not behind origin: `git fetch origin` then `git rev-list --count HEAD..origin/dev` is 0.
   Being **ahead is normal and fine** — phase 7 pushes the branch. Report the ahead count
   (`git rev-list --count origin/dev..HEAD`) and the subjects, so the user can confirm
   those commits belong in this release. Do not require the two to be identical.
4. Version not already tagged, locally **or on the remote**: `git tag -l "v<version>"` and
   `git ls-remote --tags origin "refs/tags/v<version>"` both empty. A local-only check
   misses a tag a teammate pushed.
5. `gh auth status` succeeds.
6. Repo visibility: `gh api repos/YayCommerce/YayLoyalty --jq .visibility`. Carry the
   answer to the gate and print it there. **This repo is public today**, and the Pro zip is
   a paid build — the gate must show both facts together so the choice is never invisible.
7. Build prerequisites, each its own abort: `php` (8.2+ for tooling), `composer`, `zip`,
   `patch`, and `vendor/bin/yaycommerce-prerelease` plus php-scoper from `composer install`.
   **Node 22 is not optional**: pnpm 11 dies on anything older with a dynamic-import error
   (`docs/known-issues.md`), and a default shell here may still be on Node 20. Run
   `nvm use` in the repo root first and assert `node -v` is 22 or later — `run.sh` only
   falls back to corepack, which does not save you from the wrong Node.
8. Compute the release date **once** here (`date +%F`) and reuse it. This repo's changelog
   carries no date (see the changelog reference), so today it is used only in the tag
   message — computing it once still means two dated strings can never disagree.

**Never release one edition to stand in for a release of both.** If a build fails, say which
edition and ask the user what they want; do not quietly ship the half that worked. Both
editions built and validated clean on 2026-11-06 — Pro 17 checks, Lite 21 — so a failure
here is new, not a known state to work around.

Optional but worth doing: set `YAYLOYALTY_WP_CLI` so the validator's headless boot check
runs instead of being skipped. Unset, the validator silently skips it and says so in its
report — read the report, do not assume it ran.

## Phase 1 — Version

Source of truth is the **`YAYLOYALTY_VERSION` define**, not the plugin header:
`get_version` in `scripts/run.sh` greps the define. Read it with
`./scripts/run.sh info`.

Assert the new version is **strictly greater** than the current one (compare numerically,
field by field; `sort -V` is fine for the common case).

**The exception is a resumed release.** A run that reached phase 5 and then stopped — the
user answered the gate with no, or a push failed — leaves the version already bumped and
committed, so rerunning the same version would abort here on a version that is equal, not
greater. Before asserting, check whether `HEAD` is already this release's commit — subject
`chore(release): <version>`, the three locations reading `<version>`, a clean tree and no
tag yet. If it is, phases 1-5 are **already done**: say so, skip to the gate, and assert
nothing. Never bump again on top of a release commit. If the user named no version,
propose minor vs patch from the changelog draft in phase 2 — a new earning rule or a new
admin screen is a minor; a fix to existing behaviour is a patch — and ask. Do not pick
silently.

## Phase 2 — Changelog

Read [references/changelog-style.md](references/changelog-style.md) before writing a word
of it. In short: entries live in `readme.txt` under its Changelog heading, newest first,
no dates.

Collect the range with **subjects and bodies** — the body usually carries the user-visible
symptom that belongs in the entry:

```
git log --no-merges --format='%h%n%s%n%b%n---' <prev-tag>..HEAD
```

**There is no previous release tag in this repo yet**, so the first release is not a
commit-range exercise at all — the house answer collapses the history into one
"Initial release." bullet. The changelog reference says what to do instead. Rehearsed
against the shipped 1.0.0 entry: a range-based draft does not reproduce it and must not
try.

Show the user the draft and let them edit it **before anything else happens**. Nothing is
written, built or committed until they are happy with it.

## Phase 3 — Bump

Use the repo's own seam. **Never sed a bare version string across the repo:**

```
./scripts/run.sh bump <version>
```

That writes exactly three live locations: the plugin header `Version:`, the
`YAYLOYALTY_VERSION` define, and `Stable tag:` in `readme.txt`. Then write the changelog
entry into `readme.txt` with an exact-context edit.

**Never touched by a release**, and all of them look like versions:

| Not a plugin version | Where |
|---|---|
| `SCHEMA_VERSION` / `CONFIG_SCHEMA_VERSION` integer constants | `includes/Helpers/SettingsHelper.php`, `includes/Helpers/DesignHelper.php`, `includes/Models/Campaign.php`, `includes/Models/CustomerMeta.php` |
| the `yayloyalty_db_version` option | `includes/Database/Installer.php` — set from the plugin version at runtime, never edited |
| `Requires at least:` / `Tested up to:` | `readme.txt` — WordPress versions |
| `WC requires at least:` / `WC tested up to:` | plugin header — WooCommerce versions |
| `"version"` in `apps/admin/package.json:5` | a private workspace package that never ships; `apps/` is not packaged. It is **deliberately** left behind and will differ from the plugin version. Do not "fix" it. |

Self-check, all three must hold:

```
git diff --name-only                       # exactly yayloyalty.php and readme.txt
git diff -U0 | grep -E '^\+.*(SCHEMA_VERSION|@since|@deprecated)'   # must print nothing
git diff -U0 -- readme.txt | grep -E '^\+(Requires at least|Tested up to):'  # nothing
```

Then positively confirm the three locations agree:
`grep -n 'Version:\|YAYLOYALTY_VERSION' yayloyalty.php; grep -n 'Stable tag:' readme.txt`.

## Phase 4 — Build

Bump **before** build, not after: the version is baked into the zip, the plugin header
inside it and the SHA256 manifest, and the validator fails the package if the three
disagree. A build before the bump ships the old version.

Build **only the editions the argument asked for** — `both` is the default, but `pro` or
`free` alone means one build and one attached zip in phase 7, not two.

Each build takes minutes — warn the user, and give each command a generous timeout.
Pro first when building both; it is the one that would stamp a version, and `--no-prompt`
keeps them from re-prompting after phase 3 already wrote it:

```
./scripts/run.sh release pro --no-prompt
./scripts/run.sh release free --no-prompt   # builds the lite package
```

**Run each one unpiped and read its exit status.** Piping it through `tail` or `head`
replaces the script's status with the pager's, so an aborted release reports success — this
happened in the rehearsal that produced this skill, and the Lite abort was reported as exit
0. If the output must be trimmed, keep the status: check `PIPESTATUS[0]`, or capture to a
file and read it afterwards.

Then `composer install`, **even if a build failed** — a release run leaves the plugin's own
dependency tree needing it back (`CLAUDE.md`).

**Verify the artifacts; do not trust the exit status.** For each zip in `release/`:

- `unzip -l` and confirm the single internal root directory is the plugin slug — both
  editions use the same folder name so upgrading Lite to Pro replaces it in place.
- Confirm the version inside, from the extracted header and define, matches the release.
- Print the `Plugin Name:` line from each zip and check it names the edition: the Pro
  header carries the suffix, Lite's does not. `build_package` drops it from Lite's staged
  copy and the validator asserts it both ways, so a mismatch here means that pair has
  drifted — stop and fix the pair, do not patch the zip.
- Cross-check the split: `includes/Pro/` present in Pro and absent in Lite; the Pro bundle
  sentinel present in the Pro admin bundle and absent in Lite's. The sentinel is a literal
  string the validator greps — refer to it by name, never paste the token into any file.
- Read the validator's own report for both builds and quote its skipped checks.

A validation failure renames the zip with an `.invalid` suffix and aborts. That is the
end of the release: fix the cause, do not re-zip by hand.

## Phase 5 — Commit

Stage **by name** — `yayloyalty.php` and `readme.txt` only. Build output is gitignored and
must not appear.

Subject in the repo's Conventional Commits style, `chore(release): <version>`; body lists
the changelog entry as it will ship. End with the `Co-Authored-By: Claude <model>` trailer
naming the model you run as, unless a personal `CLAUDE.local.md` drops it.

## Phase 6 — Gate

**This is the one stop in the flow, and it is not optional.** Print the block below, then
stop and wait. Print, in one block:

- the version, and the repo's visibility from phase 0
- the changelog entry exactly as it will ship
- `git show --stat HEAD`
- every artifact path, its size in bytes, and whether it will be attached
- the exact commands phase 7 will run

Then ask, and **wait for the user's answer in this conversation**. **The `--publish` flag**,
a `--dry-run` request, a plan approved earlier, an approval given for a previous release,
and the model's own confidence that everything looks right are **none of them the user's
answer**. Only the user, here, now. Publishing being the default makes this gate the only
thing between a typed command and a pushed tag, so never infer the answer from the command
that started the run.

Warn them that the GitHub Release step will raise a permission prompt. That prompt is a
feature on the one irreversible command — do not try to smooth it away, and do not widen
`allowed-tools` to avoid it: that pre-approval covers only the turn the skill was invoked
in and has already lapsed by the time the user answers this gate, which is the behaviour
you want here.

## Phase 7 — Publish

**Only after the user answered the gate.** Tag, then push, then release. **Push the tag
before creating the release**, or `gh` creates a tag of its own.

```
git tag -a "v<version>" -m "YayLoyalty <version>"
git push origin dev
git push origin "v<version>"
gh release create "v<version>" --title "v<version>" --notes-file <notes> \
  release/yayloyalty-pro-<version>.zip release/yayloyalty-lite-<version>.zip
```

Attach **the zip of every edition this run built** — for the default `both`, both zips.
That is the owner's standing decision (2026-11-06), taken knowing the repo is public and
that the Pro zip is an installable paid build. Re-confirm it at the gate each release
rather than treating this line as the approval, and if the repo has since gone private,
say so there. A single-edition run attaches one zip and must say which edition the
Release therefore does **not** carry.

Add `--draft` when asked. There has never been a published release in this repo, so the
first real run is a good candidate for `--draft`.

## Phase 8 — Failure handling

Reversibility ends at the **first push** in phase 7. Before it, everything is undoable;
after it, roll forward. **Never force-push and never rewrite pushed history** to tidy a
release.

| Fails at | Recovery |
|---|---|
| Phase 0 preflight | nothing happened; fix the cause and rerun |
| Phase 2 changelog | nothing written; redraft |
| Phase 3 bump | `git checkout -- yayloyalty.php readme.txt` |
| Phase 4 build | `git checkout -- yayloyalty.php readme.txt`, delete the zips and any `.invalid` one, run `composer install` |
| Phase 5 commit | `git reset --soft HEAD~1`, then the phase 3 recovery |
| Phase 6 gate, user says no | as phase 5 — nothing has left the machine |
| Gate declined, commit left in place | a complete local release. Undo as phase 5, or rerun the same version to resume from the gate |
| Tag created, not pushed | `git tag -d "v<version>"` |
| **After `git push origin dev`** | irreversible. Roll forward: fix on a new branch, release a new patch version. Do not revert the tag |
| `gh release create` fails after the pushes | the git side is **already correct**. Retry the release only. Never re-push, never re-tag |

## Phase 9 — Dry run

With `--dry-run`: run phases 0-5 for real, print phase 7 verbatim, execute none of it.
`--dry-run` beats the default and `--publish` alike — print what phase 7 would do, say
plainly that nothing was published, and never resolve the contradiction by publishing.
Then give the user the exact unwind commands and run them on request:

```
git reset --soft HEAD~1
git checkout -- yayloyalty.php readme.txt
```

**Restore gitignored files too.** `git reset` does not touch them, so also delete the
throwaway zips and any `.invalid` leftovers from `release/`, and say that
`assets/dist/admin/` now holds a build from the rehearsal — harmless, rebuilt by the next
build, but say it rather than leaving it silent. A rehearsal that leaves gitignored state
behind breaks the *next* build long after anyone remembers why.
