# Releasing YayCommerce plugins

<!-- Purpose: the build → test → release → support workflow for people, and the commands behind it.
Shared by the YayCommerce plugin repos; canonical copy YayCommerce/Yay-Release-Kit, replaced whole elsewhere
by `yr kit sync`. A repo's own facts (branches, editions, build commands) are in .claude/release.config.json. -->

The release kit builds zips, writes notes and manages git state. **It never uploads, deploys or
publishes anything**: no SVN commits, no store uploads, no FTP/SSH, no customer emails. The manager
uploads every release by hand. Branches and tags are pushed to our own GitHub only after you see
exactly what will be pushed and say yes.

## Words

- **Edition**: one shippable variant of a plugin, e.g. YayPricing `pro-edd` (EDD store), `free`
  (WordPress.org), `woo-pro`, `woo-free` (WooCommerce Marketplace). Each has a dev branch, maybe a
  released-code branch, a version, a changelog, a zip and a target (`wporg`, `edd`, `woo`).
  Some plugins build two editions from one branch (e.g. YayLoyalty `release pro|lite`): those share
  a version and a release branch.
- **Shared code**: `yaycommerce/admin-shell`, bundled into each plugin's `vendor-prefixed/`. On a site
  with several of our plugins the highest admin-shell version wins, so every zip records the exact
  revision it ships and all zips of a run use **one pinned revision**.

## The cycle

| When | You say / run | What happens |
|---|---|---|
| Dev work done | `/yaybuild` *(plugin, edition)* | Test zip `…-X.Y.Z-test.N-<sha>.zip` in `dist/<plugin>/<edition>/`, gates run, tester note printed. No version change. |
| Tester found bugs | commit fixes on the dev branch, `/yaybuild` again | Next test number. |
| Testing passed | `/yayrelease` | Release branch `release/<plugin>-<edition>-X.Y.Z` from the approved build's commit, version bump everywhere, plain-language changelog for you to edit (includes support fixes), release zip `…-X.Y.Z.zip`, `HANDOFF.md` with upload steps. Asks before pushing. No tag. |
| Manager uploads | — | By hand, following `HANDOFF.md` (SVN steps for WordPress.org, EDD store notes, Woo notes). |
| Woo (or a store) sends feedback | `/yayrelease-fix` | Fix on the release branch. Not live anywhere yet → same version, file `…-X.Y.Z-r1.zip`. Already live somewhere → patch version. HANDOFF gets "Changes since previous submission". Reminds you to bring the fix to the dev branch and siblings. |
| Live everywhere | `/yayfinalize` | Checks the zip checksums, merges the release branch into the released-code and dev branches, **creates the tag `<plugin>-<edition>/vX.Y.Z` on the exact commit of the accepted zip**, marks support fixes released, pushes after you confirm. |
| Production has a bug | `/yayhotfix` | `release/<plugin>-<edition>-X.Y.Z` from the live tag (next patch, none of the unreleased dev work), fix, a client build `…-X.Y.Z-hotfix.N` that the tester checks and the clients who need it now get, fix brought to the dev branch, then the release prepared and held until you decide to upload. |
| A customer needs a fix now | `/yaysupport` | `support/<plugin>-<edition>-<ticket>` from the version they run, fix, client build `…-X.Y.Z-hotfix.N-support-<ticket>-<sha>.zip`, logged in `support/<plugin>-<edition>.md`, fix brought to the dev branch (asks first), customer note printed. |
| Anytime | `/yaystatus` | What's released, unreleased, open, unported between editions, drifting. Read-only. |
| Many plugins at once | `/yayorchestrate build\|release\|release-fix <plugin:edition …>` | One pinned admin-shell, one worker per edition in parallel (3 at a time), one review table for all of them, then pushes one by one after you confirm, plus a combined `HANDOFF-<date>.md` per target. |
| New repo / config change | `/yayinit` | Detects branches and editions, asks what it can't detect, confirms what is released now (baseline tags), writes `.claude/release.config.json`. |

Tags only ever mean "customers have exactly this". They are never moved or deleted.

## Fixes for clients before a release (client builds)

A zip sent to clients by `/yayhotfix` or `/yaysupport` never carries the version that will be
released. It says **X.Y.Z-hotfix.N**, where X.Y.Z is the release the fix is headed for:

| | Version inside the zip |
|---|---|
| live now | 3.6.2 |
| client builds | 3.6.3-hotfix.1, 3.6.3-hotfix.2, … |
| the release (wp.org / EDD / Woo) | 3.6.3 (or any later version, e.g. 3.7.0) |

WordPress and the EDD/Woo updaters order these with PHP `version_compare`, so each client build
replaces the buggy version, a later client build replaces an earlier one, and the real release is
offered to every client as a normal update, even if more was fixed after they got their zip. The
client version is set only inside the zip; nothing in git changes.

Two guards protect those clients. Every release build runs `yr support check`, and refuses when:

1. the release is not above every client build in the support log (those clients would never be offered it);
2. a client fix in the support log is not in the release (the update would remove it from them).

So bring every client fix to the dev branch (cherry-pick; a hand port says `(cherry picked from commit <sha>)` in
its message), or record the team's decision not to release it (`yr support set-status --status=wont-release`).
`/yaystatus` lists the client builds out and any client fix still missing on a dev branch.

## Where things are

- `dist/<plugin>/<edition>/` (git-ignored): zips, each with a `<zip>.BUILD.json` (plugin, edition,
  version, build type and number, git sha and branch, shared-code revision, date, sha256), plus
  `HANDOFF.md`, `REPORT.md`, `logs/`. Every zip also carries `<slug>/build-info.json`, so you can
  always tell which build a tester or customer has. The folder inside the zip is always the plugin's
  real slug.
- `support/<plugin>-<edition>.md` (committed on that edition's dev branch): customer patches and
  "won't port" decisions.
- `.claude/release.config.json` (committed, identical on every edition dev branch): editions, branches,
  targets, version locations, build commands, changelog format, zip rules.
- `~/.config/yayrelease/registry.json` (your machine): where each plugin's repo is, the admin-shell pin,
  concurrency.
- `.claude/release-kit/`: the scripts (`bin/yr help`), templates and docs (`.claude/release-kit/reference/changelog-style.md`,
  `.claude/release-kit/reference/gates.md`, `.claude/release-kit/reference/config-reference.md`).

## QA gates (every build)

PHP lint, PHPCS (if configured), Plugin Check in an isolated branchpress site (WordPress.org editions),
version numbers agree, readme/plugin headers (`Tested up to`, `Requires PHP`, `WC tested up to`),
nothing that shouldn't ship (`node_modules`, `.git`, tests, dev configs, source maps, lockfiles),
edition boundaries (e.g. no Pro code in Lite), the shared code is the pinned revision, the size didn't
jump, and the repo's own validators. A failed gate stops the build; the zip is kept in `dist/…/.staging/` to look at.

## Commands without Claude

Everything the skills do goes through `.claude/release-kit/bin/yr`, and every command takes `--dry-run`:

```bash
yr status --all                                   # the yaystatus table
yr build --plugin=yaypricing --edition=free --type=test --dry-run
yr hotfix start --plugin=yaypricing --edition=free --dry-run   # release branch from the live tag
yr support check --plugin=yaypricing --edition=free --ref=dev/free --version=3.7.0   # client guards
yr version propose --plugin=yaypricing --edition=free
yr shared pin --ref=v2.9.0 --save                 # set the admin-shell revision builds use
yr kit sync --from=<Yay-Release-Kit checkout> --dry-run   # update this repo's copy of the kit
```

## Updating the kit

Change it in YayCommerce/Yay-Release-Kit first (PR, then a new tag), then `yr kit sync --from=<Yay-Release-Kit>` in each repo and commit the
result on every edition dev branch. Never edit a copy in place.
