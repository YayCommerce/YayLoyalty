<!--
Purpose: the index every Claude Code session loads first: what this repo is, the commands, the hard rules, the git rules and where to look next.
Update when: a command, hard rule or git rule changes, or a harness doc is added, moved or removed. Keep it under 100 lines. Detail goes in a linked doc, reached by a "read when…" link.
-->

# YayLoyalty

WooCommerce loyalty plugin (points, rewards, referrals, VIP tiers). One repo ships two builds: Pro, and Lite, which is Pro without `includes/Pro/` and with the SPA built as `lite`.
PHP lives in `includes/`. The admin SPA is `apps/admin/` (React, Vite, TypeScript).

## Commands

Run from the repo root on Node 22: `nvm use` reads `.nvmrc`, and pnpm 11 fails on older Node. Use pnpm only; `apps/admin` rejects npm and Yarn. Composer tooling needs PHP ≥ 8.2.

- `pnpm install`: JS dependencies, then `composer install` (root `postinstall`). On a fresh clone, run `./scripts/run.sh dev` first.
- `composer lint`: phpcs, WordPress-Extra with a PHP 7.4 floor. [Fails today](docs/known-issues.md).
- `composer test`: PHPUnit unit suite, no WordPress.
- `composer test:integration`: PHPUnit inside the WordPress site this plugin is installed in, with the plugin active. Local sites set `YAYLOYALTY_TEST_SOCKET` (see the header of `tests/integration/bootstrap.php`). [Exit 0 doesn't prove it ran](docs/known-issues.md).
- `pnpm --filter @yayloyalty/admin-page lint`: eslint, then prettier. [Fails today](docs/known-issues.md).
- `pnpm --filter @yayloyalty/admin-page test`: Vitest. [Fails today](docs/known-issues.md).
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`: type-check the SPA. [Fails today](docs/known-issues.md).
- `./scripts/run.sh dev [pro|lite]`: Vite on :3030, which wp-admin loads while `YAYLOYALTY_IS_DEVELOPMENT` is true (the default). It first runs the admin-shell sync, which can [rewrite `composer.json` and `scoper.inc.php`](docs/known-issues.md): check `git status` before committing.
- `./scripts/run.sh build [pro|lite]`: type-check, then build the SPA into `assets/dist/admin/`. [Fails today](docs/known-issues.md).
- `./scripts/run.sh release [pro|lite] [version] [--no-prompt]`: package and validate a zip in `release/`. Run Pro first (it stamps the version), then Lite with `--no-prompt`. [Fails today](docs/known-issues.md). Run `composer install` afterwards, even after a failed run.

Any pnpm command may [run `pnpm install`, and with it `composer install`, first](docs/known-issues.md). `./scripts/run.sh help` lists the rest. Judge a failing check against `dev/edd`. If it fails there too, it was already failing: leave it unless asked, and don't add to it.

## Hard rules

1. Never hand-edit generated output: `vendor/`, `vendor-prefixed/`, `node_modules/`, `assets/dist/admin/`, `release/`. Change the input and rerun the command that builds it.
2. Lite names Pro in exactly one place: the guarded `YayLoyaltyPro::boot()` call in `includes/YayLoyalty.php`. Pro attaches only through the filters that `boot()` adds in `includes/Pro/YayLoyaltyPro.php`. `scripts/validate-build.php` fails a Lite zip that names Pro anywhere else.
3. SPA Pro code lives in `apps/admin/src/pro/` and is imported only through `apps/admin/src/pro/index.ts`. It renders only behind `IS_PRO_BUILD` (`apps/admin/src/lib/is-pro-build.ts`), a build-time constant; never use a runtime or localized flag. `<ProGate>` alone still ships the Pro code into Lite, and the validator's sentinel check misses that, so only review catches it.
4. Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types. phpcs does not detect PHP 8-only syntax and the dev tooling runs on PHP 8, so only review catches it.
5. Only `LedgerService` (`includes/Services/LedgerService.php`) writes ledger rows and balances. Construct it only in the places its docblock lists.

## Git

- Branch from `dev/edd` as `<name>/<type>/<topic>`.
- Every change, docs included, lands through a PR into `dev/edd` (squash merge). Never commit to `dev/edd` or `main` directly; `dev/edd` reaches `main` through its own PR.
- Commit messages follow Conventional Commits: `type(scope): summary`.
- Commit or push only when asked. Never force-push a shared branch, rewrite pushed history, skip hooks (`--no-verify`) or change git config.
- Check `git status` and stage files by name, so personal and generated files stay out.

## Plans and specs

`docs/plans/` and `docs/specs/` are kept as history. Act only on a plan whose `Status:` is `active` or a spec whose `Status:` is `accepted`. Any other status makes it a record, not an instruction.

## Where to look

- [docs/architecture.md](docs/architecture.md): how the pieces fit: boot, the admin-shell sync, layers and who writes what, data and upgrades, Pro/Lite, the SPA↔WordPress bridge, release and tests. Read it before a change that crosses layers, adds a table or a writer, touches the Pro/Lite split, or changes how the SPA loads or calls WordPress.
- [docs/conventions.md](docs/conventions.md): team rules the code can't show: money, dates and time zones, Pro data in Lite, TypeScript copies of PHP, wire casing, file names and comments. Read it before adding a file, a money or date field, a TypeScript copy of a PHP shape, or anything that stores Pro data.
- [docs/decisions/](docs/decisions/): the reasons behind architectural choices: the Pro/Lite split, the ledger's single writer, admin-shell scoping, UTC dates, money as strings and this harness. Read the matching record before a change that alters an architectural choice; [the format record](docs/decisions/2026-10-01-record-architecture-decisions.md) says when to write a new one. Only `accepted` records are in force.
- [docs/known-issues.md](docs/known-issues.md): checks that fail today and gotchas, each with a workaround. Read it when a command fails or does something unexpected, before you debug it.
- [docs/plans/](docs/plans/): phased plans. Read it when you continue planned work; the plan with `Status: active` says what comes next.
