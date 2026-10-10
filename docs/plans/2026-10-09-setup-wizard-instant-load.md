# Setup Wizard Instant Load Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source.

**Status:** active
**Branch:** `trace/fix/wizard-exit-setup` (current branch); PR into `dev/edd`
**Goal:** the setup wizard opens at once from declared base values, with no request before it renders.
**Spec:** none, planned from the request. Changes [the setup wizard design](../specs/2026-10-09-setup-wizard-design.md) (accepted), whose criterion 1 prefills from `GET /setup`.
**Architecture:** the wizard is a first-run setup, so its form starts from a `setupDefaults()` function in `setup-wizard.form.ts` instead of the stored values. It is a function, not a constant, because the two labels go through `__()` and must be translated at render time, not at import. With nothing reading it, `GET /setup` and its SPA query are removed; `POST /setup` and `POST /setup/dismiss` stay as they are.
**Touches:** `apps/admin/src/components/setup-wizard/setup-wizard.form.ts`, `SetupWizardModal.tsx`, `apps/admin/src/lib/queries/setup.queries.ts`, `apps/admin/src/lib/api/setup.api.ts`, `apps/admin/src/lib/schema/setup.schema.ts`, `includes/Controllers/SetupRestController.php`, `includes/Services/SetupService.php`, `docs/specs/2026-10-09-setup-wizard-design.md`. No shared code: `spendPairFromRate()` loses one caller but keeps its others (`points.helper.ts`, `PurchaseRateCalculatorDialog.tsx`).
**Tests:** none. The defaults are fixed values, not money math, and `toSetupPayload()`, which turns them into rates, is unchanged.

## Requirements

- **Goal:** when the wizard is due, it is on screen with the first page render, with no loading gap.
- **Out of scope:** what `POST /setup` validates and saves (the zero discount rate gap is fixed: `Reward::config_schema()` refuses a rate of 0), `POST /setup/dismiss`, the steps' UI, and who sees the wizard (`AppLayout.tsx`).
- **Acceptance criteria:**
  1. With `yayloyalty_setup_completed` absent, opening wp-admin → YayLoyalty shows the wizard on step 1 with no `GET …/yayloyalty/v1/setup` request in the browser's network panel.
  2. The wizard starts from exactly these values, whatever is stored: labels "Point" / "Points" (translated), 1 point for every 1.00, signup on at 100, review on at 50, 100 points = 1.00, minimum 100, max 100%, brand colour `#1f6fb2`.
  3. `GET /yayloyalty/v1/setup` returns 404 `rest_no_route`; `POST /yayloyalty/v1/setup` still saves and returns the saved payload.
  4. End to end: delete the option, reload YayLoyalty, change the signup points to 150 and Turn on. Campaigns shows Signup at 150, and a reload shows no wizard.
- **Decided with the user:**
  - Where the base values live → a SPA function in `setup-wizard.form.ts`, not PHP defaults sent with the page.
  - Existing installs, whose stored values the defaults would overwrite on Turn on → accepted: the plugin is unreleased, every value is shown before Turn on, and Exit saves nothing.
  - `GET /setup` → removed, with `SetupService::current()`, `getSetup` and `useSetupQuery`.

## Global constraints

- Run from the repo root on Node 22 (`nvm use`). pnpm only. Any pnpm command may run `pnpm install` and `composer install` first.
- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- `composer.json` is already modified in this checkout and is not part of this work: stage files by name.
- Translatable strings use the `yayloyalty` text domain.
- Comments at minimal depth: one-line docblocks, a why only where code would look wrong.
- No Pro code: the wizard is the same in Lite and Pro.
- Known failures on `dev/edd`: admin `lint` (whole repo), `tsc --noEmit` (TS2307 for `@emoji-mart/*` and `@radix-ui/react-progress`) and `composer lint` (whole repo). Expect **no new errors** in the changed files.
- Commits follow Conventional Commits and end with the `Co-Authored-By` trailer from CLAUDE.md. Commit only when the user asks.

## Review focus

- **Defaults drifting from the PHP seeds.** `setupDefaults()` copies `Campaign::default_config()` (purchase 1, signup 100, review 50), `Reward::default_config()` for `FLEXIBLE_DISCOUNT` (100 per unit, min 100, max null), `SettingsHelper` label defaults and `DesignSchema`'s `brand_color`. Its docblock names them so a change to a seed finds this copy. Checked in Task 1 Step 3.
- **Labels translated too early.** A module-level constant would call `__()` before the locale data loads and show English on a translated site. `setupDefaults()` must be called inside the component. Checked in Task 1 Step 3.
- **A stale cache write.** `useCompleteSetupMutation` writes `SETUP_QUERIES.main`; removing the query without that line breaks the build. Checked in Task 2 Step 4.

---

### Task 1: Open the wizard from base values

**Files:**
- Modify: `apps/admin/src/components/setup-wizard/setup-wizard.form.ts`
- Modify: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx`

**Interfaces:**
- Produces: `setupDefaults(): SetupForm` in `setup-wizard.form.ts`; `toSetupForm()` no longer exists.

- [x] **Step 1: Replace `toSetupForm(setup: Setup)` with `setupDefaults(): SetupForm`**

It returns `{ pointsLabelSingular: __('Point', 'yayloyalty'), pointsLabelPlural: __('Points', 'yayloyalty'), earnPoints: 1, earnSpend: 1, signupEnabled: true, signupPoints: 100, reviewEnabled: true, reviewPoints: 50, worthPoints: 100, worthAmount: 1, minPoints: 100, maxPercent: 100, brandColor: '#1f6fb2' }`. One-line docblock: the first-run values, copied from the PHP seeds listed in Review focus. Drop the `spendPairFromRate` import; keep `Setup`, which `toSetupPayload()` still uses.

- [x] **Step 2: Render the wizard without waiting in `SetupWizardModal.tsx`**

`SetupWizardModal` drops `useSetupQuery()` and renders `<SetupWizard onClose={onClose} />` unconditionally. `SetupWizard({ onClose }: { onClose: () => void })` loses its `setup` prop and calls `useForm<SetupForm>({ defaultValues: setupDefaults() })`. Drop the `Setup` type import and `useSetupQuery` from the imports. Update the modal docblock: the wizard starts from `setupDefaults()` and nothing is saved until "Turn on my program".

- [ ] **Step 3: Verify**

Run: `git grep -n "toSetupForm\|useSetupQuery" -- apps/admin/src/components`
Expected: no output.

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit 2>&1 | grep setup-wizard`
Expected: no output (the baseline TS2307 errors elsewhere remain).

Open: `/wp-admin/admin.php?page=yayloyalty` after `wp option delete yayloyalty_setup_completed` (wp-cli pointed at the Local site's MySQL socket), dev server running.
Expected: the wizard is on screen with the page; step 1 shows "Point" / "Points"; steps 2–4 show 1 per 1.00, signup on 100, review on 50, 100 = 1.00, min 100, max 100%, `#1f6fb2`. `setupDefaults()` is called inside `SetupWizard`, not at module level.

- [ ] **Step 4: Commit**

Stage: `apps/admin/src/components/setup-wizard/setup-wizard.form.ts`, `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx`
Message: `feat(setup): open the setup wizard from base values without a request`

### Task 2: Remove `GET /setup`

**Files:**
- Modify: `apps/admin/src/lib/queries/setup.queries.ts`
- Modify: `apps/admin/src/lib/api/setup.api.ts`
- Modify: `apps/admin/src/lib/schema/setup.schema.ts`
- Modify: `includes/Controllers/SetupRestController.php`
- Modify: `includes/Services/SetupService.php`
- Modify: `docs/specs/2026-10-09-setup-wizard-design.md`

**Interfaces:**
- Consumes: Task 1, after which nothing calls `useSetupQuery`.
- Produces: `/yayloyalty/v1/setup` registers `POST` only. `SetupService` has no public `current()`; `complete()` still returns the saved payload from the private `payload()`.

- [x] **Step 1: SPA**

`setup.queries.ts`: delete `SETUP_QUERIES` and `useSetupQuery`, the `setQueryData(SETUP_QUERIES.main…)` line in `useCompleteSetupMutation`, and `useQuery`/`queryOptions`/`getSetup`/`Setup` from the imports where now unused (`Setup` stays if `mutationFn` still types its argument with it). `setup.api.ts`: delete `getSetup`. `setup.schema.ts`: docblock becomes "Mirrors SetupService::payload(): POST /setup takes it and returns the saved values."

- [x] **Step 2: PHP**

`SetupRestController::register_routes()`: the `/setup` route keeps only its `CREATABLE` entry; delete `get_setup()`; drop the `Capabilities::VIEW` use only if nothing else needs `Capabilities` (`MANAGE_SETTINGS` still does, so the `use` stays). Class docblock: "POST /setup: the setup wizard's "Turn on my program". POST /setup/dismiss: its "Exit setup"." `SetupService`: delete `current()`; class docblock "one read and one write" becomes "the setup wizard's two writes"; `complete()`'s `@return` reads "the saved values, built from the rows just saved" instead of naming `current()`.

- [x] **Step 3: Spec**

In `docs/specs/2026-10-09-setup-wizard-design.md`: Context bullet and Integration points name `POST /setup` and `POST /setup/dismiss` only; criterion 1 says the wizard opens "from the base values in `setupDefaults()`" instead of "prefilled from `GET /setup`", with "Changed 2026-10-09 at the user's request: it first prefilled from `GET /setup`."; the controller and `setup.api.ts`/`setup.queries.ts` file bullets drop `GET`, `getSetup` and `useSetupQuery`; the Architecture paragraph says the modal starts from `setupDefaults()` instead of fetching.

- [x] **Step 4: Verify**

Run: `git grep -n "useSetupQuery\|SETUP_QUERIES\|getSetup\|get_setup\|->current()\|GET /setup" -- apps/admin/src includes docs/specs`
Expected: no output.

Run: `php -l includes/Controllers/SetupRestController.php && php -l includes/Services/SetupService.php && vendor/bin/phpcs includes/Controllers/SetupRestController.php includes/Services/SetupService.php`
Expected: no syntax errors; phpcs reports nothing.

Run: `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit 2>&1 | grep -i setup`
Expected: no output.

Run, as an admin via `wp eval` with the Local socket: `rest_do_request( new WP_REST_Request( 'GET', '/yayloyalty/v1/setup' ) )->get_status()`
Expected: `404`.

- [ ] **Step 5: End to end**

With the dev server running, `wp option delete yayloyalty_setup_completed`, open `/wp-admin/admin.php?page=yayloyalty` with the network panel open.
Action: on step 2 set signup points to 150, Continue to step 5, Turn on my program, then reload.
Expected: no `GET …/setup` request; `POST …/setup` returns 200; Campaigns shows Signup at 150; the reload shows no wizard.

- [ ] **Step 6: Commit**

Stage: the six files above.
Message: `refactor(setup): remove GET /setup now the wizard starts from base values`
