# YayLoyalty — Points,  Rewards,  Referral & VIP for WooCommerce

<!-- yay:managed:start v=0.8.0 -->
<!-- Kit-managed by the yay plugin 0.8.0. Don't edit this block; run /yay:harness-update. Project knowledge goes in the yay:project block below. -->
## How we work here

This repo uses the **yay** harness. Work through its skills so every developer and every Claude session follows the same flow:

| Step | Skill | Writes |
|---|---|---|
| Not sure? Say it in plain words | `/yay:go` | nothing: shows what's next, or picks the skill below |
| Plan the horizon | `/yay:roadmap` | the roadmap (`R-<n>`): one file per sprint, a list per version |
| Explore an idea | `/yay:brainstorm` | `docs/brainstorms/` (`BS-<n>`) |
| Design a UI | `/yay:design` | `docs/designs/` (`UX-<n>`) |
| Decide the work | `/yay:plan` | `docs/plans/` (`P-<n>`) |
| Build it | `/yay:implement` | worktree, branch, commits, PR |
| Run a whole queue | `/yay:autopilot` | all of the above per item, one decision sheet, `.yay/runs/` |
| Find a bug's cause | `/yay:debug` | `docs/bugs/` (`B-<n>`) |
| Fix a bug | `/yay:fix` | worktree, branch, commits, PR |
| Test build for QA | `/yay:release --bundle` | `release/bundles/<slug>-<x.y.z>-rc.<n>/` |
| Ship | `/yay:release` → merge → `/yay:release --tag-only` | readme.txt changelog, `docs/releases/`, checked bundle, tag |
| Keep docs true | `/yay:knowledge-update` | this file's project block, `docs/knowledge/` |

- A worked example from roadmap to release tag, with the exact prompts, is in `docs/yay-workflow.md`.
- Each skill checks for the previous step's output and says when it's missing. You can still run any skill on its own.
- Lost? `yayh status` lists the work in flight in every worktree, most urgent first, each with the command that moves it on. `yayh next [ID]` prints just that command.
- The repo's commands, editions, branches and generated paths are in `.yay/config.json`. Read them with `yayh config get <key>`; never guess a command.
- Get IDs and slugs from `yayh id next <R|BS|P|B|BL|UX>` and `yayh slug "<text>"`.
- Git: branches are `<type>/<slug>`, commits are `type(scope): summary`, and you never commit to `dev` directly. The full rules are in `.claude/rules/yay-git.md`.
- Never hand-edit generated paths: vendor/, vendor-prefixed/, node_modules/, assets/dist/, dist/, release/. A hook blocks it.
- Product PHP runs on PHP 7.4+. `.claude/rules/yay-wordpress.md` lists what that rules out.
<!-- yay:managed:end -->

<!-- yay:project:start ref=3f6424ea updated=2026-11-12 -->
## What this is
A WooCommerce loyalty program: shoppers earn points for orders, sign-ups and reviews, and spend them as a discount at checkout (classic and block). Merchants run it from a React admin SPA under the shared YayCommerce menu. One repo ships two editions: **Pro** (default) and **Lite**, which is Pro without `includes/Pro/` and with the SPA built as `lite`. Pro adds triggers, reward types, batch expiry, birthday points, a daily earning limit, segments and reports, all attached through filters.

## Layout
| Path | What |
|---|---|
| `yayloyalty.php` | Constants, autoloader, `plugins_loaded` boot, AdminShell registration |
| `includes/YayLoyalty.php` | Composition root; the one guarded `YayLoyaltyPro::boot()` call |
| `includes/Engine/` | Earning, Redemption, Rewards, Expiry, Emails, Storefront, admin order screens, asset registration |
| `includes/Services/`, `Repositories/`, `Models/`, `Database/` | Ledger and lots, one-table SQL, typed rows, schemas and `Installer` |
| `includes/Controllers/` | REST `yayloyalty/v1` |
| `includes/Utils/`, `includes/Helpers/` | Shared helpers, settings and design storage |
| `includes/Integrations/` | YayMail, YayPricing, YayCurrency |
| `includes/Pro/` | Pro only; removed from the Lite zip |
| `apps/admin/` | Admin SPA (React, Vite, TS); `src/pro/` is Pro only |
| `apps/e2e/` | Playwright + wp-env |
| `assets/storefront/`, `assets/admin/` | Hand-written storefront and order-screen JS/CSS (no build) |
| `scripts/` | `run.sh` (dev, build, release), `validate-build.php`, the Lite admin-shell patch |

## Commands
Run from the repo root on Node 22 (`nvm use`). pnpm only; `apps/admin` rejects npm and Yarn.
- `pnpm install && composer install`: deps. The root `postinstall` runs `composer install`, which needs read access to the private admin-shell repo.
- `pnpm run dev` (`./scripts/run.sh dev [pro|lite]`): Vite on :3030, which wp-admin loads while `YAYLOYALTY_IS_DEVELOPMENT` is true (the default).
- `./scripts/run.sh build [pro|lite]`: type-check, then the SPA, storefront CSS and blocks into `assets/dist/`.
- `composer lint`: phpcs. Fails on `dev` with a known baseline, see known-issues.
- `composer test`: PHP unit suite, no WordPress. `composer test:integration`: inside the WordPress site this plugin is installed in, plugin active; on Local set `YAYLOYALTY_TEST_SOCKET`.
- `pnpm --filter @yayloyalty/admin-page test` / `lint` / `exec tsc --noEmit`: Vitest, eslint + prettier, type-check.
- `pnpm --filter @yayloyalty/e2e env:start`, then `test`: wp-env on :8889; build the `pro` SPA first.
- `./scripts/run.sh release {edition} {version} --no-prompt`: zip into `release/` and validate it. Pro first (it stamps the version in the working tree), then Lite.
- `./scripts/run.sh shell-update`: update admin-shell and rebuild the committed `vendor-prefixed/`; commit the result.

## Hard rules
1. Lite names Pro in exactly one place, the guarded `YayLoyaltyPro::boot()` in `includes/YayLoyalty.php`; Pro attaches only through filters. `scripts/validate-build.php` fails a Lite zip that names Pro elsewhere.
2. SPA Pro code lives in `apps/admin/src/pro/`, is imported only through `src/pro/index.ts`, and renders only behind the build-time `IS_PRO_BUILD` with a ternary. `<ProGate>` alone ships Pro code into Lite and the validator misses it.
3. Only `LedgerService` writes ledger rows, balances and lots, inside `Transaction::run`, with a key from `IdempotencyKey`. Construct it only in the five places its docblock lists. Money is a string; dates are stored UTC.
4. Hook a Pro gate or handler before `RedemptionService::build()` / `EarningService::build()` run, or it is never applied.
5. Migrations in `Installer::run_migrations()` must be idempotent: every version bump re-runs install.
6. Keep Pro values recognisable in Lite (`all_known()`), so a downgraded site doesn't reject stored Pro rows.

## Tests
Warranted for money and ledger logic (points math, `LedgerService` writes and balances) and for a fixed bug a test reproduces cheaply. PHP unit tests are in `tests/unit/` (base `EngineTestCase`), integration in `tests/integration/` (base `IntegrationTestCase`, scratch table prefix `ylit_`), SPA tests in `apps/admin/tests/`, browser specs in `apps/e2e/`. Run one with `composer test -- --filter <Name>`.

## Read when…
- adding any function, hook or setting → [docs/knowledge/helpers.md](docs/knowledge/helpers.md) (reuse first)
- changing boot, tables, options, meta, REST, earning, redemption, expiry, emails, storefront or the Pro seams → [docs/knowledge/architecture.md](docs/knowledge/architecture.md)
- changing the admin SPA, its loading, API client or edition gate → [docs/knowledge/admin-spa.md](docs/knowledge/admin-spa.md)
- touching `scripts/`, the release, the admin shell, `vendor-prefixed/` or CI → [docs/knowledge/build-release.md](docs/knowledge/build-release.md)
- adding a money, date or Pro field, a TypeScript copy of a PHP shape, or a test → [docs/knowledge/conventions.md](docs/knowledge/conventions.md)
- touching HPOS, block checkout, coupons, emails or YayMail/YayPricing/YayCurrency → [docs/knowledge/integrations.md](docs/knowledge/integrations.md)
- a command fails or something behaves oddly → [docs/knowledge/known-issues.md](docs/knowledge/known-issues.md)
- changing an architectural choice → [docs/knowledge/decisions.md](docs/knowledge/decisions.md)
<!-- yay:project:end -->
