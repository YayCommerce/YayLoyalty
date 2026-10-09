# Setup Wizard Implementation Plan

> **For agentic workers:** execute phases in order. Read this file, then the current phase file. Do not start a phase until the previous phase's exit criterion has been verified and `/yayplancheck` has passed on it.

**Status:** active
**Goal:** A fresh install starts with the program off and opens a five-step setup wizard from the YayLoyalty menu. "Turn on my program" writes every answer in one transaction and makes the program live.
**Spec:** [docs/specs/2026-10-08-setup-wizard-design.md](../../specs/2026-10-08-setup-wizard-design.md)
**Architecture:**
- Option `yayloyalty_setup` is the wizard's only state. `Installer::install()` creates it, `LocalizeHelper` exposes it, and `SetupRestController` changes it.
- The SPA edits a draft that never touches live rows. `SetupApplier::apply()` validates every built-in before writing and writes the options last, so a failure leaves nothing behind, including in the object cache.
- The spec's single apply endpoint was chosen over chaining the existing `/settings`, `/campaigns` and `/rewards` routes. Chaining could half-apply, and those routes need `manage_*` capabilities that the wizard's view-only users lack.

**Touches:**
- PHP: `includes/Helpers/SetupHelper.php` (new), `includes/Services/SetupApplier.php` (new), `includes/Controllers/SetupRestController.php` (new), `includes/Database/Installer.php`, `includes/Engine/RestAPI.php`, `includes/Helpers/LocalizeHelper.php`.
  - `includes/Engine/Storefront/LauncherPreview.php` is (shared). Its consumers are `DesignRestController::preview_design()` and the new `SetupRestController`. Phase 2 only adds an optional parameter.
- SPA: `apps/admin/src/router.tsx`, `apps/admin/src/types/localize.type.ts`, new `lib/{schema,api,queries}/setup.*`, new `pages/setup/`, and `pages/dashboard/DashboardPage.tsx` plus two new dashboard components.

**Tests:**
- `tests/integration/Database/InstallerSetupStateTest.php` (integration suite, `composer test:integration`). Guards that an existing install gets status `completed` and keeps `general.enabled = true`, and that only a site with no `yayloyalty_db_version` starts `pending` with the program off.
- `tests/integration/Services/SetupApplierTest.php` (integration suite). Guards these rates:
  - `points_per_unit = points ÷ amount` (3 per "2" → 1.5);
  - `points_per_currency_unit` (200 per "1" → 200);
  - `max_percent` 100 → `null`, 50 → 50;
  - a missing built-in throws and changes nothing.

## Global constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types. phpcs doesn't catch these, so review does.
- Lite names Pro in exactly one place. Nothing in this plan goes in `includes/Pro/` or `apps/admin/src/pro/`, or references them. The wizard is identical in Lite and Pro.
- Only `LedgerService` writes ledger rows. This plan writes no ledger rows.
- Never hand-edit `vendor/`, `vendor-prefixed/`, `node_modules/`, `assets/dist/` or `release/`.
- Money on the wire is a decimal string (`z.string()`), and points are integers. Computed money is rounded to the store currency's decimals (`currencyDecimals()`), never a fixed 2. Numbers are formatted with `formatStoreNumber`, `formatStoreDecimal` and `formatPoints`, or `SettingsHelper::format_number()`, never `toLocaleString()`.
- A limit that can be off is stored as `null`, never 0: `max_percent_of_subtotal` at 100% is stored as `null`.
- Every `/setup` route requires `yayloyalty_view`. This is a deliberate exception that lets a shop manager set the labels and the launcher design during setup.
- SPA rules from `.claude/rules/react-admin.md`:
  - parse REST responses with zod in `*.api.ts`;
  - `<RESOURCE>_QUERIES` maps go in `lib/queries/`;
  - show errors with `toastApiError()`;
  - forms use react-hook-form with `zodResolver`;
  - build UI from `components/ui/`;
  - no dynamic `import()` or `React.lazy`.
- File names follow the repo: PascalCase `.tsx` components, kebab-case `.ts` modules, PascalCase PHP classes. New strings use the `yayloyalty` text domain.
- Branch is `paul/feat/setup-wizard`, off `dev/edd`. Commit only when asked, stage files by name, and use Conventional Commits ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- These checks already fail on `dev/edd`, so the expected result is no new failures:
  - `composer lint`: warnings in 3 existing files. Run `vendor/bin/phpcs <changed files>` instead and expect no output.
  - admin lint: run eslint and prettier on the changed paths only.
  - `tsc --noEmit`: expect only the three `TS2307` errors for `@emoji-mart/data`, `@emoji-mart/react` and `@radix-ui/react-progress`.
  - `composer test:integration` can exit 0 without running, so read the test count (see `docs/known-issues.md`).

## Review focus

- **Upgrading a live store.** `install()` runs on the new version with `yayloyalty_db_version` set and no `yayloyalty_setup`. The status must become `completed`, and the program must stay on and never be routed to the wizard. Phase 1 Task 1 test.
- **The option is missing on a request.** `SetupHelper::get_state()` reads it as `completed`, never `pending`, so a live store is never sent to `#/setup`. Phase 1 Task 1, step 3.
- **A built-in row is missing, or its config is rejected.** Nothing changes: no row, no option, and no cached option. Phase 1 Task 2 test.
- **A rate that isn't per 1 unit.** 3 points per "2" stores 1.5, and the worked line for a 50 order reads 75. Phase 1 Task 2 test, and Phase 1 Task 5, step 3.
- **A shop manager runs setup.** Every `/setup` route answers that user, while `/settings` still refuses them. Phase 1 Task 3, step 3.
- **Complete pressed twice, or in two tabs.** The second call gets 409 and nothing changes. Phase 1 Task 3, step 3.
- **A currency with 0 decimals.** The minimum's money value rounds to 0 decimals, with no ".00". Phase 1 Task 5, step 3.
- **The Design tab's preview after `documents()` gains `$earn`.** Its output must not change. Phase 2 Task 1, steps 1 and 3.
- **The draft's labels leak past the preview.** The `pre_option` filter must be gone once `/setup/preview` returns, even when it throws. Phase 2 Task 1, step 6.
- **A view-only user on the first-run dashboard.** The card must not call routes that need `manage_*`. Phase 2 Task 2, step 9.

## Phases

| Phase | Delivers (user-visible) | Exit criterion (exact check) | Status |
|---|---|---|---|
| 1 | A fresh install is off and opens the five-step wizard; "Turn on my program" applies every answer and goes live | The Phase 1 walkthrough in `phase-1-wizard-goes-live.md` passes | pending |
| 2 | Step 4's live launcher preview with the draft's labels and rate, and the dashboard's setup banners and program card | Spec acceptance criterion 15, end-to-end, passes | pending |

## Interface contract

**Option `yayloyalty_setup`** (autoloaded):
```
{ status: 'pending'|'completed', step: int 1–5, draft: SetupDraft|null, first_run_dismissed: bool }
```

**SetupDraft** (wire and storage; PHP array, zod `setupDraftSchema`):
```
{ points_label_singular: string 1–30, points_label_plural: string 1–30,
  earn:     { points: int ≥1, amount: decimal string >0 },
  signup:   { enabled: bool, points: int ≥0 },
  review:   { enabled: bool, points: int ≥0 },
  reward:   { points: int ≥1, amount: decimal string >0, min_points: int ≥0, max_percent: int 1–100 },
  launcher: { position: 'bottom_right'|'bottom_left', brand_color: '#rgb'|'#rrggbb' } }
```
A decimal string matches `/^\d+(\.\d+)?$/`.

**PHP `YayLoyalty\Helpers\SetupHelper`** (Phase 1 Task 1):
- `const OPTION_SETUP = 'yayloyalty_setup'`, `STATUS_PENDING = 'pending'`, `STATUS_COMPLETED = 'completed'`, `LAST_STEP = 5`
- `get_state(): array`: the coerced option, or `{completed, 5, null, true}` when the option is missing
- `localized_state(): array{status: string, step: int, first_run_dismissed: bool}`
- `on_install( bool $is_fresh ): void`
- `draft_error( $draft ): ?string`: a translated message, or null when the draft is valid
- `save_draft( int $step, array $draft ): array`: the new state
- `mark_completed(): void`, `dismiss_first_run(): void`

**PHP `YayLoyalty\Services\SetupApplier`** (Phase 1 Task 2):
- `__construct( CampaignRepository $campaigns, RewardRepository $rewards )`
- `apply( array $draft ): void`, which throws `\RuntimeException` on a missing built-in or a rejected config

**REST** (`yayloyalty/v1`, every route `requires( Capabilities::VIEW )`):

| Route | Phase / Task | Body | Response |
|---|---|---|---|
| `GET /setup` | 1 / 3 | none | `{status, step, draft: SetupDraft\|null, live: SetupLive}` |
| `POST /setup/draft` | 1 / 3 | `{step, draft}` | the state; 400 on an invalid step or draft |
| `POST /setup/complete` | 1 / 3 | `{draft}` | `{status: 'completed'}`; 400 invalid; 409 already completed |
| `POST /setup/dismiss-first-run` | 1 / 3 | none | `{first_run_dismissed: true}` |
| `POST /setup/preview` | 2 / 1 | `{draft, viewer: 'member'\|'guest'}` | `{collapsed: string, expanded: string}`; 400 on an invalid draft |

**SetupLive** (the live values that pre-fill a missing draft):
```
{ points_label_singular, points_label_plural,
  purchase_rate: number,
  signup: { enabled: bool, points: int }, review: { enabled: bool, points: int },
  reward: { points_per_currency_unit: number, min_points: int, max_percent_of_subtotal: number|null },
  launcher: { position, brand_color } }
```
`enabled` is `status === active`. The SPA turns the rates into point/amount pairs, so PHP has no copy of `spendPairFromRate()`.

**Localized data:**
- `window.yayLoyaltyAdmin.setup = SetupHelper::localized_state()` (Phase 1 Task 1)
- `window.yayLoyaltyMeta.wpMeta.homeUrl = home_url()` (Phase 2 Task 2)

**SPA:**
- `lib/schema/setup.schema.ts`: `setupDraftSchema`, `SetupDraft`, `setupLiveSchema`, `SetupLive`, `setupStateSchema`, `SetupState`, `SetupLocalized`
- `lib/api/setup.api.ts`:
  - `getSetup(): Promise<SetupState>`
  - `saveSetupDraft(step: number, draft: SetupDraft)`
  - `completeSetup(draft: SetupDraft)`
  - `dismissFirstRun()`
  - `previewSetup(draft, viewer)` (Phase 2)
- `lib/queries/setup.queries.ts`:
  - `SETUP_QUERIES.state`
  - `useSetupQuery()`
  - `useSaveSetupDraftMutation()`
  - `useCompleteSetupMutation()`
  - `useDismissFirstRunMutation()`
  - `useSetupPreviewQuery(draft, viewer)` (Phase 2)
- `pages/setup/setup-wizard.draft.ts`:
  - `draftFromLive(live: SetupLive): SetupDraft`
  - `earnedOn(draft: SetupDraft, subtotal: number): number`
  - `rewardWorth(draft: SetupDraft, points: number): number`
  - `earnBackPercent(draft: SetupDraft): number`
- `LauncherPreview::documents( array $design, bool $is_member, ?array $earn = null ): array` (Phase 2 Task 1). `$earn`, when given, replaces `$payload['earn']`, shaped `array{purchase: ?float, signup: ?int, review: ?int}`.
