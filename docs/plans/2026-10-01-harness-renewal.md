# Harness renewal plan — YayLoyalty

**Status:** active · planned 2026-10-01 against `dev/edd` @ `a8d6fe0`
**Purpose:** the one plan for replacing this repo's agent/developer harness. One phase = one focused session = one PR.
**Update when:** a phase starts or finishes (tick its checklist, link its PR), a decision changes (§6), or the inventory drifts (§2).
**Location:** `docs/plans/2026-10-01-harness-renewal.md`. The old `plans/` ignore rule hides this path, so P0 committed it with `git add -f`. Once tracked, its edits show in `git status`, but staging them still needs `git add -f` until P2 deletes the rule.

## Context

The harness (`CLAUDE.md`, `.claude/`, `docs/`) is a ClaudeKit-era doc set plus one home-grown skill, and it costs more than it returns:

- **Churn.** Of 164 non-merge commits on `dev/edd` since 2026-08-28, 30 touched only harness docs and 60 code commits also had to edit them.
- **Broken instructions.** `CLAUDE.md` makes every session read about 580 lines up front. It points at `/yay:code-corrector`, a skill name Claude Code does not register.
- **Code coupled to docs.** 30 code comments cite doc files or `§` numbers.
- **Traps for any successor.** `.gitignore` hides `docs/plans/` and all of `.claude/`, and two of the three target rule globs match zero files (§2.6).

Outcome: a short, progressively disclosed harness, built from a fresh code audit. Every command is verified, every link resolves, and a developer who did not build it can use it.

---

## 1. Summary

**Goal.**
1. Delete the old harness and every reference to it.
2. Build the target structure (§4) phase by phase, from audits of the real code.
3. Prove a newcomer can follow it.

**Scope (approved).**

| Path | Treatment |
|---|---|
| `CLAUDE.md`, `.claude/**`, `docs/**` | old harness: delete (P2), rebuild (P3–P9) |
| `README.md` | old harness: strip dead references (P2), rewrite as the human front door (P11) |
| `includes/Engine/{Earning,Points,Redemption,Rewards}/README.md` | old harness: delete (P2) |
| `scripts/check-docs.sh` | old harness: delete (P2); new checks built in P12 |
| `.gitignore` lines 23–28 (AI block) | old harness: delete (P2); personal-file ignores added in P7 |
| Code comments, `phpcs.xml.dist`, `scripts/run.sh`, `scripts/validate-build.php` | inbound references only: comment and message edits (P2) |
| `AGENTS.md`, `.github/`, CI, `.cursor*`, `.windsurf`, `GEMINI.md`, `.clinerules` | absent on all 31 branch refs; nothing to clean; not added (Claude Code only) |

**Non-goals.**
- No application behaviour changes; P2 edits are comment- and message-only.
- No fixing pre-existing lint or type failures: "green" means no regression (A3).
- No CI.
- Nothing ported from the old harness (§5). The `code-corrector` skill is **removed**, not rebuilt (Q1).

---

## 2. Current-state findings

### 2.1 Stack (from code and config)

- **Plugin.** Entry `yayloyalty.php`: v1.0.0, text domain `yayloyalty`, PHP ≥ 7.4, WP ≥ 6.5, WC ≥ 10.0 (tested to 10.7.0).
- **Boot.**
  - `plugins_loaded`@5 boots AdminShell and registers `YayLoyaltyPluginAdapter.php`.
  - @10 `plugin_init()` → `YayLoyalty::initialize()` (`includes/YayLoyalty.php`). The constructor builds RestAPI, RegisterFacade, Settings and Emails; the Pro seam is at `:28-32`.
  - @20 `Installer::maybe_upgrade` and `Capabilities::migrate`.
- **Autoload.**
  - A custom loader maps `YayLoyalty\X\Y` → `includes/X/Y.php`, guarded by `file_exists` (`yayloyalty.php:64-82`). That guard is why the Lite strip is safe.
  - Composer is classmap-only, for `vendor-prefixed/src` and the adapter (`composer.json:28-33`).
- **Shared package (the target's "monorepo sync / shared packages").**
  - `yaycommerce/admin-shell` comes from a private VCS repo and is locked at 2.8.5.
  - `vendor/bin/yaycommerce-update` scopes it with php-scoper (prefix `YayLoyaltyScoped`) into `vendor-prefixed/`. It also rewrites `composer.json` and `scoper.inc.php`.
  - It runs on every `./scripts/run.sh dev`, after a `composer update`. Release runs the `yaycommerce-prerelease` variant, which ends in `composer install --no-dev`.
- **Layers.**
  - REST namespace `yayloyalty/v1`. Writes run inside `Transaction::run` (`Controllers/BaseRestController.php:58-114`).
  - `LedgerService` is the sole writer of the ledger and customer aggregates. Repositories own one table each. Models are typed rows. Enums are PHP 7.4 constant classes.
  - `Engine/{Points (pure), Earning, Rewards, Redemption (scaffold), Emails, Admin, Register}`.
  - 7 capabilities, checked only at the HTTP boundary (`Access/Capabilities.php`).
- **Data.**
  - 5 installed tables: customers, ledger, campaigns, rewards, customer_meta (`Database/Installer.php:31-46`).
  - `yayloyalty_db_version` holds the plugin version; any bump re-runs `install()`.
  - redemptions is designed but not installed (`Schema/RedemptionsTable.php` throws). referrals, lots and tiers are not built.
- **Pro/Lite.**
  - PHP: `includes/Pro/` attaches via 8 `yayloyalty_*` filters plus 2 WooCommerce filters (`Pro/YayLoyaltyPro.php:69-78`).
  - SPA: `BUILD_TYPE` → `VITE_IS_PRO` → `IS_PRO_BUILD` (`apps/admin/src/lib/is-pro-build.ts`). `src/pro/index.ts` is the only import surface and carries a sentinel.
  - Release removes `includes/Pro/` for Lite (`scripts/run.sh build_package`). `scripts/validate-build.php` enforces the split, the manifest, the single chunk and an optional WP-CLI boot.
- **Admin SPA** (`apps/admin`, package `@yayloyalty/admin-page`).
  - React 18.3, Vite 6.4 (root `src`, dev :3030, single entry → `assets/dist/admin/js/main.js`), TS 5.9 strict, react-router 7 (hash).
  - TanStack Query 5, ky, zod 3, react-hook-form, Radix UI, Tailwind 4 scoped to `.yay-ui`, `@wordpress/i18n`.
  - Loaded by `Engine/Register/RegisterDev.php` (Vite server, when `YAYLOYALTY_IS_DEVELOPMENT`, which **defaults to true**) or by `RegisterProd.php`.
- **Tests.**
  - PHPUnit 9.6 unit (no WP; shims in `tests/bootstrap.php`).
  - Integration needs a real WP: `YAYLOYALTY_WP_ROOT`, optional `YAYLOYALTY_TEST_SOCKET`, scratch prefix `ylit_`, plugin active, CREATE/DROP rights (`tests/integration/bootstrap.php`).
  - Vitest includes `tests/**/*.test.ts`. **Test count is zero:** `a8d6fe0` deleted 65 PHP and 11 Vitest test files.
- **Lint.**
  - phpcs: WordPress-Extra + PHPCompatibilityWP `7.4-`. It scans `includes`, root PHP and `tests`, not `scripts/`.
  - ESLint 9 flat config.
  - Prettier 3 (width 120, import-sort and tailwind plugins).
- **i18n.** `pnpm makepot` excludes `apps/`, so JS strings come from the built bundle: build first.
- **Missing tooling.** No CI, no git hooks, no `.editorconfig`, no `.nvmrc`/`engines`. `composer.lock` and `pnpm-lock.yaml` are gitignored.

### 2.2 Commands and today's result

Environment: 2026-10-01 @ `a8d6fe0`, PHP 8.3.32, Composer 2.6.5, Node 22.22.2, pnpm 11.15.1, WP-CLI 2.9.0, Claude Code 2.1.286.

| Command | Defined in | Result today |
|---|---|---|
| `pnpm install` | root `package.json` (`postinstall: composer install`) | works on Node 22. **pnpm fails on the shell default Node 20.16** (`ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING`) |
| `composer test` | `composer.json` → `phpunit.xml.dist` | exit 0, "No tests executed!" |
| `composer test:integration` | `phpunit-integration.xml.dist` | bootstrap: "Error establishing a database connection" (needs a reachable site DB) |
| `composer lint` | `phpcs.xml.dist` | **exit 1**: 0 errors, 7 warnings in 3 files |
| `pnpm --filter @yayloyalty/admin-page lint` | `apps/admin/package.json` | **fails**: eslint 1 error and 39 warnings; prettier 30 files, plus 5 dead `importOrder*` options |
| `pnpm --filter @yayloyalty/admin-page test` | vitest | 0 test files; exits non-zero by default (inferred; P2 baseline records it) |
| `pnpm exec tsc --noEmit` (in `apps/admin`) | first half of `build` | **3 × TS2307** (`@emoji-mart/data`, `@emoji-mart/react`, `@radix-ui/react-progress`), so `./scripts/run.sh build` and `release` fail |
| `./scripts/run.sh dev` | `scripts/run.sh` | not run: `composer update`, rewrites `composer.json`, `scoper.inc.php` and `vendor-prefixed/` |

### 2.3 Old harness inventory

| Path | Lines | Origin | Holds | Fate |
|---|---|---|---|---|
| `CLAUDE.md` | 7 | hand-written | read-order, comment rule, skill pointer | delete P2 → new P3 |
| `.claude/.env.example` | 100 | ClaudeKit template, force-added in #28 | unrelated API keys; nothing uses them | delete P2 |
| `.claude/skills/code-corrector/` (`SKILL.md`, `evals/cases.md`) | 79 + 211 | team, 2026-09-24 | duplicate-rule finder. Reads `code-standards` §16/§17. Name `yay:code-corrector` is invalid (colon; ≠ dir). Hard-codes the nvm path and `dev/edd`. Eval case 7 no longer applies. | delete P2; not rebuilt (Q1) |
| `docs/` (15 files) | 3,987 | ClaudeKit slots + hand-written specs | rules, code maps, data-model specs, runbook, roadmap, changelog, 2 scout reports. Many stale claims (e.g. Pro seam "in `initialize()`"; stubs that are now implemented). | delete P2 (tag `harness-v1-final`) |
| `README.md` | 145 | ClaudeKit-style, hand-edited | status, setup, release, Pro list, checks | strip P2 → rewrite P11 |
| `includes/Engine/*/README.md` (4) | 242 | hand-written | module invariants; ship inside release zips | delete P2 |
| `scripts/check-docs.sh` | 86 | hand-written | enforces the old docs layout | delete P2 |
| `.gitignore` 23–28 | 6 | ClaudeKit | `plans/`, `**/.claude/*`, `!/.claude/skills/code-corrector/`, `.opencode/`, `.repomixignore` | delete P2 |

### 2.4 Inbound references outside the harness (the P2 edit list)

| Group | Where (path:line) | Cites | P2 rewrite |
|---|---|---|---|
| PHP comments citing `code-standards` (paths under `includes/`) | `YayLoyalty.php:28`, `Pro/YayLoyaltyPro.php:27`, `Helpers/DesignSchema.php:83`, `Controllers/DesignRestController.php:13` (§9), `Database/InitYayLoyaltyCustomer.php:27`, `Models/Redemption.php:11`, `Repositories/AbstractRepository.php:15`, `Repositories/CustomerRepository.php:204`, `Services/CustomerActivityService.php:19`, `Engine/Points/Triggers/PurchaseHandler.php:106` | §5 §6 §8 §9 §13 §16 | drop the pointer; keep or restate the *why* in ≤ 1 line |
| PHP comments citing data-model and archive (under `includes/`) | `Services/LedgerService.php:20, :375`, `Database/Schema/{CustomersTable:13, CustomerMetaTable:13, LedgerTable:15, RedemptionsTable:7}`, `Controllers/{RedemptionsRestController:11, ReferralsRestController:11, CustomerItemFormatter:48}` | data-model/*, archive scout | drop the pointer; unbuilt tables read "designed, not built yet" (A2) |
| TS comments | `lib/helpers/format.helper.ts:87`, `lib/schema/campaign-performance.schema.ts:126`, `lib/schema/{customer:44, referral:10, redemption:10}.schema.ts`, `pages/customers/drawer/{CustomerDrawerExpiryPanel.tsx:20, CustomerDrawerTierCard.tsx:10, customer-drawer.activity-rows.ts:42, customer-drawer.campaign-names.ts:17}`, `pages/customers/components/adjust-points/AdjustPointsOptions.tsx:43, :64` (all under `apps/admin/src/`) | §4, data-model, roadmap, an uncommitted "brief" | same rules |
| Feature IDs (A9) | `TODO(F08)` ×37 in 14 PHP files (`Engine/Redemption/*`, `Engine/Rewards/{ContextFactory,Handlers/FlexibleDiscountHandler}`, `Database/Schema/RedemptionsTable`, `Repositories/RedemptionRepository`, `Services/LedgerService`, `Engine/Earning/Listeners/OrderReversalListener`). Bare `F08` ×2: `Database/Schema/RedemptionsTable.php:8`, `Repositories/RedemptionRepository.php:13`. `F01-4` ×4: `campaign-performance.schema.ts:20`, `pro/campaigns/performance/CampaignPerformanceSidePanel.tsx:111`, `Pro/Controllers/CampaignPerformanceRestController.php:63`, `Pro/Repositories/CampaignPerformanceRepository.php:66` | IDs defined only in the roadmap and changelog | `TODO(F08)` → `TODO(redemption)`. Bare `F08`: reword without the ID. `F01-4`: drop the ID. The earning engine has shipped, so correct or delete the stale clause and list it in the PR. |
| Config and scripts | `phpcs.xml.dist:3, :66`; `scripts/run.sh` help ("See docs/deployment-guide.md."); `scripts/validate-build.php:337` (§13), `:435` (SKIP message) | code-standards, deployment-guide | self-contained wording |
| README | lines 70, 78–83, 118, 122, 124–125, 131 | 9 doc links, check-docs, `/yay:code-corrector` | delete those lines |
| **Not references (keep)** | `§1–§6` in `apps/admin/src/pro/campaigns/editor/{CampaignEditorSections.tsx, campaign-editor.form.ts}` and `pro/campaigns/add/campaign-templates.ts` | the editor's own section numbers | — |

Package manifests, templates and CI: no references; there is no CI.

P0 re-sweep (2026-10-01 @ `a8d6fe0`): §2.3 and every row above match HEAD. One correction: the first sweep counted `TODO(F08)` as ×39 in PHP. Two of those are in `includes/Engine/{Redemption,Rewards}/README.md`, which go with the READMEs, and it missed the 2 bare `F08`, so the total stays 43. C5 reports `docs/points-engine-scout.md` and `docs/redemption-scout.md` as missing. Both come only from `scripts/check-docs.sh:57`, which P2 deletes.

### 2.5 Workflow facts (confirmed)

- Branch `<name>/<type>/<topic>` from **`dev/edd`** (long-lived) → PR into `dev/edd` (squash) → `dev/edd` → `main` by PR (merge commit).
- Commits use Conventional Commits `type(scope): summary`. Docs and refactor commits have also landed directly on `dev/edd`. From now on, every change goes through a PR (Q7).
- No CI. `gh` is unauthenticated on this machine, so branch protection and reviewers are unknown. CLI `git fetch` and `git push` also return 403 here: the keychain token belongs to an account without access. Pushes go through GitHub Desktop, so phase PRs are opened from there or from the web.

### 2.6 Traps the new harness must avoid (each verified)

1. Unanchored `plans/` in `.gitignore` ignores `docs/plans/`; `**/.claude/*` ignores every new `.claude/` file.
2. Target globs `**/src/admin/**` and `**/build/**` match **0** tracked files (checked with `git ls-files ':(glob)…'`).
3. `@path` imports load at launch and "don't reduce its context cost" (memory docs).
4. If a rule's YAML frontmatter fails to parse, Claude Code loads the rule **as if it had no `paths`**: silently global.
5. pnpm 11 needs Node ≥ 22. `pnpm exec`/`run` auto-installs when `node_modules` is stale, and the root `postinstall` then runs `composer install`. A "read-only" check can rewrite `vendor/` and `node_modules/`. This happened on 2026-10-01: `pnpm exec tsc` installed the missing `@phosphor-icons/react` and regenerated `vendor/composer/autoload_*`.
6. `composer.lock` and `pnpm-lock.yaml` are untracked, so teammates resolve different versions.
7. The dev toolchain needs PHP ≥ 8.2 (php-scoper 0.18) while the product targets 7.4.
8. Skill `name:` must be `[a-z0-9-]` and match its directory. Namespaced `x:y` names exist only for plugin skills.
9. `includeGitInstructions: false` (A7) also removes Claude Code's built-in git safety guidance, so `CLAUDE.md` must restate the git rules the team wants.
10. The skill listing that Claude Code puts in context cuts every description after 511 characters. This was measured on 2.1.286's listing and is the same for every skill. Text past the cut never reaches the model, "Do not use" clauses included.
11. When Claude Code 2.1.286 loads a skill, it strips the frontmatter and keeps HTML comments, unlike rules and `CLAUDE.md`. A header in an HTML comment would reach the model on every invocation. A YAML comment in the frontmatter doesn't, and the skill still parses (P9 probes).

### 2.7 Outside scope — found during the audit, for the app backlog

- `YAYLOYALTY_IS_DEVELOPMENT` defaults to true (`yayloyalty.php:59-62`). A shipped zip shows a blank admin unless the site defines it false.
- Every install seeds 5 demo customers (`Installer.php:58`).
- The second-copy guard doesn't return (`yayloyalty.php:25-33`).
- i18n path bugs (`I18n.php:18-19`); a missing text domain in `UpgradeToPro.tsx:33`.
- JSX runtime mismatch between tsconfig and Vite; unused dependencies.
- Comments still cite deleted tests (`tests/bootstrap.php:13-21`).
- About 35 comments under `apps/admin/src/pages/customers/` and in `lib/schema/customer.schema.ts` name UI parts by design-artboard IDs (`3d`, `4a`, `5c`, …). The design file is not in the repo. (Found in P2.)

Found in P4:

- **Pro offers what nothing can award or read.**
  - Pro offers campaign triggers (birthday, first order, order count, spend milestone) and reward types, but nothing subscribes to `yayloyalty_register_trigger_handlers`, `yayloyalty_register_reward_handlers` or `yayloyalty_order_award_triggers`.
  - Nothing calls `RewardType::all()`.
  - So a Pro birthday campaign saves but never awards (`Pro/YayLoyaltyPro.php:43-48`, `Engine/Points/Triggers/Registry.php:49`).
- **Comments promise Pro subscribers that don't exist:** `YayLoyalty.php:34` (earning gates), `Installer.php:27` (table schemas), `OrderListener.php:163` (award triggers).
- **The validator is weaker than its comments.**
  - The sentinel proves only that `main.tsx`'s branch folded. `pro/index.ts:2-10`, `DesignSettingsPage.tsx:92-94` and `validate-build.php:228` overstate it.
  - Zero Pro references passes, despite the "Not zero" comment (`validate-build.php:320, :327`).
  - The inline-field check passes when a file is renamed (`:390-392`).
  - The WP-CLI boot check's `/pro|premium/i` (`:510`) matches Lite's own design keys `signed_out_prompt` and `tier_progress_line`, so it fails whenever enabled.
  - The single-chunk check globs `js/`, but with `assetsDir: ''` a side chunk would land outside it.
- **Network-admin detection is broken in the scoped copy.** php-scoper reads `scoper.inc.php`'s `wp_.*`-style exclude-function patterns as literal names, because they have no delimiters. The scoped admin-shell therefore calls `\YayLoyaltyScoped\is_network_admin()` (`vendor-prefixed/src/Support/AdminContext.php:53`). The template comes from admin-shell's own `bin/` scripts.
- **Pro behaves like Lite in admin-shell.** Both zips ship the same "Lite" adapter, so Pro has no licence or updater and still shows "Go Pro" (`YayLoyaltyPluginAdapter.php`).
- **Nothing is removed on uninstall.** `Capabilities::uninstall()` has no caller, so tables, options, capabilities and demo users remain.
- **Capabilities.**
  - Every version bump re-grants defaults that a merchant removed.
  - If the first install ran before WooCommerce created `shop_manager`, that role gets nothing until the next bump (`Access/Capabilities.php:63-101`).
- **The SPA's capability handling.**
  - The SPA never gates on `MANAGE_SETTINGS` or `RUN_TOOLS`, so a VIEW-only user can press Save and get a 403.
  - `wp_localize_script` turns `ceiling` and `isAdministrator` into strings, but `types/localize.type.ts` declares them a number and a boolean.
- **The campaign cache can serve stale rows.** `CampaignRepository::flush_cache()` runs inside the write transaction, not after commit, so a concurrent read can re-cache pre-commit rows for an hour.
- **Over-long input returns 500, not 400.** Campaign names and adjustment notes have no `maxLength`, so text longer than their `varchar` columns errors out.
- **`Transaction::run` loses the original exception** when `rollback()` itself throws (`Database/Transaction.php:42-47`).
- **Dev mode differs from prod.**
  - The prod stylesheet is enqueued beside Vite's CSS.
  - React and `@wordpress/date` are bundled rather than external.
  - The refresh preamble and the `yay-ui` body class are added on every admin screen.
- **Stale comments:**
  - `Pro/YayLoyaltyPro.php:31` says "initialize()".
  - `Helpers/LocalizeHelper.php:44` says "one zip carries both".
  - `scripts/run.sh:217` and `:372-374`.
  - `Controllers/CampaignRestController.php:318-322` says listeners "aren't registered at boot"; they are.
  - `Engine/Earning/Listeners/OrderListener.php:56, :87` cite the deleted Earning README. Fixed on 2026-10-03, before P12: each comment now states the reason the README gave, and C2 also matches `README invariant`.
  - `Controllers/SettingsRestController.php:9` gives the wrong route; it is `/mark-reviewed`.

Found in P5 (gaps against `docs/conventions.md`):

- **Mirror direction.**
  - `CustomerRowFilters.php:11`, `CustomerRowSort.php:7` and `CustomerListFormatter.php:67-68` say the PHP mirrors the TS.
  - `CampaignPerformanceRestController.php:68` says it mirrors the SPA's constant, while `campaign-performance.schema.ts:24` says the opposite.
- **TS copies that name no PHP owner:** `dashboard-notice.schema.ts`, `emails.schema.ts`, `FORBIDDEN_ERROR_CODES` in `access.tsx`, `types/localize.type.ts`, `DASHBOARD_RANGES` in `dashboard.schema.ts`, and the customer filter and sort unions in `customer.schema.ts`.
- **Money.**
  - `DashboardFixture.php:168` rounds to a fixed 2 decimals, and `dashboard.schema.ts:31` says "2 dp".
  - `parseWPCurrency` (`format.helper.ts:56-58`) runs `parseFloat` on string money.
  - `Engine/Rewards/Result.php` holds value and rate as floats, while `Redemption` stores them as strings.
- **Dates.** The drawer's activity filters take UTC day bounds built from browser-local picker dates (`ActivityRestController.php:21-22`, `customer-drawer.tab-query-state.ts:58-59`). Reports and daily caps use site-calendar days.
- **Comments.**
  - No purpose docblock: `Engine/ActDeact.php`, `Enums/CustomerFilters.php`, `Utils/SingletonTrait.php`.
  - 4 bare `TODO:`.
  - About 105 SPA placeholder strings without `translators:`; no lint rule checks them.
  - 48 `phpcs:ignore` without a reason.
  - Design-artboard IDs on about 84 lines (the P2 bullet above).
- **The current user below the HTTP boundary:** `CustomerActivityService.php:67` and `DesignHelper.php:59` read it.

Found in P7:

- **Capability refusals toast twice.** The client's `afterResponse` hook already toasts them, and `toastApiError()` skips them. Seven call sites use `toast.error(await getApiErrorMessage(…))` instead, among them `CustomerDrawerActions.tsx` and `customers-page.bulk-actions.ts`.
- **Three API modules cast instead of parsing.** `settings.api.ts`, `design.api.ts` and `emails.api.ts` use `.json<T>()`. `customer.api.ts` explains why a cast is unsafe.
- **WordPress script dependencies.**
  - `@wordpress/date` is missing from `externalDev`, so the dev server bundles its own copy, and WordPress's date settings never reach it. This is inferred from the config, not reproduced.
  - `wp-components` and `wp-hooks` are enqueued and mapped as externals, but nothing imports them.
  - The image picker calls `window.wp.media`, but nothing in the plugin calls `wp_enqueue_media()`.
- **Couplings with no comment.**
  - `scripts/validate-build.php` reads the upsell sentence in `UpgradeToPro.tsx`, and reads `DesignStorefrontTextSection.tsx` and `DesignVisibilitySection.tsx` by path. None of the three files says so.
  - The rules now state this from both sides.
- **A rule no doc sets.** Comments in 12 files cite a "200-line cap" that only the removed standards set.

---

## 3. Research findings

Official docs (code.claude.com/docs, fetched 2026-10-01) and practitioner sources.

| # | Finding | Consequence |
|---|---|---|
| R1 | CLAUDE.md: "target under 200 lines". Include commands, non-default style, etiquette, gotchas. Exclude what code shows; "Detailed API documentation (link to docs instead)". "If an entry … only matters for one part of the codebase, move it to a skill or a path-scoped rule." (memory, best-practices) | < 100-line index; rules for scoped guidance |
| R2 | `@imports` "are expanded and loaded into context at launch" and "don't reduce its context cost". Max 4 hops; ignored inside code spans. | **A5**: plain links, no imports |
| R3 | Rules: only `paths` is read (YAML list or comma string, brace expansion). They "trigger when Claude reads files matching the pattern". Unscoped rules load at launch. A parse failure loads the rule unscoped. | Remapped globs (A10) + load proof (C11) |
| R4 | Block-level HTML comments in CLAUDE.md are stripped before injection | CLAUDE.md's purpose/update-when header costs 0 tokens |
| R5 | `.claude/settings.json` = team permissions, hooks and plugins. `settings.local.json` is added to global git excludes only when Claude creates it. `permissions.allow` waits for folder trust. | P7 ignores; P8 settings |
| R6 | Hooks are deterministic; exit code 2 blocks. The `InstructionsLoaded` event reports `load_reason` (`session_start`, `path_glob_match`, …). | Guard hook (A7); rule-load proof (C11) |
| R7 | `/doctor prompt-audit` (≥ 2.1.283) flags references to missing files or commands and contradictions. `claude doctor` validates settings. `--setting-sources project` loads only project settings (C11 records any user-scope memory that still loads). | P12 validation, upkeep routine |
| R8 | Skills: `.claude/skills/<name>/SKILL.md`; the name defaults to the directory; `disable-model-invocation` is for side-effect workflows; descriptions are always in context | Few, specific skills; descriptions ≤ 500 characters (§2.6) |
| R9 | Context engineering: "just in time … lightweight identifiers (file paths …)"; "CLAUDE.md files are naively dropped into context up front" (anthropic.com/engineering/effective-context-engineering-for-ai-agents) | Docs reached by path when relevant |
| R10 | HumanLayer, "Writing a good CLAUDE.md" (2025-11-25): task docs in separate files, listed in CLAUDE.md with one-line descriptions; "Prefer pointers to copies"; root file < 60 lines | Same pattern; `file:line` pointers |
| R11 | ADRs: Nygard's Context / Decision / Status / Consequences; accepted ADRs are superseded, never edited (cognitect.com 2011; adr.github.io/madr) | ADR format (P6) |

---

## 4. Target structure (final)

`Δ` = an approved change from your template.

```
yayloyalty/
├── CLAUDE.md                  < 100 lines: what · commands · hard rules · git rules · where to look   (Δ1: plain "read when…" links, no @imports)
├── README.md                  human front door: onboarding · workflow · agent tooling · harness upkeep   (Δ2: in scope)
├── .gitignore                 AI block removed; personal Claude files ignored   (Δ3)
├── .claude/
│   ├── settings.json          permission allowlist · generated-dir guard · includeGitInstructions:false   (Δ4)
│   ├── hooks/guard-generated-paths.sh                                                                      (Δ4)
│   ├── rules/
│   │   ├── php-standards.md   paths: includes/**/*.php, yayloyalty.php, YayLoyaltyPluginAdapter.php, tests/**/*.php, scripts/*.php   (Δ5)
│   │   ├── react-admin.md     paths: apps/admin/**                                                       (Δ5)
│   │   └── release.md         paths: scripts/**/*, scoper.inc.php                                        (Δ5; P7 note)
│   └── skills/                our own   (Δ6: yay- names and a review skill, Q10)
│       ├── yaybrainstorming/  idea → design doc in docs/specs/
│       ├── yayplan/           design doc → plan in docs/plans/
│       ├── yaytidy/           before a PR: duplicated facts and their owners, plus /simplify; fixes only what the developer picks
│       └── yayplancheck/      implementation → review against its plan, in a fresh subagent
└── docs/
    ├── architecture.md        boot & autoload · admin-shell sync & scoping · layers · data · Pro/Lite · SPA↔WP · build/release · tests
    ├── conventions.md         code and domain conventions not inferable from code
    ├── decisions/             YYYY-MM-DD-<slug>.md (Nygard)
    ├── known-issues.md        symptom · cause · workaround · remove-when
    ├── specs/                 YYYY-MM-DD-<topic>-design.md · Status: proposed | accepted | superseded
    └── plans/                 YYYY-MM-DD-<topic>.md, or YYYY-MM-DD-<topic>/ (README.md + phase files) · Status: active | done | superseded (kept as history)
```

**Owner map — one home per topic** (each PR from P3 on is checked against it):

| Topic | Owner | Others may only |
|---|---|---|
| Daily commands; hard rules; git rules (branching, commits, PR base, safety) | `CLAUDE.md` | link |
| PHP / SPA / release-tooling rules | the matching `.claude/rules/*.md` | link to `architecture.md` sections |
| How the pieces fit | `docs/architecture.md` | link |
| Conventions not in code | `docs/conventions.md` | link |
| Gotchas and today's failing checks | `docs/known-issues.md` | link |
| Why X, not Y | `docs/decisions/` | link |
| Onboarding, review, promotion and release cadence, agent-tooling use, harness upkeep | `README.md` | link |
| Spec and plan formats and Status values; reviewing work against its plan; finding duplicated facts and their owners before a PR; the stacked-branch PR-base rule | `yaybrainstorming` (specs), `yayplan` (plans), `yayplancheck` (reviews), `yaytidy` (duplicates and the PR-base rule) | `CLAUDE.md` one-liner: "act only on active/accepted"; `README.md`: when to use each skill |

---

## 5. What we are leaving behind — and why

| Leaving behind | Why (evidence) | Instead |
|---|---|---|
| The ClaudeKit doc set: codebase-summary, system-architecture, code-standards, deployment-guide, roadmap, changelog | It restates code (trees, class names, counts), so it drifts. 90 of 164 commits had to touch it, and "docs: sync counts, class names…" commits recur (`d98b8c1`). Many claims are stale (§2.3). | `architecture.md` explains how pieces fit and cites paths |
| Mandatory up-front reading | About 580 lines loaded into every session, relevant or not | < 100-line index, path-scoped rules, "read when…" links |
| Code comments citing docs or `§` numbers | 30 pointers; renumbering breaks them silently | comments carry their own *why*; docs cite code, never the reverse |
| Feature IDs (`F08`, `F01-4`) | Defined only in the roadmap and changelog | descriptive TODOs (A9) |
| Status, counts, changelog, roadmap in docs | Rot on every PR; needed `check-docs.sh` to police | git history, PRs; the tag keeps the old text readable |
| Specs for unbuilt tables | Design without code that drifts silently | let go behind tag `harness-v1-final`; fresh spec when built (A2) |
| Module READMEs in `includes/` | Fourth copy of invariants; they ship in release zips | `architecture.md` + code docblocks |
| Old `code-corrector` + §16 owner table | Depends on a doc section; invalid name; machine path | nothing: removed (Q1) |
| ClaudeKit residue (`.env.example`, `plans/`, `.opencode/`, `.repomixignore`, `**/.claude/*`) | A tool this repo doesn't use; made the harness invisible to git | ignore only personal Claude files |

**Do-not-reintroduce checklist** — every construction PR (P3–P11) ticks it in its description:

- [ ] No doc restates what reading the code answers: trees, class or file lists, counts, signatures.
- [ ] No code comment cites a doc path, `§` number or feature ID.
- [ ] No status, progress or counts outside `docs/plans/`.
- [ ] No `@import` in `CLAUDE.md`.
- [ ] No machine-specific path or tool version outside config files.
- [ ] Every doc, rule and skill opens with **Purpose** and **Update when**: an HTML comment in `CLAUDE.md` and the rules, and YAML comments in a skill's frontmatter (§2.6, trap 11).
- [ ] Every topic has exactly one owner (§4); any new doc is linked from `CLAUDE.md` with a "read when…" trigger.

---

## 6. Decisions

**Approved in planning (2026-10-01):**

| ID | Decision |
|---|---|
| A1 | Scope as in §1: README, Engine READMEs, `check-docs.sh` and the `.gitignore` AI block count as old harness |
| A2 | Unbuilt-feature designs are let go. Tag the last pre-removal commit `harness-v1-final`; comments say "designed, not built yet" without a pointer. |
| A3 | "Green build" for P2 = **no regression**: identical baseline counts and exit codes. Existing failures are recorded in `known-issues.md`. |
| A4 | `brainstorming` and `writing-plans` are **our own** skills, written from scratch. Amended by Q10: they are `yaybrainstorming` and `yayplan`. |
| A5 | CLAUDE.md reaches docs through **plain links with "read when…" triggers**, no `@imports`. P12 tests that Claude opens the right doc; a doc it keeps missing may be promoted to an import, recorded in an ADR. |
| A6 | Claude Code only, so no `AGENTS.md` |
| A7 | Committed `.claude/settings.json`: (a) allowlist for `composer lint`, `composer test`, admin lint/test, `tsc --noEmit`, `git diff`, `git log`; (b) PreToolUse guard blocking edits under `vendor/`, `vendor-prefixed/`, `assets/dist/`, `release/`; (c) `includeGitInstructions: false` with Claude Code's **default attribution** kept |
| A8 | Own skills: brainstorming and writing-plans; no others. Amended by Q1: `code-corrector` is removed, not rebuilt. Amended by Q10: the own skills are `yaybrainstorming`, `yayplan` and `yayplancheck`. |
| A9 | Feature IDs become descriptive TODOs. Roadmap and dev changelog are dropped; release notes stay in `readme.txt`. |
| A10 | Rule globs remapped and PHP narrowed (Δ5) |
| A11 | `dev/edd` is the long-lived PR base |
| A12 | Plans and specs are **kept as history** with a Status line that agents check before acting |

**Resolved in P1 (2026-10-01, approver: Trace Bui):**

| Q | Decision |
|---|---|
| Q1 | Remove `code-corrector` for good: no rebuild, no owner list. The four readable-code rules are dropped too. P10 is dropped; A8 amended. |
| Q2 | `YayLoyaltyPluginAdapter.php` joins the `php-standards.md` globs (Δ5). |
| Q3 | Backfill all five candidates in P6: build-time Pro/Lite split with zip validator; `LedgerService` as sole ledger writer with a cached balance; admin-shell scoped through php-scoper; UTC storage; money as decimal strings. The team still confirms each rationale when it is written. |
| Q4 | Trace Bui reviews every phase PR. The P12 validator is named later, before P12 starts. |
| Q5 | Yes: `.nvmrc` (`22`) and `"engines": {"node": ">=22"}` in the root `package.json`, added in P3. |
| Q6 | Every teammate runs Claude Code ≥ 2.1.283. |
| Q7 | PR-only: every change, docs included, goes branch → PR into `dev/edd`. |
| Q8 | Tag push approved. P2 may be prepared before the freeze. Trace announces the freeze ≥ 1 day before P2 merges and pushes the tag (CLI push is blocked on this machine, §2.5). |
| Q9 | Lockfiles stay gitignored, recorded as a known issue in P5. |

**Decided for P9 (approver: Trace Bui; Q10 on 2026-10-01, Q11–Q13 on 2026-10-02):**

| Q | Decision |
|---|---|
| Q10 | The team's own skills are `yaybrainstorming`, `yayplan` and `yayplancheck` (Δ6). Trace drafted all three. The `yay` prefix keeps them apart from personal or plugin skills with the same names, which replaces P9's planned repo-specific descriptions. `yayplancheck` is new: before the next phase starts or the work merges, a fresh subagent checks the implementation against its plan. A4 and A8 are amended. |
| Q11 | (a) The skills use §4's Status words: `proposed`, `accepted YYYY-MM-DD` and `superseded by <link>` for specs; `active`, `done YYYY-MM-DD` and `superseded by <link>` for plans. `CLAUDE.md` stays as it is. |
| Q12 | (a) The skills stay shared across the YayCommerce plugins. This repo's specifics reach them through `CLAUDE.md` and the docs it links, and their files stay repo-neutral. Where the canonical copy lives is not named yet. |
| Q13 | (a) A new decision record covers the skills. The harness record's Status line adds that the new record supersedes its skills decision. |

**Decided after P9 (2026-10-02):**

| Q | Decision |
|---|---|
| Q14 | `yaytidy` joins the own skills; see [the yaytidy record](../decisions/2026-10-02-add-yaytidy.md). Amends A8 and Q10. |
| Q15 | `yayplan` may plan any request without a spec, with the requirements in the plan; see [the plan-without-a-spec record](../decisions/2026-10-02-plan-without-a-spec.md). |

**Decided before P12 (2026-10-03, Trace):**

| Q | Decision |
|---|---|
| Q16 | The stack lands before the teammates' branches. On 2026-10-03, `dev/edd` was still `a8d6fe0`, so the stack merges cleanly, and the local `harness-v1-final` tag is correct. Seven teammate branches conflict with it: `jason/feat/rewards-editor`, `paul/feat/email-points-earned`, `paul/feat/frontend-launcher` and `paul/improve/ledger-queries`, which are live, and `jason/feat/reward-screen`, `jason/feat/campaign-editor-archive-ui` and `jason/dev/campaign-editor-performance-ui`, which are older but still wanted. Each gets a fix list: the conflicts, the new old-harness references to rewrite, and what its old-doc edits must move into the new docs. The freeze message asks them not to merge into `dev/edd` until the stack lands, and the 12 PRs merge in a day or two. |
| Q17 | Trace approves the 12 stack PRs, under Q4. Q4 predates the README's rule that a PR needs a teammate other than its author; the rule applies to every PR after the stack. |
| Q18 | Trace is the code owner who reviews `docs/architecture.md` for P4. |
| Q19 | The stack landed in one PR, not one per phase. P0 and P1 were squash-merged (#45, #57). Two problems then showed up. Every next branch conflicted on the plan file, because a squash commit isn't in the next branch's history. And deleting a merged branch closed the PR stacked on it, rather than retargeting it as §7 assumed. So Trace reverted #45 and #57 and merged the top branch, `trace/docs/harness-p11-readme`, as #59 with a merge commit (`2fbd369`, 2026-10-03). `dev/edd`'s tree is identical to that branch, and every phase's commit, with its audit body, is in the history. This departs from `CLAUDE.md`'s squash rule and §7's one PR per phase, for this stack only. `./scripts/check-harness.sh` passes on `dev/edd`. |
| Q20 | The shared skills' canonical copy is `.claude/skills/<name>/` in this repo; see [the canonical-copy record](../decisions/2026-10-03-shared-skills-canonical-copy.md). Another repo copies a skill whole and never edits its copy. Answers the open part of Q12. Bringing the skills to the sibling repos is a separate plan for each repo, which starts with that repo's `CLAUDE.md`. |
| Q21 | Paul is the P12 validator (V10). Answers the name Q4 deferred. |
| Q22 | The harness and skills records (A1–A12, Δ1–Δ5, Q10–Q15) are consolidated into [the harness and shared skills record](../decisions/2026-10-04-harness-and-shared-skills.md), which supersedes them whole; the canonical-copy record (Q20) stands. [The decision-record practice](../decisions/2026-10-04-decision-record-practice.md) supersedes the format record: records are superseded only whole, and skill internals and process stay out of them. Decided 2026-10-04 after an evaluation of `docs/architecture.md` and `docs/decisions/`. |

---

## 7. Phases

Order: inventory → approval → removal → one phase per section of the new structure → team workflow → validation. Every phase starts from the latest `dev/edd`, ends in one PR into `dev/edd`, and ticks its checklist in this file. Checks `C1–C17` and the baseline script `B` are in the Appendix.

Exception: P1–P5 were prepared on 2026-10-01 as a stack of branches, each on top of the previous one, starting from P0.
1. Open each PR against the previous phase's branch, and merge them in order.
2. After a squash merge, delete the merged branch so that GitHub retargets the next PR to `dev/edd`.
3. Then merge `dev/edd` into the next branch, so its PR shows only its own commit: use the PR's "Update branch" with a merge, or merge locally.
4. Never rebase: the branches are pushed, and rebasing them would rewrite pushed history.

What happened instead is Q19: steps 2 and 3 failed in practice. A squash commit isn't in the next branch's history, so every "Update branch" conflicted on the plan file. And deleting the merged branch closed the next PR rather than retargeting it. For a future stack, either change each next PR's base to `dev/edd` before deleting the merged branch, or land the stack as one PR.

### P0 — Land the plan and freeze the inventory
- **Goal:** this plan is in the repo; the inventory matches HEAD.
- **Prerequisites:** none.
- **Steps:**
  1. Branch `<you>/chore/harness-p0-plan`.
  2. Commit `docs/plans/2026-10-01-harness-renewal.md` with `git add -f`, because `plans/` stays ignored until P2.
  3. Re-run the §2.3/§2.4 sweeps (C2–C5 in report mode) and update the tables for any drift.
  4. Open the PR.
- **Checklist:**
  - [x] plan committed
  - [x] sweeps re-run, §2 updated (§2.4 correction above)
  - [x] PR merged (#45, then landed again with the whole stack in #59, Q19)
- **Acceptance:**
  - `git ls-files docs/plans/2026-10-01-harness-renewal.md` prints the path.
  - `git diff --name-only origin/dev/edd...HEAD` lists only that file.
- **Risks:** `dev/edd` moves daily, so P2 re-sweeps before editing.
- **Effort:** S (≤ 1 h).

### P1 — Design approval
- **Goal:** every pending question in §9 answered; nothing downstream depends on an open decision.
- **Prerequisites:** P0.
- **Steps:**
  1. Walk §9 with the team lead and record each answer, approver and date in §6.
  2. Adjust the affected phase steps.
  3. Name the P12 validator and the reviewers for each phase.
- **Checklist:**
  - [x] §9 empty
  - [x] §6 updated
  - [x] reviewers named (Q4)
  - [x] P12 validator named: Paul (Q21)
- **Acceptance:**
  - §9 has no unchecked item.
  - The diff touches only the plan file.
- **Risks:** stalls. Every question carries a recommended default; timebox to one meeting.
- **Effort:** S.

### P2 — Removal, reference cleanup, no-regression build
- **Goal:** the old harness is gone and nothing points at it. Build, lint and test results are identical to before.
- **Prerequisites:**
  - P1.
  - Before merge: docs freeze announced ≥ 1 day ahead (Q8: Trace). In-flight branches that touch `docs/` resolve by accepting the deletion.
  - Tag push approved (Q8).
- **Steps:**
  1. `pnpm install` (so later pnpm calls don't auto-install), then capture baseline `B` → `before.txt`.
  2. Tag the base commit locally: `git tag harness-v1-final "$(git merge-base HEAD origin/dev/edd)"`. Trace pushes it before P2 merges: `git push origin harness-v1-final`. If `dev/edd` has moved by then, merge it into the stack (never rebase, §7) and move the tag to `dev/edd`'s head just before P2 merges, so the tag still marks the last commit with the old docs.
  3. `git rm` the old harness: `CLAUDE.md`, `.claude/`, `docs/` except `docs/plans/`, `includes/Engine/*/README.md`, `scripts/check-docs.sh`.
  4. Edit every row of §2.4:
     - drop each pointer and keep the *why* in ≤ 1 line;
     - unbuilt designs read "designed, not built yet";
     - `TODO(F08)` → `TODO(redemption)`; the 2 bare `F08` reworded without the ID;
     - `F01-4` dropped and stale clauses fixed;
     - README lines deleted;
     - `phpcs.xml.dist`, `run.sh` help and the `validate-build.php` messages made self-contained;
     - `.gitignore` lines 23–28 deleted.
  5. Capture `B` → `after.txt`.
  6. Run C1–C5, C7 and C8.
- **Checklist:**
  - [x] tag pushed (`harness-v1-final` at `a8d6fe0`, by Trace, Q8)
  - [x] files removed
  - [x] 30 doc pointers, 43 IDs, 5 config/script refs and README lines fixed
  - [x] `.gitignore` block gone
  - [x] C1–C5 clean
  - [x] C7 empty diff
  - [x] C8 shows only allowlisted files
  - [x] team told that `/code-corrector` is gone for good (Q1; in the freeze notice)
- **Notes (2026-10-01):**
  - README lines 122 and 131 carry real content beside the pointer. P2 strips the pointer and keeps the line (§1: "strip dead references").
  - Stale clauses corrected along with `F01-4`: `campaign-performance.schema.ts:20`, `CampaignPerformanceSidePanel.tsx:110-112`, `CampaignPerformanceRestController.php:55-65` (including its "EarningService is scaffold" claim), and `CampaignPerformanceRepository.php:65-67`.
  - Beyond §2.4: "Zone 3" in `Engine/Redemption/CommitWriter.php:23` and `Models/Redemption.php:10` was a redemption design label defined only in the deleted docs. Both now say "cancel or refund".
- **Acceptance:**
  - C1, C2, C3, C4 and C5 print nothing.
  - `diff before.txt after.txt` is empty (C7).
  - C8 lists only `scripts/validate-build.php` (the message string).
  - `git diff origin/dev/edd...HEAD -- scripts/run.sh` touches only the help line.
  - `./scripts/run.sh help | grep -c docs/` = 0.
- **Risks:**
  - modify/delete conflicts on in-flight branches;
  - a comment edit that changes code (C8 guards);
  - no `CLAUDE.md` until P3 (stack P3 on P2 and merge them back-to-back);
  - the tag push is outward-facing (confirm first);
  - until P7, a hand-made `.claude/settings.local.json` isn't ignored, so check `git status` before committing.
- **Effort:** M (½ day).

### P3 — `CLAUDE.md`
- **Goal:** an index under 100 lines that is right for every session: what, commands, hard rules, git rules, where to look.
- **Prerequisites:** P2.
- **Audit first** (record paths in the PR): every script in `package.json`, `apps/admin/package.json`, `composer.json` and `scripts/run.sh`, and its side effects; Node, PHP and pnpm requirements; generated directories; what `validate-build.php` enforces; git history for the conventions.
- **Steps:**
  1. Maintainer header as an HTML comment (purpose, update-when).
  2. **What** in 2–3 lines.
  3. **Commands** with the Node 22 requirement. Add `.nvmrc` (`22`) and `"engines": {"node": ">=22"}` to the root `package.json`, so `nvm use` picks Node 22 (Q5). Each command is run, with its outcome recorded in the PR.
  4. **Hard rules**, ≤ 8, each traceable to code or a validator. Candidates: no edits in generated dirs; pnpm on Node 22; Lite names Pro only through the seam in `includes/YayLoyalty.php`; SPA Pro UI gated by `IS_PRO_BUILD`, never a runtime flag; PHP 7.4-compatible product code; ledger and balances only through `LedgerService`.
  5. **Git**, replacing the built-in guidance per A7:
     - branch naming, base `dev/edd`, Conventional Commits, PR into `dev/edd`; PR-only, never a direct commit to `dev/edd` or `main` (Q7);
     - safety rules: commit or push only when asked; never force-push shared branches; never skip hooks.
  6. **Plans/specs:** act only on `Status: active` / `accepted`.
  7. **Where to look:** only new-harness files that exist (initially `docs/plans/`). The stripped README is not linked until P11.
- **Checklist:**
  - [x] audit recorded (commit body)
  - [x] every command run
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-01):**
  - Outcomes on Node 22:
    - `pnpm install` exits 0.
    - `composer lint` exits 1 (7 warnings).
    - `composer test` exits 0 with no tests.
    - Admin lint exits 1 (eslint: 1 error, 39 warnings).
    - Admin test exits 1 (no test files).
    - `tsc --noEmit` exits 1 with the 3 TS2307.
    - `composer test:integration` prints "Error establishing a database connection" but exits 0.
    - `run.sh build` exits 2 with the 3 TS2307.
    - `run.sh dev` serves `/main.tsx` on :3030. Its `composer update` moved 6 Symfony dev packages in the ignored lock; `composer.json`, `scoper.inc.php` and `vendor-prefixed/` came out unchanged.
    - `run.sh release lite --no-prompt` exits 2 at the same tsc errors, after removing 58 dev packages.
    - B matches the P2 baseline.
    - `vendor/`, `vendor-prefixed/` and `composer.lock` were restored from a backup afterwards.
  - phpcs probe: a file with an enum, `readonly` promotion, union types, `?->`, `match` and named arguments passes PHPCompatibility. Hard rule 4 therefore names review as the only guard.
  - C16 now skips the `dev/edd` branch name and gitignored build output. C11 gives `claude -p` an empty stdin.
  - For P5: in `CLAUDE.md`, replace the `dev`/`release` side-effect notes with links to `known-issues.md` rather than restating them there.
- **Acceptance:**
  - `test $(wc -l < CLAUDE.md) -lt 100`.
  - C6 and C15 clean.
  - C11 shows `CLAUDE.md` loaded at `session_start`.
  - Every command is executed, with its outcome recorded:
    - the commands in `B` match the P2 baseline;
    - `dev` serves on :3030;
    - `build` and `release` fail only with the baseline tsc errors;
    - integration either runs against a site DB or fails with the recorded DB error.
- **Risks:**
  - `run.sh dev` mutates `composer.json` and `scoper.inc.php`; state this next to the command.
  - Node mismatch on teammates' machines: `.nvmrc` covers nvm users (Q5); others switch by hand.
- **Effort:** S–M (2–3 h).

### P4 — `docs/architecture.md`
- **Goal:** how the pieces fit, with every claim cited to a path.
- **Prerequisites:** P3.
- **Audit first:**
  - boot, autoload, the admin-shell sync and what it rewrites;
  - layers and write ownership; installed vs designed tables and the upgrade mechanism;
  - Pro/Lite on both sides and the validator; SPA↔WP (enqueue, localize, dev/prod registrars, REST, caps);
  - the release pipeline; test topology.
- **Steps:**
  1. Write the sections above, each ≤ ~25 lines. No class lists or counts.
  2. Add one "read when…" link in `CLAUDE.md`.
- **Checklist:**
  - [x] audit recorded (commit body)
  - [x] code owner reviewed accuracy (2026-10-04 note)
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-04), code-owner review (Q18):**
  - Every claim was checked against the code at `d49c2a8`. No mechanism was wrong. About 30 claims overstated, were stale or left out a case, and are corrected. The largest:
    - activation does nothing without WooCommerce;
    - `award()` runs the gates and the engine before its transaction;
    - redemption's quote side is built;
    - a release zip needs `YAYLOYALTY_IS_DEVELOPMENT` defined `false`;
    - every version bump re-grants the default capabilities.
  - Three code comments that contradicted the doc are fixed: the earning-gates comment in `includes/YayLoyalty.php`, `Installer::schemas()`, and `InitYayLoyaltyCustomer`'s count of construction sites.
  - Trace signs off by merging the PR from `trace/docs/architecture-review`.
- **Notes (2026-10-01):**
  - Branch `trace/docs/harness-p4-architecture` is stacked on P3, which is not merged yet.
  - Citations give a path and a symbol, never a line number, so routine edits don't make them stale.
  - C2, C5, C6, C9, C15 and C16 print nothing for `docs/architecture.md` and `CLAUDE.md`.
  - **`CLAUDE.md` hard rule 3 corrected.**
    - It said the validator's sentinel check fails a Lite zip that leaks Pro code through `<ProGate>` alone. It doesn't: the sentinel is reachable only through `registerPro()` in `main.tsx`, so it proves only that this branch folded.
    - A Lite build of a scratch copy of `apps/admin` (Vite run directly, outside the repo) with `<ProGate><DesignTypographySection /></ProGate>` shipped that section's strings. It had sentinel 0, the upsell present and one chunk, so it passes every bundle check.
  - **For P5:**
    - `architecture.md` already states three convention candidates as facts of the layering: one repository per table with no transactions, capabilities checked only at the HTTP boundary, and TS schemas naming their PHP owner. `conventions.md` should link to its sections rather than restate them.
    - New known-issue candidates, still to reproduce:
      - On a fresh clone, the root `postinstall` (`composer install`) likely fails: `composer.json` classmaps `vendor-prefixed/src`, which doesn't exist until `run.sh dev` creates it.
      - Each release re-resolves admin-shell and the scoping tools with no lockfile, so the Pro and Lite zips can carry different admin-shell versions.
  - **For P6:** the five Q3 ADRs hold the why; `architecture.md`'s matching sections hold the mechanism and can link to them.
- **Acceptance:**
  - C6 clean; C9 and C16 clean for the files this phase adds (README is checked from P11 on).
  - ≤ 250 lines.
  - Reviewer sign-off box ticked in the PR.
- **Risks:** drifting into a code tour; the reviewer cuts anything a read of the code answers.
- **Effort:** M–L (4–6 h).

### P5 — `docs/conventions.md` + `docs/known-issues.md`
- **Goal:** conventions the code can't show, with team-confirmed rationale, and the gotchas that bite.
- **Prerequisites:** P4.
- **Audit and interview** — candidate conventions, each verified in code and confirmed by the team:
  - money as DECIMAL strings bound `%s`;
  - UTC storage, converted at the edges;
  - TS mirrors name their PHP owner;
  - one repository per table, no transactions in repositories;
  - caps checked only at the HTTP boundary;
  - Pro vocabulary kept in Lite;
  - file-naming patterns;
  - comment policy (the readable-code rules were dropped in Q1).
- **Known issues** — each reproduced now:
  - Node 22 / pnpm 11;
  - pnpm auto-install plus `postinstall`;
  - baseline failures (tsc 3, eslint 1, prettier 30, phpcs warnings → exit 1, dead prettier options);
  - zero tests;
  - integration needs a site DB (Local socket via `YAYLOYALTY_TEST_SOCKET`);
  - `run.sh dev` side effects;
  - release leaves `--no-dev` deps, so run `composer install` after;
  - `makepot` needs a build first;
  - `dev:admin`/`build:admin` don't set `BUILD_TYPE`, so they build Pro unless it is exported;
  - `composer test:integration` exits 0 when the database is unreachable (found in P3);
  - untracked lockfiles.
- **Steps:**
  1. Write both docs. Known-issues entries use the fields Symptom · Cause · Workaround · Remove when.
  2. Add `CLAUDE.md` links. Next to commands that fail today, add "fails today: see `docs/known-issues.md`".
- **Checklist:**
  - [x] each convention confirmed by a named teammate (Trace Bui)
  - [x] each issue reproduced (command + output in the PR)
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-01):**
  - Branch `trace/docs/harness-p5-conventions-known-issues` is stacked on P4.
  - **Conventions**, confirmed by Trace Bui on 2026-10-01:
    - money, dates and time zones, Pro data survives Lite, TypeScript copies name their PHP owner, wire casing, file names and comments;
    - the layering rules stay in `architecture.md`, and `conventions.md` links to them.
    - The audit also offered null-not-zero, "the SPA writes every word" and "copy as whole sentences". These were not adopted.
  - **Reproductions** (Node 22, 2026-10-01).
    - Tools were called directly rather than through pnpm, so nothing reinstalled. A scratch clone stood in for a fresh one.
    - Node 20.16: pnpm dies with `ERR_VM_DYNAMIC_IMPORT_CALLBACK_MISSING`. Node 22: 11.15.1.
    - Fresh clone, `pnpm --filter @yayloyalty/admin-page exec tsc --version`:
      - pnpm ran `pnpm install` (+438 packages);
      - `postinstall` `composer install` then failed with "Could not scan for classes inside "vendor-prefixed/src"": exit 1, and no `vendor/autoload.php`;
      - `./scripts/run.sh dev` repaired the clone, after which `pnpm install` exited 0.
    - `run.sh dev` in the clone:
      - ran a full `composer update`;
      - printed "./composer.json has been updated" and regenerated `scoper.inc.php`, both identical to HEAD;
      - rebuilt `vendor-prefixed/`;
      - its `pnpm dev` re-ran `pnpm install` and `composer install`;
      - Vite was ready on :3030.
    - phpcs: 0 errors and 7 warnings (`NoReservedKeywordParameterNames`) in 3 files, exit 1.
    - ESLint: exit 1, with 1 error (`no-empty`, `api.ts:154`) and 39 warnings: 36 Tailwind canonical-class, 2 unused vars, 1 unused directive.
    - Prettier, run alone: 30 files and 5 unknown `importOrder*` options, exit 1.
    - tsc: 3 × TS2307, exit 2.
    - `release lite --no-prompt` in the clone: removed 58 dev packages, then stopped at the same tsc errors (exit 2) with phpcs and phpunit gone. `composer install` restored them.
    - `composer test`: "No tests executed!", exit 0. Vitest: "No test files found", exit 1.
    - `composer test:integration`:
      - without the socket: the DB error page, exit 0;
      - with the yaydevs socket: "plugin is not active on this site", exit 1;
      - the plugin is inactive there and was left inactive, because activation seeds demo users.
    - A build without `BUILD_TYPE` is Pro: sentinel 1, upsell 0.
    - `makepot` (new): on the existing build it ran out of memory at PHP's default 128M (exit 255). With 1G it found 1,161 strings, 1,100 of them from the bundle. With no build it found 173, none from JS.
    - Lockfiles: the fresh clone resolved 13 of 74 direct JS dependencies and 6 of 59 Composer packages differently from the working copy.
  - **No entry for the blank admin page of a shipped zip** (`YAYLOYALTY_IS_DEVELOPMENT`). It stays in §2.7: reproducing it needs a site install.
  - **Done here:**
    - `CLAUDE.md` links each failing command to `known-issues.md`.
    - The `dev`/`release` side-effect notes became links (P3's note).
    - §7's stacked-merge note now says to merge `dev/edd` into the next branch, not rebase.
- **Acceptance:**
  - C6 clean; C9 and C16 clean for the two new files.
  - Every known-issues entry has all 4 fields (`grep -c` per field = entry count).
- **Risks:** conventions without buy-in get ignored; drop any the team won't defend.
- **Effort:** M (3–5 h + interview).

### P6 — `docs/decisions/`
- **Goal:** an ADR practice with first records.
- **Prerequisites:** P5.
- **Steps:**
  1. `YYYY-MM-DD-record-architecture-decisions.md` defines format, naming, statuses and when to write one.
  2. `YYYY-MM-DD-harness-structure.md` records A1–A12, the P1 answers and Δ1–Δ5, and notes that the old harness is preserved at tag `harness-v1-final`. It names the tag, not individual old files or the removed skill, so C2 stays clean.
  3. Backfill the five app ADRs approved in Q3, each with rationale confirmed by the team: build-time Pro/Lite split; `LedgerService` as sole ledger writer; admin-shell via php-scoper; UTC storage; money as decimal strings.
  4. Add a `CLAUDE.md` link: "read when a change alters an architectural choice".
- **Checklist:**
  - [x] format ADR
  - [x] harness ADR
  - [x] approved backfills (reasons confirmed by Trace Bui)
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-01):**
  - Branch `trace/docs/harness-p6-decisions` is stacked on P5.
  - **Format**, set by the first record:
    - the statuses are `proposed`, `accepted`, `rejected` and `superseded by <link>`;
    - the file date is the day a record is written, and a backfill's Status names the commit that made the decision;
    - an accepted record changes only in its Status line, or in a link that a moved file broke;
    - a record holds the why. `architecture.md` and `conventions.md` keep the mechanism and the rules, and link to it.
  - **Backfills.** Each reason comes from the commit that made the decision and from the code's own docblocks. Trace Bui confirmed them on 2026-10-01:
    - Pro/Lite (`6dda53e`, `8a8a827`): Lite goes to WordPress.org, whose guidelines forbid locked or trial features, and paid code mustn't ship in a free download. Neither reason was written down before; Trace confirmed both.
    - Ledger (`c992f4e`): the cached totals serve reads (the customer list's sorts and filters, the dashboard's sums) and give each write one row to lock. The read side was inferred from the code, and confirmed.
    - admin-shell (`0270fd4`): a YayCommerce-wide standard, taken over from the YayWholesale scaffold.
    - UTC (`b6a4bda`): only the reason quoted from `Dates.php`. A drafted explanation (time zone changes, daylight saving) was dropped.
    - Money (`cab8497`): as drafted.
  - **Links:** `CLAUDE.md` gains a "read when" bullet. `architecture.md`'s Pro/Lite, admin-shell and ledger sections, and `conventions.md`'s money and dates entries, link to their records.
  - **Checks:**
    - C9 now covers `docs/decisions/`, and the new C17 checks record names and headings; P12 bundles it.
    - C16 stays off `docs/decisions/`: a record is never edited, so a path it names may later disappear for good reason.
    - Until P7 and P8 land, the harness record names two paths that don't exist yet: `.claude/rules/` and `.claude/settings.json`.
  - **For P9:** `yaybrainstorming` says when a design needs a decision record of its own (P9, item 9).
  - **For P11:** README's "when to write an ADR" links to the format record, which owns it.
- **Acceptance:**
  - Filenames match `^[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-z0-9-]+\.md$`.
  - Each ADR has Status, Context, Decision and Consequences headings.
  - C6 clean.
- **Risks:** invented rationale; backfill only what the team confirms.
- **Effort:** S–M (2–3 h).

### P7 — `.claude/rules/`
- **Goal:** three rules that load exactly when relevant and only say what isn't owned elsewhere.
- **Prerequisites:** P6.
- **Steps:**
  1. `.gitignore`: add `.claude/settings.local.json`, `CLAUDE.local.md`, `.claude/worktrees/`.
  2. Audit:
     - `phpcs.xml.dist`, PHP 7.4 limits, the layer patterns, the money/date/i18n/caps patterns;
     - ESLint and Prettier config, the `@/` alias, `IS_PRO_BUILD` and the `src/pro/index.ts` import rule, the zod/ky/react-query patterns, the fixture regeneration (`scripts/dump-design-defaults.php`);
     - `run.sh` and `validate-build.php` semantics, version stamping, the Pro → Lite release order.
  3. Write each rule (≤ ~40 lines) with `paths` as a YAML list of quoted globs (A10). Link to `architecture.md` or `conventions.md` sections rather than restating them.
- **Checklist:**
  - [x] ignores added
  - [x] audit recorded (commit body)
  - [x] C10 and C11 pass
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-01):**
  - Branch `trace/docs/harness-p7-rules` is stacked on P6.
  - **How rules load** (checked on Claude Code 2.1.286):
    - A probe transcript shows that Claude Code strips a rule's frontmatter and HTML comments before it injects the rule. Each rule therefore keeps Purpose and Update when in an HTML comment, as `CLAUDE.md` does, and they cost no context.
    - `scripts/**` became `scripts/**/*`.
      - Claude Code reduces a trailing `/**` to the bare directory name, which then matches a `scripts/` directory at any depth. Reading a file in `node_modules/.pnpm/ajv@6.15.0/node_modules/ajv/scripts/` loaded the release rule.
      - `scripts/**/*` is kept as written and stays anchored to the repo root.
      - `apps/admin/**` reduces to `apps/admin`, which still contains a slash, so it stays anchored.
      - A bare file name such as `yayloyalty.php` matches at any depth. No second copy exists in the tree.
    - C11 now also prints each load's parsed globs and trigger file.
    - Beyond C11's four probes:
      - `scripts/validate-build.php` loads both the PHP and the release rule, as accepted below;
      - `yayloyalty.php`, `YayLoyaltyPluginAdapter.php` and `tests/integration/bootstrap.php` load the PHP rule;
      - `scoper.inc.php` loads the release rule;
      - PHP in `vendor-prefixed/` loads none.
  - **Audit.**
    - Two read-only sub-audits swept the PHP and SPA code, and each candidate was then checked in the code.
    - A rule line is one of three things: a coupling no check catches, a reason the code states, or the pattern most of the code follows.
  - **PHP 7.4.**
    - Both phpcs and `php -l` on PHP 8.3 accept every construct the PHP rule lists, `str_contains` included.
    - WordPress 6.5 polyfills only `str_contains`, `str_starts_with`, `str_ends_with` and `array_is_list`. `array_find` and later arrived in 6.8 or after.
    - The Homebrew PHP 7.4 here doesn't start (missing libffi), so no 7.4 parse was run. Each construct is a PHP 8.0 or 8.1 feature.
  - **Release tooling.**
    - `yaycommerce-update` and `yaycommerce-prerelease` rewrite `scoper.inc.php` from their own template, keeping only the prefix and the adapter class.
    - `/bin/bash` is 3.2 here.
    - The validator ran alone on a zip already in `release/` (16 passed, 1 skipped) and changed nothing.
  - **Not reproduced:**
    - Without a terminal, `release` given no version and no `--no-prompt` likely stops at its version prompt. Under `set -e`, a `read` at end of input exits 1, printing nothing.
    - The `read` was reproduced on its own under bash 3.2. This session's permission classifier blocked running the real script.
    - If it's confirmed, the release line in `CLAUDE.md` should tell agents to pass a version or `--no-prompt`.
  - **Not adopted:**
    - "final unless it says why": most classes that aren't final give no reason;
    - "add `import React`": with the classic JSX runtime, files reach WordPress's global React in dev and in prod;
    - one accessor for the localized globals: none exists;
    - regenerating the design-defaults fixture: nothing has read it since `a8d6fe0`.
  - **One coupling, two rules.** `release.md` and `react-admin.md` both state that the validator copies SPA strings and paths, each from its own side, because each rule loads only on its side. A comment in each of the three SPA files would be a better home. That is an app-code change, so it's left to §2.7.
  - **Checks:**
    - C2, C5, C6, C10, C15 and C16 print nothing.
    - C9 flags only `README.md` (P11) and the missing skills (P9).
    - The rules are 38, 29 and 27 lines long.
- **Acceptance:**
  - C10: each glob matches ≥ 1 tracked file.
  - C11:
    - reading `includes/YayLoyalty.php` → `path_glob_match php-standards.md`;
    - reading `apps/admin/src/main.tsx` → `react-admin.md`;
    - reading `scripts/run.sh` → `release.md`;
    - reading `README.md` → no rule;
    - no rule ever at `session_start`.
- **Risks:**
  - A YAML error makes a rule global; C11 catches it.
  - `scripts/*.php` loads both PHP and release rules, which is accepted.
- **Effort:** M (3–4 h).

### P8 — `.claude/settings.json` + guard hook
- **Goal:** the team settings from A7 are committed and verified.
- **Prerequisites:** P7.
- **Steps:**
  1. `.claude/hooks/guard-generated-paths.sh` (bash + jq): resolve `tool_input.file_path` against `$CLAUDE_PROJECT_DIR`; exit 2 with a reason for the four directories.
  2. `settings.json`:
     - `permissions.allow` for exactly the A7 commands, written as in `CLAUDE.md`;
     - a `PreToolUse` hook for `Edit|Write|MultiEdit|NotebookEdit`;
     - `"includeGitInstructions": false`;
     - no `attribution` key (the default stays).
  3. Verify attribution in a scratch branch: if commits lose the default trailer with git instructions off, add the trailer rule to `CLAUDE.md` § Git.
  4. Document in `known-issues.md`: the guard can't see Bash writes; allowlisted pnpm commands may auto-install.
- **Checklist:**
  - [x] hook script
  - [x] settings (written by Trace: the auto-mode classifier refused Claude's write)
  - [x] C13 and C14 pass
  - [x] attribution verified
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-01):**
  - Branch `trace/chore/harness-p8-settings` is stacked on P7.
  - **The hook goes beyond step 1:**
    - It also guards `node_modules/`, at any depth, because hard rule 1 lists it.
    - It reads `notebook_path` as well as `file_path`, because NotebookEdit names its target that way.
    - It resolves `.`, `..` and relative paths, matches a symlinked project root, and strips Claude Code's worktree prefix, `.claude/worktrees/<name>/`.
    - The block message names the command that rebuilds the directory, and tells Claude not to write the file through the shell instead.
    - Without jq it exits 1. Claude Code then shows a hook error and lets the edit through. Blocking every edit instead would stop all work on a machine without jq.
    - Settings run it in exec form, `"command": "bash"` with the script in `args` (Claude Code 2.1.139 or later). The path then needs no quoting, and the script needs no executable bit.
    - 37 piped inputs give the expected exit code on `/bin/bash` 3.2.
    - Not caught: a path that reaches the project through a symlink when the project root is the real path. Claude Code doesn't produce one. Started from a symlink, it resolved its working directory, its file paths and `CLAUDE_PROJECT_DIR` to the real path. Only a symlinked path typed in by hand slips through.
  - **Live guard check:** a scratch `claude -p` in `acceptEdits` mode was asked to edit `vendor-prefixed/src/Probe.php` and `README.md`. The hook blocked the first edit with its message, and the second went through. A run started from a symlinked directory gave the same result.
  - **Settings file:** the auto-mode classifier refused Claude's write of `.claude/settings.json`, because the file grants Claude permissions. Trace created it from the reviewed content, keeping A7's `git diff *` and `git log *` after the `--output` finding below.
  - **Settings, from the docs** (code.claude.com, fetched 2026-10-01):
    - A rule without `*` matches one exact command, and a trailing ` *` also matches the bare command.
    - The rules are the commands as `CLAUDE.md` writes them. The exception is `composer lint *`, which also covers the PHP rule's `composer lint -- <files>`.
    - `git diff` and `git log` are already in Claude Code's built-in read-only set. A7's two rules add only the forms that set prompts for: unquoted globs, and `--output=<file>`, which writes a file.
    - `claude -p` and the SDK apply a project's allow rules only in a folder whose trust dialog was accepted. Project hooks run either way.
    - `includeGitInstructions: false` also drops the git status snapshot at session start: the branch, the main branch offered as the PR base (`main`, not `dev/edd`), the status and recent commits.
  - **Allow rules, checked** with `claude -p --setting-sources project --permission-mode dontAsk`:
    - In this repo, which is trusted, all seven allowed forms ran, `composer lint -- <file>` included.
    - The three pnpm commands carried a `timeout 1` prefix. The matcher strips that prefix, so the rule match is the same, and `timeout` isn't installed here, so pnpm never started and couldn't install.
    - `composer test -- --filter …` and `composer validate` were denied.
    - `git log --output=<file>` ran and wrote the file.
    - In an untrusted scratch clone, Claude Code printed "Ignoring 7 permissions.allow entries … this workspace has not been trusted". It still ran plain `git log` and `git diff`, and it denied the `--output` form.
  - **Attribution** (scratch clones, `claude -p --setting-sources project`, so personal settings didn't apply):
    - `CLAUDE_CODE_DISABLE_GIT_INSTRUCTIONS=1` has the same effect as the key. With it, two scratch commits had no trailer. The control commit ended in `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
    - Asked to quote its context, a session with git instructions off had neither the attribution reminder nor the Bash tool's git section. The control quoted both. So while the key is off, a personal `attribution` setting changes nothing.
    - `CLAUDE.md` § Git now states the default commit and PR lines. With git instructions off, a scratch commit then ended in the same trailer. A scratch `CLAUDE.local.md` that declined attribution left it out.
  - **Known issues:** a new entry for the guard, which doesn't see shell writes and fails open without jq. The pnpm auto-install entry now says the allowlist lets Claude start an install without a prompt.
  - **Checks:**
    - C2, C5, C6 and C15 print nothing.
    - C9 flags only `README.md` (P11) and the skills (P9).
    - C13: `claude doctor` exits 0 and lists no invalid settings. On a scratch file with a string for `includeGitInstructions`, it printed an "Invalid settings" section naming the key.
    - C14 passes in its widened form.
    - C16 prints nothing.
    - `CLAUDE.md` is 55 lines.
    - `known-issues.md` has 14 entries, each with all four fields.
- **Acceptance:**
  - C13: `claude doctor` reports no settings errors.
  - C14: the hook exits 2 for a path in each generated directory, a `..` spelling included, and 0 for `includes/x.php`.
  - A scratch `claude -p` asked to edit `vendor-prefixed/…` is refused.
  - A scratch commit carries the default trailer.
- **Risks:**
  - jq or bash missing on a teammate's OS (onboarding lists them).
  - Losing the built-in git safety guidance (restated in P3).
  - Trust prompt on first run.
- **Effort:** S–M (2–3 h).

### P9 — Skills: `yaybrainstorming`, `yayplan`, `yayplancheck`
- **Goal:** the team's own idea → design → plan → review loop. Specs land in `docs/specs/` and plans in `docs/plans/`, with the Status lines that `CLAUDE.md` acts on.
- **Prerequisites:** P8; Q11–Q13 answered (§9).
- **Drafts (Q10):** Trace drafted all three skills on 2026-10-01. They are untracked in `.claude/skills/`. Each has a `SKILL.md` and skill-creator evals in `evals/evals.json`.
  - `yaybrainstorming`:
    - explores the code, then clarifies in rounds of 2–3 questions with AskUserQuestion, drawing on `references/question-bank.md`;
    - compares 2–3 approaches and presents the design in sections;
    - writes `docs/specs/YYYY-MM-DD-<topic>-design.md` from `references/design-template.md`, reviews it and stops at an approval gate;
    - allows no code before approval. A change that fits in one sentence gets a five-line fast-path doc, never no doc.
  - `yayplan`:
    - plans without touching source;
    - turns a spec into a single-file plan of up to about 5 tasks, or into a directory plan: a `README.md` that holds the interface contract, plus 2–4 phase files, each a vertical slice;
    - gives each task Files and Interfaces blocks and checkbox steps: write the test, run it, implement, run it again, commit (`references/plan-template.md`).
  - `yayplancheck` (new):
    - dispatches a fresh general-purpose subagent with a self-contained prompt;
    - the subagent checks requirements, verification steps, review focus, interfaces, scope and the exit criterion. It returns PASS or FAIL and ties each blocking gap to a plan line;
    - the implementing session fixes the blocking gaps and asks for one more review, then stops.
- **Review of the drafts** (2026-10-01, Claude Code 2.1.286):
  - **Already passing:** C2, C6, C16 and C12's name check. The `.DS_Store` files in two skill directories are gitignored.
  - **Must fix:**
    1. **Status words (Q11).**
       - `design-template.md` writes `draft | approved YYYY-MM-DD`. `CLAUDE.md` acts only on a spec that is `accepted`, so it would treat every new spec as a record.
       - `plan-template.md` has no Status line in either shape, so no yayplan plan is ever `active`.
    2. **C9:** no `SKILL.md` has Purpose or Update when.
    3. **C5:** once the skills are tracked, the eval prompts' example paths, such as `docs/specs/2026-10-01-order-notes-export.md`, are reported missing. Fixed in this revision: C5 now skips `.claude/skills/*/evals/`. It also skips `docs/specs/`, which is history, like `docs/plans/`.
    4. **The harness decision record** says the own skills are `brainstorming` and `writing-plans`, "and there are no others" (Q13).
    5. **Descriptions cut short** (§2.6, trap 10).
       - `yayplan`'s description is 583 characters long and loses "or for executing a plan that already exists".
       - `yaybrainstorming`'s is 525 and loses "and roadmaps".
       - `yayplancheck`'s is 500 and fits.
       - C12 now checks the length.
    6. **Wrong diff base.**
       - `yayplancheck`'s example is `git diff main...HEAD`. Here the base is `dev/edd`, or the previous phase's branch when branches are stacked.
       - Only `CLAUDE.md` names that base. With git instructions off, the session gets no git snapshot (P8).
       - A diff against `main` takes in every commit not yet promoted, so the scope check fails on files the plan never touched.
  - **Should fix**, in wording that holds in any repo (Q12):
    7. **Checks that already fail.**
       - Six commands in `CLAUDE.md` are marked "Fails today". A plan step that expects one of them to exit 0 fails before any change is made.
       - The reviewer prompt counts every failing step as a gap.
       - A general-purpose subagent loads `CLAUDE.md` (probe, 2026-10-01). So the reviewer gets `CLAUDE.md`'s "judge a failing check against `dev/edd`", but its prompt contradicts it.
    8. **Default plan.** Given no argument, `yayplancheck` takes "the newest file or directory under `docs/plans/`". Here that can be this plan, which isn't in yayplan's format.
    9. **Repo docs and decision records.**
       - `yaybrainstorming` step 1 says to read "docs" without naming any, and no step mentions decision records (P6's note).
       - Step 1 should read the docs that `CLAUDE.md` links for the areas the idea touches.
       - When the chosen approach makes an architectural choice, the design names the decision record that the implementing PR adds.
    10. **Commands and commits in plans.**
        - The template's examples are `vendor/bin/phpunit --filter …` and a one-line `git commit -m`.
        - A plan should use the commands in `CLAUDE.md`, or a narrower form of one, such as `composer test -- --filter <name>`.
        - Its commit steps follow `CLAUDE.md` § Git, trailer included.
  - **Noted, not changed:**
    - The drafts are written for any YayCommerce plugin. The question bank asks about sibling plugins, page builders and block checkout. The evals use discount rules, wishlists, coupons and `src/Admin/Settings.php`. Q12 decides whether they stay that way.
    - `yaybrainstorming` gates every behaviour change and refactor, however small. The fast path keeps that cheap.
    - `yayplan` says to search with Grep and Glob. This session's Claude Code offers neither as a tool, so Claude searches through Bash.
    - C16 reads only `SKILL.md`. The templates in `references/` hold placeholder paths, such as `exact/path/to/new-file.php`, on purpose.
- **Steps:**
  1. Branch from P8 (stacked, §7). Stage the skills by name: each `SKILL.md`, `references/` and `evals/`.
  2. Fix items 1, 2 and 5–10 in the drafts, under Q11 (a) and Q12 (a):
     - **Specs:**
       - `**Status:** proposed` in the template, and in the fast-path doc too;
       - step 7 sets `accepted YYYY-MM-DD` at the review gate;
       - when a new spec replaces an older one, its step 5 sets the older one to `superseded by <link>`.
     - **Plans:**
       - `**Status:** active` in the single-file header and in a directory plan's `README.md`. Each phase file links back to its README;
       - the PR that finishes the plan sets `done YYYY-MM-DD`; a replaced plan becomes `superseded by <link>`;
       - when a review of the whole plan passes, `yayplancheck`'s report says to set `done`.
     - **Headers:**
       - Purpose and Update when go in YAML comments inside each `SKILL.md`'s frontmatter, and that one header covers the whole skill directory;
       - they don't go in an HTML comment, as in the rules: a skill keeps its HTML comments when it is invoked (§2.6, trap 11).
     - **Descriptions:** ≤ 500 characters, keeping every "Do not use" clause.
     - **`yayplancheck`:**
       - diffs against the PR's base branch: the one `CLAUDE.md` names, or the previous branch in a stack;
       - doesn't count a check as a gap when it fails the same way on the base;
       - given no argument, reviews the newest plan whose Status is `active`.
     - **`yayplan`:** a step that runs a check that fails today expects "no new failures", compared with the base. Commands and commits follow item 10.
     - **`yaybrainstorming`:** item 9.
  3. Write a decision record for Q10, including Q12's answer. With Q13's recommended answer, the harness record's Status line then says that the new record supersedes its skills decision. C17 passes.
  4. Dry run on a scratch branch with a small YayLoyalty topic. Iterate on the drafts until the outputs pass, then discard the branch:
     - `/yaybrainstorming <topic>` writes a spec with `**Status:** proposed`, and approving it sets `accepted`;
     - `/yayplan <that spec>` writes a plan with `**Status:** active`;
     - once Task 1 is implemented, `/yayplancheck <that plan>` reviews it in a subagent, against the scratch branch's base;
     - a one-line bug fix, asked for separately, takes the fast path.
  5. Optional: run each skill's `evals/evals.json` through skill-creator. The prompts aren't YayLoyalty features, so judge the process, not the facts.
- **Checklist:**
  - [x] three skills drafted (Trace, untracked)
  - [x] drafts reviewed against the harness (above)
  - [x] C5 and C12 updated in the Appendix
  - [x] Q11–Q13 answered
  - [x] branch created (step 1)
  - [x] items 1, 2, 5 and 6 fixed; 7–10 fixed, or declined with a reason
  - [x] decision record written; harness record's Status line updated
  - [x] C5, C9, C12 and C16 pass
  - [x] C17 passes with the new record
  - [x] dry run done and discarded (P12, 2026-10-03)
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-02):**
  - Trace took the recommended answer to Q11–Q13. Where the canonical copy of the shared skills lives (Q12) is not named yet; named in Q20.
  - **Fixes applied** to the drafts, all of items 1, 2 and 5–10, worded to hold in any repo:
    - **Specs:** the template and the fast-path doc start at `**Status:** proposed`. The lifecycle is stated above the template, and steps 5 and 7 set `superseded by` and `accepted`. The hard gate now waits for `accepted`.
    - **Plans:** both shapes open with `**Status:** active`, and the lifecycle is stated above the template. A phase file's first line points to its README. `yayplan` stops on a design doc that isn't `accepted`.
    - **`yayplancheck`:**
      - diffs against `git diff <base>...HEAD`, with the base from `CLAUDE.md` or the stack, and never assumes `main`;
      - with no argument, takes the newest `active` plan;
      - doesn't count a failure that the plan or the docs record on the base as a gap, and doesn't switch branches to check one;
      - its report says to set `done` when the whole plan passes.
    - **`yayplan`:**
      - verification commands come from `CLAUDE.md`, or narrow one of them;
      - a check that already fails expects "no new failures";
      - a commit step names the files and the message, and `CLAUDE.md` supplies the format and trailer;
      - the template runs `composer test -- --filter …`. `composer test` is `phpunit` here, so the filter passes through.
    - **`yaybrainstorming`:** step 1 reads the docs `CLAUDE.md` links. Step 3 and the template's new **Decision record** line name the record that an architectural choice needs.
    - **Descriptions:** 489, 498 and 500 characters. Claude Code reloaded the skills during the session, and the listing then showed all three in full.
    - **Evals:** the expected outputs now include the Status lines, the diff base and the `done` step.
  - **Header placement**, from scratch-skill probes with `claude -p` on Haiku:
    - In the transcript, an invoked skill's text began right after its frontmatter, which was gone. An HTML comment under the frontmatter was kept, word for word.
    - A YAML comment in the frontmatter never appeared in the transcript. The skill still ran, and the listing quoted its description unchanged.
    - So the headers are YAML comments, which cost nothing at invocation and can't be read as instructions for the task. They also say why they sit there, so nobody moves them into an HTML comment.
  - **Checks:** C2, C5, C6, C12 and C16 print nothing for the skills, and C9 flags only `README.md` (P11). The eval files are valid JSON. Each frontmatter parses as YAML, with only `name` and `description` as keys. Both Status greps in the acceptance give 2.
  - **Branch:** `trace/docs/harness-p9-skills` is stacked on P8.
  - **Decision record** (step 3, Q13 (a)):
    - [the shared skills record](../decisions/2026-10-02-shared-design-plan-review-skills.md) records Q10–Q13, the options turned down, the 500-character limit and where the headers sit;
    - the harness record changed only in its Status line, which now says the new record supersedes its skills decision and its other decisions stand;
    - C17, C6 and C9 pass with both files; C9 still flags only `README.md`.
  - **Still to do:** the dry run (step 4).
- **Notes (2026-10-03), the dry run** (step 4, run with P12 as V8(c)):
  - Driven headless with `claude -p` in a fresh clone, on a scratch branch stacked on `trace/docs/harness-p11-readme`. The prompts said to pick the recommended answer wherever a skill would ask a question. Topic: a `wp yayloyalty balance <user-id>` command. The clone and its branches are deleted.
  - `/yaybrainstorming` wrote `docs/specs/2026-10-03-cli-balance-design.md` at `**Status:** proposed`, listed the 10 questions with its choices, and stopped at the gate. Approval set `accepted 2026-10-03`.
  - `/yayplan` wrote a two-task single-file plan at `**Status:** active`, with `**Spec:**`, `## Global constraints`, `## Review focus`, `### Task 1:`, `**Files:**` and `**Interfaces:**`. Its commands are `composer test:integration -- --filter …`, `composer lint -- <files>` and `git grep`, plus `wp` commands for the by-hand checks of the new command.
  - Task 1 was implemented and committed. The integration tests couldn't run, because the clone isn't inside a WordPress site; the session said so and checked the logic with a throwaway script instead.
  - `/yayplancheck` ran the reviewer as a general-purpose subagent on `git diff trace/docs/harness-p11-readme...HEAD`, and didn't ask about yaytidy for a one-task scope. The report opened with `## Verdict: FAIL`, with one blocking gap: the integration tests never ran. That is the right verdict for this checkout. It made no fix and didn't loop.
  - A one-line bug, the missing text domain on `UpgradeToPro.tsx`'s tooltip, took the fast path: a 9-line spec at `proposed`, no interview and no alternatives.
  - Two sessions looked for other Local sites to run the integration suite on, and changed nothing there.
- **Acceptance:**
  - C12: each name equals its directory and matches `^[a-z0-9-]+$`, and each description is ≤ 500 characters.
  - C9 lists only `README.md` (P11). C5 and C16 print nothing, and C17 passes.
  - With Q11 (a):
    - `grep -c '^\*\*Status:\*\* proposed'` on `design-template.md` gives 2: the full doc and the fast-path doc;
    - `grep -c '^\*\*Status:\*\* active'` on `plan-template.md` gives 2: the single-file plan and the directory README.
  - The dry run, in Q11's words:
    - the spec reads `**Status:** proposed`, then `accepted`;
    - grep finds `**Status:** active`, `**Spec:**`, `## Global constraints`, `## Review focus`, `### Task 1:`, `**Files:**` and `**Interfaces:**` in the plan;
    - every command in the plan is in `CLAUDE.md`, or is a narrower form of one;
    - the review ran in a subagent and diffed against the scratch branch's base, and its report opens with `## Verdict:`.
- **Risks:**
  - Output quality: iterate on the dry run.
  - Copies drift: if other YayCommerce repos carry these skills (Q12 (a)), a fix made here has to reach them too.
  - The broad trigger costs a fast-path doc even for a one-line fix. Narrow the description if that proves too heavy.
- **Effort:** S–M (2–4 h). The drafts exist, so most of the work is the fixes and the dry run.

### P10 — dropped (Q1)
`code-corrector` is removed, not rebuilt. The number stays so phase references don't shift.

### P11 — Team workflow docs (`README.md`)
- **Goal:** someone who didn't build this can onboard, work daily, use the agent tooling, and keep the harness current.
- **Prerequisites:** P9.
- **Steps:** rewrite `README.md`:
  - **what** (2 lines) and **requirements** (PHP ≥ 8.2 for tooling and 7.4 for the product, Composer 2, Node 22, pnpm via corepack, WP-CLI, jq, Claude Code ≥ 2.1.283 per Q6);
  - **onboarding:**
    - clone into a WP site's `wp-content/plugins/` with WooCommerce active;
    - GitHub access to the private admin-shell repo;
    - `pnpm install`;
    - `YAYLOYALTY_IS_DEVELOPMENT` and `./scripts/run.sh dev`;
    - first lint and test;
    - first Claude session: trust the folder, see what loads automatically;
  - **daily workflow:**
    - links to `CLAUDE.md` § Git for the rules;
    - the human parts: review expectations, who promotes `dev/edd` → `main`, release cadence (the procedure is in `release.md` and `run.sh`);
  - **agent tooling:**
    - when to run `/yaybrainstorming`, `/yayplan`, `/yaytidy` (before opening a PR) and `/yayplancheck`;
    - the Status lifecycle of specs and plans, linking to the skills, which own it;
    - when to write an ADR;
  - **keeping the harness current:**
    - owner map (§4);
    - "update when" triggers;
    - personal overrides (`settings.local.json`, `CLAUDE.local.md`);
    - routine `/doctor prompt-audit`.
- **Checklist:**
  - [x] every command run on a clean checkout
  - [x] teammate review
  - [x] do-not-reintroduce ticked (commit body)
- **Notes (2026-10-03):**
  - Branch `trace/docs/harness-p11-readme` is stacked on `trace/docs/harness-tidy-skills`, because no harness phase has merged into `dev/edd` yet.
  - Team facts, from Trace: one teammate other than the author approves each PR; Trace promotes `dev/edd` to `main` before each release; there is no release yet, so the cadence is set at the first one.
  - Outcomes in a fresh clone of the branch, on Node 22 (`nvm use` read `.nvmrc`):
    - `corepack enable` exits 0; pnpm was already Corepack's shim.
    - `./scripts/run.sh dev` ran the sync, created `vendor-prefixed/` and installed the JS packages, then stopped with "Port 3030 is already in use", because the main checkout's dev server held the port (`strictPort`). `git status` stayed clean. P3 recorded the server starting on :3030.
    - `pnpm install` exits 0.
    - `composer lint` exits 1 (0 errors, 7 warnings in 3 files). `composer test` exits 0 with "No tests executed!".
    - `composer test:integration` prints "Error establishing a database connection" and exits 0.
    - Admin lint exits 1 (1 error, 39 warnings). Admin test exits 1 ("No test files found"). `tsc --noEmit` exits 2 with the 3 TS2307.
    - `claude -p` in the clone: `CLAUDE.md` loads at `session_start`, `php-standards.md` on reading `includes/YayLoyalty.php`, and nothing extra on reading `README.md`. Without the trust dialog, Claude ignores the 7 `permissions.allow` entries and says so.
    - Every failure matches `docs/known-issues.md`.
  - The README now holds the living owner map; §4 stays as the plan's record. `CLAUDE.md` links the README, as P3 deferred.
  - Commands in both files are one-time setup only: `nvm use`, `./scripts/run.sh dev` and `pnpm install`. The README links `CLAUDE.md` for lint, test and release.
- **Acceptance:**
  - C6 clean.
  - No command appears in both `README.md` and `CLAUDE.md`, except one-time setup.
  - Every command executed with an outcome logged.
- **Risks:** duplicating `CLAUDE.md`; the owner map decides.
- **Effort:** M (3–4 h).

### P12 — Final validation
- **Goal:** prove the harness works for someone who didn't build it, and leave a repeatable check.
- **Prerequisites:** P11; a validator who didn't build the harness, named before P12 starts (Q4 deferred the name).
- **Steps:**
  1. Add `scripts/check-harness.sh`, bundling C2–C6, C9, C10, C12, C15, C16 and C17 into one exit-coded script. Link it from README § upkeep.
  2. Run the full §8 plan.
  3. Fix-forward every finding.
  4. Set this plan to `Status: done`.
- **Checklist:**
  - [x] script
  - [ ] §8 V1–V10 green
  - [ ] walkthrough issues closed
  - [ ] plan status done
- **What's left (2026-10-03, after #59):** the harness is on `dev/edd` (Q19), and the phase branches are deleted on GitHub. In order:
  1. **This bookkeeping:** merge the PR from `trace/docs/harness-landed`, which records Q19, P0's merge, the tag push and the freeze notice.
  2. **Teammate branches:** share the fix lists with Jason and Paul, and say the stack has landed. They merge `dev/edd` into their branches and follow their lists:
     - live: `jason/feat/rewards-editor`, `paul/improve/ledger-queries`, `paul/feat/email-points-earned`, `paul/feat/frontend-launcher`;
     - nothing to merge, so close: `jason/feat/reward-screen`, `jason/feat/campaign-editor-archive-ui`, `jason/dev/campaign-editor-performance-ui`.

     Their PRs then update the new docs as their lists say: storefront and email facts in `docs/architecture.md`, the "No tests" entry in `docs/known-issues.md` once tests return, and the "Fails today" marks in `CLAUDE.md`. Each must pass `./scripts/check-harness.sh`.
  3. **P4:** Trace reviews `docs/architecture.md` as code owner (Q18), then ticks P4's box. Fixes go in their own PR.
  4. **Done (Q20).** **P9, open since Q12:** name where the canonical copy of the shared skills lives, and copy the skills to the sibling YayCommerce repos if they use them.
  5. **V10:** the validator is Paul (Q21), who didn't build the harness (Q4). They follow `README.md` from a fresh clone of `dev/edd`: setup, the dev server, lint and tests, then one brainstorm, plan and review cycle. Each blocker becomes an issue.
  6. **Fix forward** every walkthrough finding, then tick "§8 V1–V10 green" and "walkthrough issues closed".
  7. **Close the plan:** set this plan's Status to `done YYYY-MM-DD` in the PR that closes the last finding.
- **Notes (2026-10-03):**
  - Branch: `trace/docs/harness-p11-readme`, before P12 gets its own branch.
  - **Step 1:** `scripts/check-harness.sh` runs C2–C6, C9, C10, C12, C15, C16 and C17, prints each finding with its check's name, and exits 1 on any. It passes on `/bin/bash` 3.2, and a planted broken link fails it. The README's upkeep section links it.
  - **C5 fix:** the Appendix's `':!.claude/skills/*/evals/'` excluded nothing, because of the trailing slash, so C5 always reported the 5 example paths in the skill evals. It is now `':!.claude/skills/*/evals/*'`, here and in the script.
  - **§8 results:**
    - V1–V4: the script exits 0, and `CLAUDE.md` is 56 lines.
    - V5: C10 passes, and C11 gives exactly P7's expectations: `php-standards.md`, `react-admin.md` and `release.md` on their files, no rule for `README.md`, and only `CLAUDE.md` at `session_start`.
    - V6: C13's `claude doctor` exits 0 with no invalid settings, C14 passes, and a `claude -p` asked to write `vendor-prefixed/harness-probe.txt` was blocked by the hook; no file was created.
    - V7: every command in `CLAUDE.md`, `README.md`, the rules and `known-issues.md` ran in a fresh clone (P11's notes, plus `run.sh help`, `build lite`, `release pro 1.0.0`, `release lite --no-prompt`, `bash -n`, `php -l`, `composer lint -- <file>`, the direct `tsc`, `pnpm makepot` and `composer install` after release). Each outcome is a success or the failure `known-issues.md` lists. `validate-build.php` had no zip to check, because release fails.
    - V8 (a): the answer was `pnpm --filter @yayloyalty/admin-page lint`, word for word.
    - V8 (b): passes. Two fresh runs of §8's question both put `docs/architecture.md` into context: run 1 printed § Pro and Lite with `sed`, run 2 grepped the doc. Both answers used facts only that section holds: one route table for both builds, Lite dropping Pro code by constant folding, and the sentinel proving only that `main.tsx`'s branch folded.
    - V8 (b), earlier attempts, corrected: the first two prompts, and the "tightened" form that commit `bbbd866` recorded as two misses, asked questions that `CLAUDE.md` and `docs/conventions.md` own: where Lite names Pro (hard rule 2), and a Pro-only setting with a stored default ("anything that stores Pro data" sends that to `conventions.md`). Claude grepped `conventions.md` and answered correctly, so the triggers worked, and none of those runs was a miss: A5's promotion rule does not apply. Requiring the Read tool also measured tool choice, since Claude reads a section with `grep` or `sed` in Bash as often as with Read. Trace chose the form now in §8 on 2026-10-03: a question only `architecture.md` answers, and any tool call that reads it.
    - V8 (c): the P9 dry run above.
    - V8 (d): `/yaytidy base trace/docs/harness-p11-readme` on a scratch branch with a planted unmarked copy of the dev-server URL. The report's first line was `Branch mode against trace/docs/harness-p11-readme (82c9bc4): 1 changed file, untracked: none`, and finding 1 named the copy, with `DEV_PORT` in `apps/admin/vite.config.ts` as its owner.
    - V9: `/doctor prompt-audit`, read-only, found no missing file or command and no contradiction. Dispositions (Trace, 2026-10-03): the "copy this checklist" blocks in `yaybrainstorming` and `yayplan` are removed (medium confidence; the dry run's replies echoed them for nothing); the flag on `yayplan`'s description, at 498 of 500 characters, is noted: swap a trigger phrase rather than add one.
    - V10: waits for the validator (P1, Q4).
- **Acceptance:**
  - `scripts/check-harness.sh` exits 0.
  - V1–V10 pass.
  - The walkthrough log has zero open blockers.
- **Risks:** LLM-based checks are nondeterministic. Mechanical checks gate; LLM checks are advisory but every finding needs a disposition.
- **Effort:** M (½ day + validator ~2 h).

---

## 8. Validation plan (P12)

| # | Check | Mechanical pass condition |
|---|---|---|
| V1 | Links resolve | C6 prints nothing |
| V2 | Paths cited in harness docs exist | C5 + C16 print nothing |
| V3 | No old references | C2, C3, C4 print nothing |
| V4 | Size and headers | `CLAUDE.md` < 100 lines; every doc, rule and skill has Purpose + Update when (C9); no imports (C15); decision records well-formed (C17) |
| V5 | Rules load exactly when relevant | C10 + C11 expectations from P7 |
| V6 | Settings and hook | C13 + C14; scripted vendor edit refused |
| V7 | Every documented command runs | Every command in `CLAUDE.md`, `README.md`, rules and skills (except the placeholders in skill templates) is run in a **fresh clone** inside a WP `plugins/` dir with Node 22. Outcome = success, or exactly the failure listed in `known-issues.md` (table in the PR). |
| V8 | Agent smoke tests (`claude -p --setting-sources project --output-format stream-json`) | (a) asked for the admin lint command → answer contains it verbatim; (b) asked, in two fresh sessions, "I want to add a Pro-only admin page with its own route. How does it reach Pro and stay out of the Lite bundle, and would the release validator catch a mistake?" The question needs facts only `docs/architecture.md` § Pro and Lite holds, and matches its "touches the Pro/Lite split" trigger in `CLAUDE.md`. → in at least one run, a tool call puts the doc into context: a Read whose `file_path` ends in `docs/architecture.md`, or a Bash command that names it (`jq -r 'select(.type=="assistant") \| .message.content[] \| select(.type=="tool_use") \| (.input.file_path // .input.command // "")' \| grep -c 'docs/architecture\.md'` ≥ 1). Reading another doc doesn't count. Two misses apply A5's promotion rule; (c) `/yaybrainstorming` → `/yayplan` → `/yayplancheck` toy run → a spec and a plan with Status lines, and a review verdict; (d) `claude -p … "/yaytidy base <ref>"` on a scratch branch with one planted unmarked copy → the report's first line names the ref, its short SHA and the file count, and the plant appears as a finding with its owner |
| V9 | `/doctor prompt-audit` | No "missing file/command" or "contradiction" finding left without a disposition |
| V10 | New teammate end to end | The named validator (Q4) follows `README.md` from a fresh clone: setup → dev server → lint/test → one brainstorm → plan → review cycle. Every blocker is logged as an issue; pass = zero open blockers. |

---

## 9. Open questions

Q1–Q9 were resolved in P1 (2026-10-01; answers in §6). Q10 was decided when the P9 drafts were written. Q11–Q13 came out of the review of those drafts, and Trace took the recommended option of each on 2026-10-02 (§6).

- [x] **Q1 — Rebuilt `code-corrector` rule source.** Options:
  - (a) owners discovered from in-code markers (recommended);
  - (b) a fresh skill-local owner list built from a code audit;
  - (c) a section of `conventions.md`.

  Also: do the four readable-code rules survive (return early / nesting ≤ 2; one job ≈ 40 lines; names carry kind and unit; boring over clever)? If yes, the owner is `conventions.md`.
- [x] **Q2 — PHP rule glob.** Add `YayLoyaltyPluginAdapter.php` (root PHP, scanned by phpcs) to the narrowed list? Recommended: yes.
- [x] **Q3 — ADR backfill.** Which existing app decisions get ADRs in P6? Candidates, each provable in code:
  - build-time Pro/Lite split with a zip validator;
  - `LedgerService` as sole ledger writer with a cached balance;
  - admin-shell scoped through php-scoper;
  - UTC storage;
  - money as decimal strings.

  Recommended: at most the ones a teammate can state the rationale for.
- [x] **Q4 — Validator and reviewers.** Who does the P12 walkthrough (must not have built the harness)? Who reviews each phase PR?
- [x] **Q5 — Node pin.** Add `.nvmrc` (`22`) and `"engines": {"node": ">=22"}` so the documented pnpm commands work under nvm? This is a tooling change outside the harness. Recommended: yes, in P3; otherwise `CLAUDE.md` must tell people to switch Node by hand.
- [x] **Q6 — Minimum Claude Code version.** ≥ 2.1.283 (for `/doctor prompt-audit`) for every teammate?
- [x] **Q7 — Direct commits to `dev/edd`.** History shows docs and refactor commits pushed straight to `dev/edd`. Allowed going forward, or PR-only? This sets `CLAUDE.md` § Git.
- [x] **Q8 — Docs freeze for P2.** Who announces it, and when? Is the tag push to `origin` approved?
- [x] **Q9 — Lockfiles.** `composer.lock` and `pnpm-lock.yaml` are gitignored, which undermines "every command runs" on other machines. Leave it as a known issue (default), or approve committing them (an app-tooling change outside this plan)?

**For P9** (resolved 2026-10-02; answers in §6):

- [x] **Q11 — Status words.** The drafts write `draft | approved` on a spec and no Status on a plan. `CLAUDE.md`, §4 and A12 use `proposed | accepted | superseded` for specs and `active | done | superseded` for plans. Options:
  - (a) the skills adopt §4's words, and `CLAUDE.md` stays as it is (recommended: the words match the decision records' `proposed` and `accepted`, and `superseded` keeps the history readable);
  - (b) `CLAUDE.md` and §4 adopt `approved`. The skills still need a Status line for plans, and a superseded state.
- [x] **Q12 — Shared or YayLoyalty-specific skills.** The drafts are written for any YayCommerce plugin (P9, "Noted"). Options:
  - (a) keep them shared. This repo's specifics reach them through `CLAUDE.md` and the docs it links, and P9's fixes are worded to hold in any repo. Recommended: one set of skills serves the plugin family, and `CLAUDE.md`'s "read when…" links already cover Pro/Lite, new writers, the SPA, money and dates. If (a), say where the canonical copy lives;
  - (b) tailor them. The question bank names the Pro/Lite seam, `LedgerService`, capabilities, money and dates; the skills link `docs/architecture.md`, `docs/conventions.md` and `docs/decisions/`; the evals use YayLoyalty features.
- [x] **Q13 — Superseding the skills decision.** The harness record's skills bullet ([A4, A8, Q1]) no longer holds. The format record allows one edit to an accepted record: its Status line, when a later record supersedes it. It doesn't say what happens when only one of a record's decisions changes. Options:
  - (a) a new record covers the skills, and the harness record's Status line adds that the new record supersedes its skills decision (recommended: one decision changed, so one short record);
  - (b) a new harness record restates every decision, with the new skills, and supersedes the old one whole.

---

## Appendix — checks

Run from the repo root in bash, with Node 22 first on `PATH`.

```bash
# B — baseline: counts and exit codes only (line numbers and timings stripped)
run(){ echo "### $*"; out=$("$@" 2>&1); c=$?; printf '%s\n' "$out" \
  | grep -E 'TOTAL OF|problems|Code style issues|error TS|No tests|Tests |Test Files|No test files|^OK' \
  | sed -E 's/\([0-9]+,[0-9]+\)//; s/:[0-9]+:[0-9]+//'; echo "exit=$c"; }
{ run composer lint -- --report=summary -q; run composer test
  run pnpm --filter @yayloyalty/admin-page lint; run pnpm --filter @yayloyalty/admin-page test
  run bash -c 'cd apps/admin && pnpm exec tsc --noEmit'
  run bash -n scripts/run.sh; run php -l scripts/validate-build.php; } > "$OUT"

# C1 — old harness files gone (P2 only)
git ls-files CLAUDE.md .claude docs 'includes/Engine/*/README.md' scripts/check-docs.sh | grep -v '^docs/plans/'
# C2 — no references to removed files/tools (code-corrector is removed, so any mention counts)
git grep -nE 'code-standards|codebase-summary|system-architecture|deployment-guide|project-roadmap|project-changelog|data-model(\.md|/)|points-engine-scout|redemption-scout|check-docs|code-corrector|source-of-truth-check|claudekit|repomix|opencode|README invariants?' -- . ':!docs/plans/'
# C3 — no doc-section citations in code (the campaign editor's own §1–§6 are allowed)
git grep -n '§' -- includes apps tests scripts phpcs.xml.dist yayloyalty.php | grep -vE '^apps/admin/src/pro/campaigns/(editor/(CampaignEditorSections\.tsx|campaign-editor\.form\.ts)|add/campaign-templates\.ts):'
# C4 — no feature IDs
git grep -nwE 'F[0-9]{2}(-[0-9]+)?' -- includes apps tests scripts
# C5 — every docs/*.md path cited anywhere exists (plans and specs excluded: they describe history; skill evals too: their prompts name example files)
git grep -ohE 'docs/[A-Za-z0-9_./-]+\.md' -- . ':!docs/plans/' ':!docs/specs/' ':!.claude/skills/*/evals/*' | sort -u | while read -r p; do [ -f "$p" ] || echo "MISSING $p"; done
# C6 — relative markdown links resolve
git ls-files -- '*.md' | grep -E '^(CLAUDE|README)\.md$|^\.claude/|^docs/' | while read -r f; do
  grep -oE '\]\([^)[:space:]]+\)' "$f" | sed -E 's/^\]\(//; s/\)$//; s/#.*$//' | grep -vE '^(https?:|mailto:|$)' |
  while read -r t; do [ -e "$(dirname "$f")/$t" ] || echo "BROKEN $f -> $t"; done; done
# C7 — no regression
diff before.txt after.txt
# C8 — P2 changed comments only. PHP: token streams minus comments and whitespace must match the base.
strip='$t=token_get_all(stream_get_contents(STDIN));foreach($t as $x){if(is_array($x)&&in_array($x[0],[T_COMMENT,T_DOC_COMMENT,T_WHITESPACE]))continue;echo is_array($x)?$x[1]:$x,"\n";}'
for f in $(git diff --name-only origin/dev/edd...HEAD -- '*.php'); do
  [ -f "$f" ] && ! diff -q <(git show origin/dev/edd:"$f" | php -r "$strip") <(php -r "$strip" < "$f") >/dev/null && echo "CODE CHANGED $f"; done
#   TS/TSX: same idea via the TypeScript printer with comments removed (verified on JSX comments; a real code change is caught)
(cd apps/admin && for f in $(git diff --name-only origin/dev/edd...HEAD | grep -E '^apps/admin/.*\.tsx?$' | sed 's#^apps/admin/##'); do
  [ -f "$f" ] && node -e 'const ts=require("typescript"),fs=require("fs"),cp=require("child_process"),f=process.argv[1];
    const s=x=>ts.createPrinter({removeComments:true}).printFile(ts.createSourceFile("f.tsx",x,ts.ScriptTarget.Latest,true,ts.ScriptKind.TSX));
    if(s(cp.execSync("git show origin/dev/edd:apps/admin/"+f).toString())!==s(fs.readFileSync(f,"utf8")))console.log("CODE CHANGED apps/admin/"+f)' "$f"; done)
#   Allowed output: scripts/validate-build.php (message string). The bash help line in scripts/run.sh is reviewed by diff.
# C9 — headers present
for f in CLAUDE.md README.md docs/*.md docs/decisions/*.md .claude/rules/*.md .claude/skills/*/SKILL.md; do grep -q 'Purpose' "$f" && grep -q 'Update when' "$f" || echo "NO HEADER $f"; done
# C10 — each rule glob matches ≥1 tracked file
for r in .claude/rules/*.md; do sed -n '/^---$/,/^---$/p' "$r" | grep -oE '"[^"]+"' | tr -d '"' |
  while read -r g; do [ -n "$(git ls-files ":(glob)$g" | head -1)" ] || echo "DEAD GLOB $r $g"; done; done
# C11 — rules load exactly when relevant (InstructionsLoaded log)
S=$(mktemp -d); L="$S/l.jsonl"; printf '{"hooks":{"InstructionsLoaded":[{"hooks":[{"type":"command","command":"cat >> %s"}]}]}}' "$L" > "$S/s.json"
probe(){ : > "$L"; claude -p --settings "$S/s.json" --setting-sources project --max-turns 2 --no-session-persistence "Read $1 and reply OK." </dev/null >/dev/null; sleep 2
  jq -r '[.load_reason, .memory_type, (.file_path|split("/")|last), ((.globs // [])|join(",")), ((.trigger_file_path // "")|split("/")|last)] | @tsv' "$L" | sort -u; }
probe includes/YayLoyalty.php; probe apps/admin/src/main.tsx; probe scripts/run.sh; probe README.md
#   Record any memory_type=User lines: the harness must not depend on personal config.
#   The globs column is the parsed frontmatter. A rule that loads at session_start has lost its paths.
# C12 — skill names valid and equal to their directory; descriptions fit the listing (§2.6, trap 10)
for d in .claude/skills/*/; do n=$(sed -n 's/^name: *//p' "$d/SKILL.md" | head -1); [ "$n" = "$(basename "$d")" ] && [[ "$n" =~ ^[a-z0-9-]+$ ]] || echo "BAD NAME $d"
  s=$(sed -n 's/^description: *//p' "$d/SKILL.md" | head -1); [ ${#s} -le 500 ] || echo "LONG DESCRIPTION $d (${#s})"; done
# C13 — settings valid
jq empty .claude/settings.json && claude doctor
# C14 — guard hook: exit 2 in each generated directory (a ".." spelling too), 0 for includes/x.php
for p in vendor/x.php vendor-prefixed/x.php node_modules/x.js apps/admin/node_modules/x.js assets/dist/x.js release/x.zip includes/../vendor/x.php includes/x.php; do
  echo "{\"tool_input\":{\"file_path\":\"$PWD/$p\"}}" | CLAUDE_PROJECT_DIR=$PWD .claude/hooks/guard-generated-paths.sh 2>/dev/null; echo "$p exit=$?"; done
# C15 — no @imports in CLAUDE.md (backticked mentions are fine)
grep -nE '(^|[[:space:]])@[A-Za-z0-9_.~/-]+' CLAUDE.md | grep -v '`'
# C16 — backticked repo paths in harness docs exist (globs, templates, path:line suffixes, the dev/edd branch and gitignored build output handled)
for f in CLAUDE.md README.md docs/*.md .claude/rules/*.md .claude/skills/*/SKILL.md; do
  grep -oE '`[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+(:[0-9,-]+)?`' "$f" | tr -d '`' | sed -E 's/:[0-9,-]+$//' |
  grep -vE 'YYYY|^docs/specs/?$|^(origin/)?dev/edd$' | while read -r p; do [ -e "$p" ] || git check-ignore -q "$p" || echo "MISSING $f -> $p"; done; done
# C17 — decision records: file names and the four headings
for f in docs/decisions/*.md; do basename "$f" | grep -qE '^[0-9]{4}-[0-9]{2}-[0-9]{2}-[a-z0-9-]+\.md$' || echo "BAD NAME $f"
  for h in Status Context Decision Consequences; do grep -qx "## $h" "$f" || echo "NO $h $f"; done; done
```
