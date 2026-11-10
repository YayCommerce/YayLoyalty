# Known issues

**Purpose:** the checks that fail today and the gotchas that bite, each reproduced, with its cause, a workaround and the condition for deleting it. A check listed here was already failing on `dev`, so judge your change by not adding to it ([CLAUDE.md](../CLAUDE.md)).

**Update when:** an issue is fixed (delete its entry), a new one is reproduced (add it with all four fields), or a symptom changes.

## pnpm crashes on Node older than 22

- **Symptom:** every pnpm command dies with `TypeError [ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING]: A dynamic import callback was not specified`.
- **Cause:** `package.json` pins pnpm 11 (`packageManager`), which needs Node 22 or later. An nvm shell can still default to an older Node.
- **Workaround:** run `nvm use` in the repo root, which reads `.nvmrc`, before any pnpm command.
- **Remove when:** every teammate's shell defaults to Node 22 or later.

## pnpm installs on its own, and the install runs `composer install`

- **Symptom:** a command that should only read or run first runs `pnpm install`. Examples are `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` and the `pnpm dev` inside `./scripts/run.sh dev`. The root `postinstall` then runs `composer install`, which rewrites `vendor/`. `.claude/settings.json` lets Claude run the admin lint, test and type-check without asking, so Claude can set off both installs with no prompt.
- **Cause:** pnpm 11 checks the dependencies before `run` and `exec`, and installs when `node_modules` is missing or stale. The root `package.json` chains `composer install` onto every install.
- **Workaround:** run `pnpm install` on purpose after pulling or switching branches, so the check finds nothing to do. To run a check with no install at all, call the tool directly, for example `node node_modules/typescript/bin/tsc --noEmit` from `apps/admin`.
- **Remove when:** the root `postinstall` is gone, or pnpm's pre-run check is off for this repo.

## The generated-directory guard doesn't see the shell

- **Symptom:** the guard stops Claude's file tools from editing generated output, but a shell command that writes there, such as `sed -i`, `cp` or a redirect, goes through. The generated directories are gitignored, so `git status` doesn't show the change either. Without `jq`, every edit shows a `PreToolUse` hook error and nothing is blocked.
- **Cause:** `.claude/hooks/guard-generated-paths.sh` runs only before Claude's Edit, Write, MultiEdit and NotebookEdit calls, and it reads each call with `jq`. A hook that can't run lets the edit through.
- **Workaround:** hard rule 1 in [CLAUDE.md](../CLAUDE.md) covers the shell too. To undo a stray write, rerun the command that builds the directory. Install `jq` if the hook error appears.
- **Remove when:** shell writes to generated output are blocked too.

## `composer lint` exits 1 with no errors

- **Symptom:** phpcs reports warnings and no errors, yet `composer lint` exits 1. Every warning is `Universal.NamingConventions.NoReservedKeywordParameterNames`, for parameters named `$list` and `$default`.
- **Cause:** phpcs exits non-zero on warnings too. The parameters are in `includes/Models/CampaignConditionSet.php`, `includes/Models/RewardConditionSet.php` and `includes/Controllers/CustomerRowFilters.php`.
- **Workaround:** compare with `dev`. Your change must add no error and no warning.
- **Remove when:** those parameters are renamed.

## Admin lint fails on existing code

- **Symptom:** `pnpm --filter @yayloyalty/admin-page lint` exits 1. ESLint reports one error, `no-empty`, for the empty `catch {}` in `apps/admin/src/lib/api/api.ts`, plus warnings, mostly from the Tailwind plugin's enforce-canonical-classes rule. Prettier never runs, because the script is `eslint . && prettier --check .`. Run on its own, Prettier also fails: it flags existing files and reports `Ignored unknown option` for the `importOrder*` options.
- **Cause:** the empty catch and the class-name warnings are in existing code. Some files were never formatted. The `importOrder*` options in `apps/admin/package.json` aren't supported by the installed import-sort plugin.
- **Workaround:** compare with `dev`: add no ESLint error or warning, and run Prettier on the files you change.
- **Remove when:** the error is fixed, the files are formatted and the dead options are deleted.

## Integration tests can exit 0 without running

- **Symptom:** `composer test:integration` prints WordPress's "Error establishing a database connection" page and exits 0. Once the database is reachable, it stops with "the plugin is not active on this site" and exits 1.
- **Cause:** the bootstrap loads the site's `wp-load.php`. A database error there ends the process with `die()` before the bootstrap's own checks run. Local gives each site its own MySQL socket, which isn't PHP's default.
- **Workaround:**
  1. Start the site.
  2. Export `YAYLOYALTY_TEST_SOCKET` with its socket path. Local shows the path on the site's Database tab; see the header of `tests/integration/bootstrap.php`.
  3. Activate the plugin on that site. Activation installs its tables.
  4. Read the output: an exit code of 0 alone doesn't mean anything ran.
- **Remove when:** the bootstrap checks the database connection itself and exits non-zero on failure.

## `./scripts/run.sh shell-update` updates dependencies and may rewrite tracked files

- **Symptom:** each run updates admin-shell, rebuilds `vendor-prefixed/`, may rewrite `composer.json` and may regenerate `scoper.inc.php`. Both files usually come out unchanged. They change when php-scoper, its Safe library or admin-shell's template moves on.
- **Cause:** `update_shell` in `scripts/run.sh`, which runs admin-shell's `vendor/bin/yaycommerce-update` ([architecture.md](architecture.md#admin-shell-sync-and-scoping)). `dev` and `release` no longer run it.
- **Workaround:** run `git status` after `shell-update`. Commit `vendor-prefixed/`, and a change to `composer.json` or `scoper.inc.php` only on purpose.
- **Remove when:** the sync no longer touches `composer.json` or `scoper.inc.php`.

## wp-env can't run the plugin on PHP 7.4

- **Symptom:** with `phpVersion` 7.4 in `apps/e2e/.wp-env.json`, every request dies with `Parse error: syntax error, unexpected '|'` in `vendor/thecodingmachine/safe/lib/special_cases.php`.
- **Cause:** wp-env mounts the working tree, whose `vendor/` holds the dev packages. php-scoper pulls in Safe, which needs PHP 8, and Composer's autoloader loads its files on every request.
- **Workaround:** the browser tests run on PHP 8.2. Check the PHP 7.4 floor by review (hard rule 4) or on a release zip.
- **Remove when:** wp-env mounts a `--no-dev` build of the plugin.

## Builds outside `run.sh` are Pro

- **Symptom:** the root `pnpm dev:admin` and `pnpm build:admin`, and any `vite` run without `BUILD_TYPE`, produce the Pro build: its bundle carries the Pro sentinel and no upsell.
- **Cause:** only `scripts/run.sh` sets `BUILD_TYPE`, and `apps/admin/vite.config.ts` builds Pro for any value but `lite`, including unset.
- **Workaround:** use `./scripts/run.sh dev lite` or `./scripts/run.sh build lite`, or export `BUILD_TYPE=lite` first.
- **Remove when:** the root scripts pass an edition.

## `makepot` misses JS strings without a build, and runs out of memory with one

- **Symptom:** without `assets/dist/admin/`, `pnpm makepot` succeeds, but the POT holds only the PHP strings. With a build, it dies with `Allowed memory size of 134217728 bytes exhausted` under PHP's default 128M limit.
- **Cause:** the script excludes `apps/`, so JS strings come only from the built bundle, and parsing that bundle needs more than 128M.
- **Workaround:** run `./scripts/run.sh build` first, then run the same command with more memory: `php -d memory_limit=1G "$(command -v wp)" i18n make-pot . languages/yayloyalty.pot --exclude=node_modules,apps,tests --domain=yayloyalty`. The POT follows whichever edition was built last.
- **Remove when:** the script raises the memory limit and always runs on a fresh build.

## Untracked lockfiles let installs drift

- **Symptom:** two checkouts, or two release runs, can resolve different dependency versions, including the admin-shell that ships in the zip. A check can pass on one machine and fail on another.
- **Cause:** `.gitignore` ignores `composer.lock` and `pnpm-lock.yaml`, so each checkout resolves its own versions on its first install. `vendor-prefixed/` is committed, so the shipped admin-shell is the same everywhere unless a `shell-update` changes it; a release warns when the installed admin-shell differs from the committed copy.
- **Workaround:** when results differ between machines, compare versions first, with `composer show` and `pnpm list`.
- **Remove when:** the lockfiles are committed.
