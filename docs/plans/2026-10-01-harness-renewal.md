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

### 2.7 Outside scope — found during the audit, for the app backlog

- `YAYLOYALTY_IS_DEVELOPMENT` defaults to true (`yayloyalty.php:59-62`). A shipped zip shows a blank admin unless the site defines it false.
- Every install seeds 5 demo customers (`Installer.php:58`).
- The second-copy guard doesn't return (`yayloyalty.php:25-33`).
- i18n path bugs (`I18n.php:18-19`); a missing text domain in `UpgradeToPro.tsx:33`.
- JSX runtime mismatch between tsconfig and Vite; unused dependencies.
- Comments still cite deleted tests (`tests/bootstrap.php:13-21`).

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
| R8 | Skills: `.claude/skills/<name>/SKILL.md`; the name defaults to the directory; `disable-model-invocation` is for side-effect workflows; descriptions are always in context | Few, specific skills |
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
│   │   └── release.md         paths: scripts/**, scoper.inc.php                                          (Δ5)
│   └── skills/
│       ├── brainstorming/     our own → docs/specs/
│       └── writing-plans/     our own → docs/plans/
└── docs/
    ├── architecture.md        boot & autoload · admin-shell sync & scoping · layers · data · Pro/Lite · SPA↔WP · build/release · tests
    ├── conventions.md         code and domain conventions not inferable from code
    ├── decisions/             YYYY-MM-DD-<slug>.md (Nygard)
    ├── known-issues.md        symptom · cause · workaround · remove-when
    ├── specs/                 YYYY-MM-DD-<topic>-design.md · Status: proposed | accepted | superseded
    └── plans/                 YYYY-MM-DD-<topic>.md · Status: active | done | superseded (kept as history)
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
| Spec and plan formats and Status values | the `brainstorming` / `writing-plans` skills | `CLAUDE.md` one-liner: "act only on active/accepted" |

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
- [ ] Every doc, rule and skill opens with **Purpose** and **Update when** (an HTML comment in `CLAUDE.md`).
- [ ] Every topic has exactly one owner (§4); any new doc is linked from `CLAUDE.md` with a "read when…" trigger.

---

## 6. Decisions

**Approved in planning (2026-10-01):**

| ID | Decision |
|---|---|
| A1 | Scope as in §1: README, Engine READMEs, `check-docs.sh` and the `.gitignore` AI block count as old harness |
| A2 | Unbuilt-feature designs are let go. Tag the last pre-removal commit `harness-v1-final`; comments say "designed, not built yet" without a pointer. |
| A3 | "Green build" for P2 = **no regression**: identical baseline counts and exit codes. Existing failures are recorded in `known-issues.md`. |
| A4 | `brainstorming` and `writing-plans` are **our own** skills, written from scratch |
| A5 | CLAUDE.md reaches docs through **plain links with "read when…" triggers**, no `@imports`. P12 tests that Claude opens the right doc; a doc it keeps missing may be promoted to an import, recorded in an ADR. |
| A6 | Claude Code only, so no `AGENTS.md` |
| A7 | Committed `.claude/settings.json`: (a) allowlist for `composer lint`, `composer test`, admin lint/test, `tsc --noEmit`, `git diff`, `git log`; (b) PreToolUse guard blocking edits under `vendor/`, `vendor-prefixed/`, `assets/dist/`, `release/`; (c) `includeGitInstructions: false` with Claude Code's **default attribution** kept |
| A8 | Own skills: brainstorming and writing-plans; no others. Amended by Q1: `code-corrector` is removed, not rebuilt. |
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

---

## 7. Phases

Order: inventory → approval → removal → one phase per section of the new structure → team workflow → validation. Every phase starts from the latest `dev/edd`, ends in one PR into `dev/edd`, and ticks its checklist in this file. Checks `C1–C16` and the baseline script `B` are in the Appendix.

Exception: P1–P3 were prepared in one session (2026-10-01), on branches stacked on P0. Merge them in order. After each squash merge, rebase the next branch: `git rebase --onto origin/dev/edd <merged-branch> <next-branch>`.

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
  - [ ] PR merged
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
  - [ ] P12 validator named (deferred by Q4; needed before P12)
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
  2. Tag the base commit locally: `git tag harness-v1-final "$(git merge-base HEAD origin/dev/edd)"`. Trace pushes it before P2 merges: `git push origin harness-v1-final`. If `dev/edd` has moved by then, rebase and re-tag at the new merge base.
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
  - [ ] tag pushed
  - [ ] files removed
  - [ ] 30 doc pointers, 43 IDs, 5 config/script refs and README lines fixed
  - [ ] `.gitignore` block gone
  - [ ] C1–C5 clean
  - [ ] C7 empty diff
  - [ ] C8 shows only allowlisted files
  - [ ] team told that `/code-corrector` is gone for good (Q1)
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
  - [ ] audit recorded
  - [ ] every command run
  - [ ] do-not-reintroduce ticked
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
  - [ ] audit recorded
  - [ ] code owner reviewed accuracy
  - [ ] do-not-reintroduce ticked
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
  - `dev:admin`/`build:admin` ignore `BUILD_TYPE` (always Pro);
  - untracked lockfiles.
- **Steps:**
  1. Write both docs. Known-issues entries use the fields Symptom · Cause · Workaround · Remove when.
  2. Add `CLAUDE.md` links. Next to commands that fail today, add "fails today: see `docs/known-issues.md`".
- **Checklist:**
  - [ ] each convention confirmed by a named teammate
  - [ ] each issue reproduced (command + output in the PR)
  - [ ] do-not-reintroduce ticked
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
  - [ ] format ADR
  - [ ] harness ADR
  - [ ] approved backfills
  - [ ] do-not-reintroduce ticked
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
  - [ ] ignores added
  - [ ] audit recorded
  - [ ] C10 and C11 pass
  - [ ] do-not-reintroduce ticked
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
  - [ ] hook script
  - [ ] settings
  - [ ] C13 and C14 pass
  - [ ] attribution verified
  - [ ] do-not-reintroduce ticked
- **Acceptance:**
  - C13: `claude doctor` reports no settings errors.
  - C14: the hook exits 2 for `vendor/x.php` and 0 for `includes/x.php`.
  - A scratch `claude -p` asked to edit `vendor-prefixed/…` is refused.
  - A scratch commit carries the default trailer.
- **Risks:**
  - jq or bash missing on a teammate's OS (onboarding lists them).
  - Losing the built-in git safety guidance (restated in P3).
  - Trust prompt on first run.
- **Effort:** S–M (2–3 h).

### P9 — Skills: `brainstorming` + `writing-plans`
- **Goal:** our own spec → plan pair, writing into `docs/specs/` and `docs/plans/` with Status lines.
- **Prerequisites:** P8.
- **Steps:**
  1. `brainstorming` (model- and user-invocable):
     - interview with AskUserQuestion, covering Pro/Lite, ledger and data impact, REST/SPA, caps, i18n and HPOS;
     - read `architecture.md`; compare 2–3 approaches;
     - write `docs/specs/YYYY-MM-DD-<topic>-design.md` with `Status: proposed`;
     - point to an ADR when a choice is architectural.
  2. `writing-plans`:
     - takes a spec path and audits the code;
     - writes `docs/plans/YYYY-MM-DD-<topic>.md` with `Status: active`, in this plan's format: summary, findings with paths, phases with goal, prerequisites, steps, checklist, mechanical acceptance, risks, effort; validation; open questions;
     - uses only commands from `CLAUDE.md`.
  3. Each `description` is specific to this repo, so it can't be confused with a personal plugin skill of the same name.
- **Checklist:**
  - [ ] both skills
  - [ ] C12 passes
  - [ ] toy-topic dry run done and discarded
  - [ ] do-not-reintroduce ticked
- **Acceptance:**
  - C12: name = directory, `^[a-z0-9-]+$`.
  - On a scratch branch, `/brainstorming <toy>` creates `docs/specs/<date>-<slug>-design.md` with `Status: proposed`.
  - `/writing-plans <that spec>` creates `docs/plans/<date>-<slug>.md` with `Status: active` and every required heading (grep list).
- **Risks:** output quality; iterate on the dry run. Optionally use Anthropic's `skill-creator` to write evals.
- **Effort:** M (4–6 h).

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
    - when to brainstorm or plan;
    - Status lifecycle;
    - when to write an ADR;
  - **keeping the harness current:**
    - owner map (§4);
    - "update when" triggers;
    - personal overrides (`settings.local.json`, `CLAUDE.local.md`);
    - routine `/doctor prompt-audit`.
- **Checklist:**
  - [ ] every command run on a clean checkout
  - [ ] teammate review
  - [ ] do-not-reintroduce ticked
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
  1. Add `scripts/check-harness.sh`, bundling C2–C6, C9, C10, C12, C15 and C16 into one exit-coded script. Link it from README § upkeep.
  2. Run the full §8 plan.
  3. Fix-forward every finding.
  4. Set this plan to `Status: done`.
- **Checklist:**
  - [ ] script
  - [ ] §8 V1–V10 green
  - [ ] walkthrough issues closed
  - [ ] plan status done
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
| V4 | Size and headers | `CLAUDE.md` < 100 lines; every doc, rule and skill has Purpose + Update when (C9); no imports (C15) |
| V5 | Rules load exactly when relevant | C10 + C11 expectations from P7 |
| V6 | Settings and hook | C13 + C14; scripted vendor edit refused |
| V7 | Every documented command runs | Every command in `CLAUDE.md`, `README.md`, rules and skills is run in a **fresh clone** inside a WP `plugins/` dir with Node 22. Outcome = success, or exactly the failure listed in `known-issues.md` (table in the PR). |
| V8 | Agent smoke tests (`claude -p --setting-sources project --output-format stream-json`) | (a) asked for the admin lint command → answer contains it verbatim; (b) asked a Pro/Lite question → a `Read` of `docs/architecture.md` appears in the stream (A5's trigger works; if it misses twice, apply A5's promotion rule); (c) `/brainstorming` → `/writing-plans` toy run → files with Status lines |
| V9 | `/doctor prompt-audit` | No "missing file/command" or "contradiction" finding left without a disposition |
| V10 | New teammate end to end | The named validator (Q4) follows `README.md` from a fresh clone: setup → dev server → lint/test → one brainstorm → plan cycle. Every blocker is logged as an issue; pass = zero open blockers. |

---

## 9. Open questions (all resolved in P1, 2026-10-01; answers in §6)

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
git grep -nE 'code-standards|codebase-summary|system-architecture|deployment-guide|project-roadmap|project-changelog|data-model(\.md|/)|points-engine-scout|redemption-scout|check-docs|code-corrector|source-of-truth-check|claudekit|repomix|opencode' -- . ':!docs/plans/'
# C3 — no doc-section citations in code (the campaign editor's own §1–§6 are allowed)
git grep -n '§' -- includes apps tests scripts phpcs.xml.dist yayloyalty.php | grep -vE '^apps/admin/src/pro/campaigns/(editor/(CampaignEditorSections\.tsx|campaign-editor\.form\.ts)|add/campaign-templates\.ts):'
# C4 — no feature IDs
git grep -nwE 'F[0-9]{2}(-[0-9]+)?' -- includes apps tests scripts
# C5 — every docs/*.md path cited anywhere (plans excluded: they describe history) exists
git grep -ohE 'docs/[A-Za-z0-9_./-]+\.md' -- . ':!docs/plans/' | sort -u | while read -r p; do [ -f "$p" ] || echo "MISSING $p"; done
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
for f in CLAUDE.md README.md docs/*.md .claude/rules/*.md .claude/skills/*/SKILL.md; do grep -q 'Purpose' "$f" && grep -q 'Update when' "$f" || echo "NO HEADER $f"; done
# C10 — each rule glob matches ≥1 tracked file
for r in .claude/rules/*.md; do sed -n '/^---$/,/^---$/p' "$r" | grep -oE '"[^"]+"' | tr -d '"' |
  while read -r g; do [ -n "$(git ls-files ":(glob)$g" | head -1)" ] || echo "DEAD GLOB $r $g"; done; done
# C11 — rules load exactly when relevant (InstructionsLoaded log)
S=$(mktemp -d); L="$S/l.jsonl"; printf '{"hooks":{"InstructionsLoaded":[{"hooks":[{"type":"command","command":"cat >> %s"}]}]}}' "$L" > "$S/s.json"
probe(){ : > "$L"; claude -p --settings "$S/s.json" --setting-sources project --max-turns 2 --no-session-persistence "Read $1 and reply OK." >/dev/null; sleep 2
  jq -r '[.load_reason, .memory_type, (.file_path|split("/")|last)] | @tsv' "$L" | sort -u; }
probe includes/YayLoyalty.php; probe apps/admin/src/main.tsx; probe scripts/run.sh; probe README.md
#   Record any memory_type=User lines: the harness must not depend on personal config.
# C12 — skill names valid and equal to their directory
for d in .claude/skills/*/; do n=$(sed -n 's/^name: *//p' "$d/SKILL.md" | head -1); [ "$n" = "$(basename "$d")" ] && [[ "$n" =~ ^[a-z0-9-]+$ ]] || echo "BAD NAME $d"; done
# C13 — settings valid
jq empty .claude/settings.json && claude doctor
# C14 — guard hook
for p in vendor/x.php includes/x.php; do echo "{\"tool_input\":{\"file_path\":\"$PWD/$p\"}}" | CLAUDE_PROJECT_DIR=$PWD .claude/hooks/guard-generated-paths.sh; echo "$p exit=$?"; done
# C15 — no @imports in CLAUDE.md (backticked mentions are fine)
grep -nE '(^|[[:space:]])@[A-Za-z0-9_.~/-]+' CLAUDE.md | grep -v '`'
# C16 — backticked repo paths in harness docs exist (globs, templates and path:line suffixes handled)
for f in CLAUDE.md README.md docs/*.md .claude/rules/*.md .claude/skills/*/SKILL.md; do
  grep -oE '`[A-Za-z0-9_.-]+/[A-Za-z0-9_./-]+(:[0-9,-]+)?`' "$f" | tr -d '`' | sed -E 's/:[0-9,-]+$//' |
  grep -vE 'YYYY|^docs/specs/?$' | while read -r p; do [ -e "$p" ] || echo "MISSING $f -> $p"; done; done
```
