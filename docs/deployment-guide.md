# Deployment Guide

How YayLoyalty Pro and Lite are built, packaged and verified.

One repository produces two zips. Lite is Pro minus `includes/Pro/`, built with
`BUILD_TYPE=lite` so the admin bundle drops its Pro code too. There is no
separate Lite branch, no marker surgery on shared files, and no runtime tier
flag — see `code-standards.md` §13 for the seams that make this work.

## Prerequisites

| Tool | Notes |
|---|---|
| PHP CLI | 7.4+ — used by the validator and `php -l` |
| Composer | `update_shell` regenerates `vendor-prefixed/` via php-scoper |
| Node + pnpm | Node 22 recommended; `run.sh` falls back to corepack under `~/.nvm` |
| `zip` / `unzip` | packaging and validation |
| WP-CLI | optional, enables the headless boot check — see below |

## Releasing

```bash
./scripts/run.sh release pro 1.2.0        # prompts nothing, sets the version
./scripts/run.sh release lite --no-prompt # same version, Lite package

# Aliases for faster typing:
./scripts/run.sh release premium 1.2.0
./scripts/run.sh release free --no-prompt
./scripts/run.sh bump [pro|lite]          # bump version only
./scripts/run.sh info                     # show current version
./scripts/run.sh clean                    # remove build artifacts and vendor
./scripts/run.sh help                     # show all commands
```

Output lands in `release/`:

- `yayloyalty-{version}.zip` — Pro
- `yayloyalty-lite-{version}.zip` — Lite

**Release Pro first, then Lite with `--no-prompt`.** Version metadata is written
into the tree once, by whichever command runs first. Prompting twice is how the
two packages end up carrying different numbers.

`release` with no build type is Pro, so existing habits keep working.
`./scripts/run.sh build lite` builds just the SPA bundle when you want to look
at a Lite UI locally.

**After a release, run `composer install`.** The prerelease shell updater strips
dev dependencies, which takes `phpcs` with it — `composer lint` fails with
`command not found` until they are reinstalled.

### What actually differs

Both zips unpack to a folder named `yayloyalty`, so a Lite site upgrades to Pro
by replacing its plugin directory in place.

| | Pro | Lite |
|---|---|---|
| `includes/Pro/` | shipped | deleted from the staged copy |
| `IS_PRO_BUILD` in the bundle | `true` | `false` |
| Everything else | identical | identical |

SPA sources never ship — only `assets/`, never `apps/` — so `src/pro/` needs no
deletion step. Its absence from the Lite bundle rests entirely on
`BUILD_TYPE=lite` reaching Vite via `vite.config.ts`, which sets `VITE_IS_PRO` in
`import.meta.env`, which is why the sentinel check below exists.

The Composer autoload dump runs before the strip, and that is safe: Composer's
classmap covers `vendor-prefixed/src` and the root adapter only. Plugin classes
resolve through the `file_exists`-guarded autoloader in `yayloyalty.php`, so a
deleted `includes/Pro/` becomes "class not found" rather than a missing
`include`.

## Validation

Every release runs `scripts/validate-build.php` against the **zip**, not the
working tree. A failure aborts the release: the package is renamed
`*.zip.invalid` so it can be inspected but cannot be mistaken for shippable,
and `run.sh` exits non-zero.

Run it by hand against any package:

```bash
php scripts/validate-build.php release/yayloyalty-lite-1.2.0.zip lite
```

### What it asserts

| Check | Applies to |
|---|---|
| Zip unpacks to `yayloyalty/` | both |
| `release-manifest.json` present, `buildType` matches | both |
| Version agrees across manifest, plugin header and `YAYLOYALTY_VERSION` | both |
| Every manifested file present with a matching SHA256 | both |
| No packaged file missing from the manifest | both |
| `includes/Pro/` stripped | lite |
| `includes/Pro/YayLoyaltyPro.php` shipped | pro |
| `.gitkeep` files stripped | both |
| Bundle sentinel **absent** from `main.js` | lite |
| Bundle sentinel **present** in `main.js` | pro |
| Bundle is a single entry chunk | both |
| No Pro flag localized to the SPA | both |
| `php -l` clean across every PHP file | both |
| `Pro` named only by the guarded seam in `includes/YayLoyalty.php` | lite |
| No dev-only artifacts (`RegisterDev.php`, `.map`, `.vite`, `.DS_Store`) | both |
| Plugin boots inside WordPress with no fatal | both |
| `is_pro()` returns what the build type says | both |
| Localized payload carries no Pro-shaped key | both |

Two of these deserve explanation.

**The sentinel is checked in both directions.** `registerPro()` writes a marker
string into the bundle. Absent from Lite proves tree-shaking worked; present in
Pro proves the Lite assertion is not vacuous. A sentinel accidentally
eliminated from *both* builds would otherwise make the Lite check pass forever
while proving nothing.

**Lite still names Pro, and must.** `YayLoyalty::initialize()` keeps its
guarded `class_exists` plus the call it guards — two tokens, one seam, both in
`includes/YayLoyalty.php`. Asserting zero references would fail the build for
correct code, so the validator asserts they are *confined to that file*
instead. `vendor-prefixed/` is excluded: the scoped admin shell has its own
licence and plan vocabulary that this plugin neither owns nor strips.

### Enabling the headless boot check

The boot check loads the extracted package inside a real WordPress and asserts
it initialises without a fatal. It needs WP-CLI, so it reports **SKIP** — never
a silent pass — when unconfigured:

```bash
export YAYLOYALTY_WP_CLI="wp --path=/path/to/site"
export YAYLOYALTY_WP_CLI_ARGS="--skip-plugins=yayloyalty"
```

The installed copy of this plugin **must** be skipped, or its class
declarations collide with the extracted one. Add any other plugin that fatals
on load to the same list.

On a Local (by Flywheel) site:

```bash
export YAYLOYALTY_WP_CLI="$HOME/.claude/bin/wp-local yaydevs"
export YAYLOYALTY_WP_CLI_ARGS="--skip-plugins=yayloyalty"
```

Pass `--require-boot` to treat a skipped check as a failure. Use it in CI, where
"we could not check" and "it is broken" should have the same consequence.

## The integration suite

`composer test` is plain PHP with no database, which is why the invariants only
a server can settle — a UNIQUE index rejecting a second award, `SELECT … FOR
UPDATE`, the `balance_after` chain — sat as incomplete specifications for as
long as they did. `composer test:integration` is where they become assertions:

```bash
composer test:integration
```

It boots a real WordPress through `wp-load.php` and talks to a real MySQL.
Two environment variables point it at one:

| Variable | Meaning |
|---|---|
| `YAYLOYALTY_WP_ROOT` | Document root holding `wp-load.php`. Defaults to the site this copy of the plugin is installed in, so a normal dev checkout needs nothing. |
| `YAYLOYALTY_TEST_SOCKET` | `mysqld` socket, for a server that is not on PHP's default one. |

On a Local (by Flywheel) site, whose MySQL listens on a per-site socket while
`wp-config.php` says `localhost`:

```bash
export YAYLOYALTY_TEST_SOCKET="$HOME/Library/Application Support/Local/run/<site-id>/mysql/mysqld.sock"
composer test:integration
```

**It cannot touch the site's data, by construction.** `Utils\Table::name()`
composes every table name from `$wpdb->prefix` on each call, so the bootstrap
reassigns that one property to `ylit_` and every plugin table resolves to a
scratch namespace it creates on entry and drops on exit — asserting the prefix
before each `DROP`. wpdb's own table properties were resolved during load and
still point at the site, so WordPress keeps working; no option is written, and
the site's `yayloyalty_*` tables are never read. The plugin must be active on
the target site, since that is what loads its classes.

The suite refuses to start rather than guess: a missing `wp-load.php`, a
missing socket, an inactive plugin, or a site already using the scratch prefix
each stop it with a message naming the problem.

## Known Issues

**Build currently fails at `tsc --noEmit` before packaging can start.** Three pre-existing
type errors block it: `emoji-picker-content.tsx` (×2, `@emoji-mart/*` types undeclared) and
`progress.tsx` (`@radix-ui/react-progress` not installed). They must be fixed before
the next release can complete; `vite build` itself succeeds when run directly.

### Fixture-backed endpoints

Two categories of fixture data ship in this plugin, with different visibility rules:

**Dev-only `?scenario=` fixtures** (WP_DEBUG-gated): Drawer sub-routes (`GET /customers/{id}/ledger`, `.../activity`, `.../redemptions`, `.../referrals`) and dashboard (`GET /dashboard`) accept a `scenario` query arg only when `WP_DEBUG` is true. The gate is checked server-side by `CustomerDrawerFixture::requested()` and `DashboardFixture::requested()` respectively. Production sites with `WP_DEBUG = false` cannot reach these fixtures; requests without the arg always return real data or honest empty envelopes.

**Unconditional swap-point fixtures**: Campaign list/detail (`GET /campaigns`, `GET /campaigns/{id}`) and campaign performance (`GET /campaigns/{id}/performance`) are ENTIRELY fixture-backed via `CampaignRestController::dummy_fixture()` and `CampaignPerformanceFixture`. These ship in the zip and are what a production site sees until swapped for real data. The validator does not flag them; they are marked as swap points in their controller docblocks.

**Packaged builds ship without `RegisterDev.php`, and `YAYLOYALTY_IS_DEVELOPMENT` defaults
to `true` when undefined.** A production site must define
`YAYLOYALTY_IS_DEVELOPMENT` as `false` in `wp-config.php`, otherwise `RegisterFacade`
selects the dev registrar, finds no class, and registers no admin assets.

## When validation fails

The failing row names the problem. The two worth recognising on sight:

**Sentinel found in the Lite bundle.** Something deep-imports from `@/pro/`
outside the `import.meta.env.VITE_IS_PRO` gate, so Rollup kept the subtree. Everything Pro must
come through the `@/pro` barrel, imported once from `main.tsx` behind the
constant. A dynamic `import()` is not a fix — it emits a side chunk, which the
"single entry chunk" check exists to catch.

**A `Pro` reference outside `includes/YayLoyalty.php`.** Lite code grew a second
direct reference to the Pro namespace. It needs to go through a filter seam
instead; the seven attach points are documented in `code-standards.md` §13.

**Sentinel found in the Lite bundle, from a page that only renders a Pro
component conditionally.** Wrapping a Pro component in `<ProGate>` is not enough
on its own. JSX evaluates children eagerly, so `<ProGate><ProThing /></ProGate>`
references `ProThing` at runtime and pulls all of `@/pro` into Lite. The call
site needs `IS_PRO_BUILD && …` around the Pro component as well; `ProGate` decides
what a user *sees*, the constant decides what *ships*.
