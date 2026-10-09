# Setup Wizard Design

**Status:** accepted 2026-10-08; UI amended 2026-10-09: step 5's "What this creates" has no links, since leaving the wizard there would drop unsaved edits; the dashboard links to those screens after go-live
**Related:** Wireframes F16 "Setup wizard: five steps, then the first day" (`YayLoyalty Setup Wizard.dc.html`, artboards 1a–1g, with 1f, the Pro step 2 variant, dropped); branch `paul/feat/setup-wizard`

## Goal

A merchant who installs YayLoyalty for the first time sets up and turns on their points program in five short steps: points name, how customers earn, how they spend, launcher look, then review and go live. Nothing reaches customers until they press "Turn on my program". The wizard is the same in Lite and Pro.

## Context

- Activation runs `Engine/ActDeact::activate` → `Database/Installer::install()`. That function runs dbDelta, seeds the built-ins (`seed_builtins`), installs capabilities, runs migrations, verifies, then stamps `yayloyalty_db_version`. `maybe_upgrade()` runs `install()` again on `plugins_loaded` whenever the version changes. There is no install timestamp, no redirect after activation, and no uninstall routine.
- A fresh install is live straight away: `general.enabled` defaults to `true` (`Helpers/SettingsHelper.php`), and every built-in is seeded ACTIVE:
  - campaigns `default-purchase` (`points_per_unit` 1, `rounding` down, after discounts, excluding shipping and tax), `signup` (100) and `review` (50, `require_verified`, `one_per_product`);
  - reward `cart discount`, a flexible discount (`points_per_currency_unit` 100, `min_points` 100, `max_percent_of_subtotal` null).
- The values the wizard sets already exist:
  - points labels: `yayloyalty_settings.advanced.points_label_singular` and `points_label_plural`, max 30 characters (`SettingsHelper::POINTS_LABEL_MAX_LENGTH`);
  - earn rate: `default-purchase.config.points_per_unit`;
  - signup and review: `config.points` plus the campaign status;
  - reward: `points_per_currency_unit`, `min_points` and `max_percent_of_subtotal` (`Models/Reward.php`);
  - launcher: `yayloyalty_design.launcher.position` (`bottom_right` or `bottom_left`) and `brand_color` (`Helpers/DesignSchema.php`).
- `Engine/Earning/ProgramGate::program_enabled()` gates earning, and `Engine/Storefront/Launcher.php:123` uses it to gate the launcher.
- Writes in a single transaction go through `Database/Transaction::run()`. REST controllers extend `Controllers/BaseRestController` (`exec_read`, `exec_write`, `error_conflict`), and permissions go through `$this->requires( Capabilities::… )`.
- The SPA uses a hash router (`apps/admin/src/router.tsx`), the launcher preview (`pages/settings/LauncherPreviewFrame.tsx`, `DesignLauncherPreview.tsx`, `POST /design/preview`), and localised data in `Helpers/LocalizeHelper.php` (`window.yayLoyaltyAdmin`, plus `window.yayLoyaltyMeta.wcMeta` for the currency).
- Dashboard notices come from `Services/Dashboard/DashboardNotices.php` and render through `pages/dashboard/components/DashboardNoticeStrip.tsx`.
- Sibling precedent: `yay-wholesale-b2b` (`Helpers/SetupWizardHelper.php`, `Controllers/SetupWizardRestController.php`) keeps the wizard status in an option and exposes it at `#/setup-wizard`.

## Scope

**In:**
- Detecting a fresh install and starting it with the program off and setup pending. Existing installs are marked completed and left unchanged.
- The wizard at `#/setup`, five steps, with the draft saved server-side (Exit, Save and finish later, Continue).
- One endpoint that applies every answer atomically and turns the program on.
- Dashboard: a "Finish setup" banner while setup is pending. After completion, a dismissible "Your program is live" banner plus a "Your program" card.

**Out (do not add):**
- Wireframe controls with nothing behind them in the code:
  - the points icon picker or upload (step 1);
  - "When should the points arrive" (step 2); only `completed` awards, by product decision in `OrderListener`;
  - button style icon/label/both (step 4);
  - the checkout slider preview (step 3), since redemption is still a stub;
  - Pro campaign templates (artboard 1f);
  - the go-live checklist rows about the checkout slider, My Account and emails.
- A redirect on activation.
- From artboard 1g: the "Test it yourself" checklist, the Expiry row and the Pro tiles.
- Deleting the option on uninstall, since the plugin has no uninstall routine.
- Re-running the wizard after it has been completed.
- Any change to the Settings → General `enabled` switch. Turning it on while setup is pending does not complete setup.

## Acceptance criteria

1. Given a site that has never had YayLoyalty, when the plugin is activated:
   - option `yayloyalty_setup` equals `{status: 'pending', step: 1, draft: null, first_run_dismissed: false}`;
   - `yayloyalty_settings.general.enabled` is `false`;
   - the storefront shows no launcher;
   - a completed order earns no points.
2. Given a site where `yayloyalty_db_version` already exists (an upgrade or reactivation), when `install()` runs and `yayloyalty_setup` is missing, then `yayloyalty_setup.status` is `completed`, and `general.enabled`, the campaigns, the reward and the design are unchanged.
3. Given status `pending`, when any user with `yayloyalty_view` loads the YayLoyalty admin page, the SPA opens `#/setup` at the saved `step`. After "Exit setup", it shows `#/dashboard` with a "Finish setup" banner that links to `#/setup`. The next full page load opens `#/setup` again.
4. Given status `completed`, loading the admin page never opens `#/setup`, and visiting `#/setup` redirects to `#/dashboard`.
5. `GET /yayloyalty/v1/setup` returns `{status, step, draft}`. When `draft` is null, it returns values pre-filled from the live rows: on a fresh install, singular "Point", plural "Points", earn 1 point per "1" currency unit, signup on at 100, review on at 50, reward 100 points = "1", minimum 100, maximum 100%, position `bottom_right`, colour `#1f6fb2`.
6. Step 1 rejects an empty label or one longer than 30 characters. Its preview reads "You have 850 {plural}", and it shows the store currency read-only.
7. Step 2 rejects points ≤ 0 or an amount ≤ 0. The worked line is "A {currency}50 order earns {floor(50 × points ÷ amount)} {label}"; with 3 points per "2", it reads "earns 75".
8. Step 3 rejects reward points < 1, an amount ≤ 0, a minimum < 0, or a percent outside 1–100. The minimum's money value is `min × amount ÷ points`, rounded to the store currency's decimals.
9. Step 4's preview updates on every change of position or colour and uses the labels from step 1.
10. "Skip" on any step fills the remaining steps with their pre-filled values and opens step 5. Each Edit link on step 5 opens its step, and that step's Continue returns to step 5.
11. `POST /setup/draft` with `{step: 1–5, draft}` saves both into `yayloyalty_setup` and changes no setting, campaign, reward or design.
12. `POST /setup/complete`, given a valid draft and status `pending`, does all of the following in one transaction, then returns 200 with `{status: 'completed'}`:
    - sets the labels;
    - sets `default-purchase` `points_per_unit = points ÷ amount`, status active;
    - sets `signup` and `review` `config.points`, status active when on and paused when off;
    - sets the reward's `points_per_currency_unit = points ÷ amount`, `min_points`, and `max_percent_of_subtotal` (null when 100), status active;
    - sets `launcher.position` and `brand_color`;
    - sets `general.enabled = true` and status `completed`.

    If any write fails, it returns an error and nothing changes. With status already `completed`, it returns 409 and nothing changes.
13. After "Turn on my program", the SPA opens `#/dashboard`, showing the "Your program is live" banner and the "Your program" card. Dismissing either calls `POST /setup/dismiss-first-run`, and both stay hidden on later loads.
14. A Lite build and a Pro build show the same five steps. `scripts/validate-build.php` passes for Lite.
15. **End-to-end:** on a fresh WordPress and WooCommerce site, activate the plugin. Open YayLoyalty and confirm the wizard opens at step 1 and no launcher appears on the storefront. Then:
    - set "Star"/"Stars", 2 points per "1", signup off, 200 points = "1", min 200, max 50%, `bottom_left`, `#aa3300`;
    - Exit, reload, and confirm it reopens at the same step with those values;
    - press "Turn on my program".

    Then confirm:
    - Campaigns shows purchase at 2 per unit, signup paused and review active;
    - Rewards shows 200 = 1, min 200, max 50%;
    - the storefront launcher is bottom-left in `#aa3300`, saying "Stars";
    - a completed $10 order earns 20 Stars.

## Constraints

- Product PHP runs on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite never names Pro. The wizard has no Pro-only code; nothing new goes in `includes/Pro/` or `apps/admin/src/pro/`.
- Money on the wire is a decimal string (`z.string()` in the SPA), and points are integers. Computed money is rounded to the store currency's decimals, never a fixed 2. Numbers are formatted with `formatStoreNumber`, `formatStoreDecimal` and `formatPoints`, or with `SettingsHelper::format_number()`.
- A limit that can be off is stored as `null`, never 0: `max_percent_of_subtotal` 100% → `null`.
- No ledger writes. `LedgerService` is not touched.
- Every setup route requires `yayloyalty_view` (the user's choice). This deliberately lets a shop manager set labels and the launcher design during setup, which the Settings screen otherwise reserves for `yayloyalty_manage_settings`.
- All new strings use the `yayloyalty` text domain. File names are kebab-case, following the SPA's existing naming.

## Approach

**Chosen:** a dedicated setup endpoint with one atomic apply.

**Architecture:**
- The PHP side owns the wizard's lifecycle in a single option, `yayloyalty_setup`. `Installer::install()` sets it, `LocalizeHelper` exposes it, and `SetupRestController` changes it.
- The SPA holds the answers locally and saves them as a draft blob that never touches live rows. A single `complete` call validates the draft and writes every live value inside `Transaction::run()`, then turns the program on. A half-applied setup is impossible, and only one capability check is involved.

**Components:**
- `includes/Helpers/SetupHelper.php`: the option key `yayloyalty_setup` and its defaults, get/save, draft validation and coercion, and the defaults pre-filled from the live rows.
- `includes/Controllers/SetupRestController.php`: the routes below.
- `includes/Services/SetupApplier.php`: `apply( array $draft )`, which inside `Transaction::run()` uses `SettingsHelper::update_settings`, `CampaignRepository::find_by_slug` + `update`, `RewardRepository::find_by_slug` + `update`, and `DesignHelper::update_design`. It reuses each model's existing config validation (`Campaign::config_schema`, the reward config schema).
- `includes/Database/Installer.php`: a fresh-install check before the version stamp.
- `includes/Helpers/LocalizeHelper.php`: `setup: {status, step, first_run_dismissed}` in `yayLoyaltyAdmin`.
- `apps/admin/src/pages/setup/`: the wizard page, a layout with header and footer, one component per step, a review step, and a form schema.
- `apps/admin/src/router.tsx`: a top-level `setup` route outside `AppLayout`, plus the pending-status redirect on first load.
- `apps/admin/src/pages/dashboard/`: the "Finish setup" banner, the "Your program is live" banner and the "Your program" card.

**Data and storage:**
- Option `yayloyalty_setup`, autoloaded: `{status: 'pending'|'completed', step: int 1–5, draft: object|null, first_run_dismissed: bool}`.
- Draft shape:
  ```
  { points_label_singular, points_label_plural,
    earn: { points: int ≥1, amount: decimal string >0 },
    signup: { enabled: bool, points: int ≥0 },
    review: { enabled: bool, points: int ≥0 },
    reward: { points: int ≥1, amount: decimal string >0, min_points: int ≥0, max_percent: int 1–100 },
    launcher: { position: 'bottom_right'|'bottom_left', brand_color: '#rrggbb' } }
  ```
- Migration: on `install()`, if `yayloyalty_db_version` was absent before the stamp, it is a fresh install, so it writes `general.enabled = false` and the pending option. Otherwise, if the option is missing, it adds `status: 'completed'`.
- Uninstall: none, matching the plugin today.

**Integration points:**
- REST namespace `yayloyalty/v1`:
  - `GET /setup`
  - `POST /setup/draft`
  - `POST /setup/complete`
  - `POST /setup/dismiss-first-run`
- Localised `window.yayLoyaltyAdmin.setup`.
- The design preview is the existing `POST /design/preview`.
- No new hooks or filters. No shared code or sibling plugin is changed.

**UI:**
- Full-screen `#/setup` with the header "YayLoyalty setup · Step n of 5 · Exit setup" and the footer "‹ Back · Skip · Continue". Each step's content follows the wireframes 1a–1e, minus the skipped controls.
- Step 5:
  - the program card, with each row's Edit link;
  - "What this creates": the 3 campaigns and 1 reward, by name, with no links out (leaving would drop unsaved edits);
  - "When you turn it on": two rows only. "The three campaigns start earning on the next order that completes; orders already placed are not counted." and "The launcher appears on your storefront.";
  - the "Turn on my program" button and the "Save and finish later" link.
- Dashboard:
  - while pending, a "Finish setup" banner;
  - after completion, until dismissed, the "Your program is live" banner with "View your storefront" (`wpMeta` home URL) and the "Your program" card, listing the campaigns, "Points for discount · N points = X", and links to Campaigns, Rewards and Settings.

**Edge cases and failure modes:**
- A merchant edits campaigns or the reward while setup is pending. With no draft yet, `GET /setup` pre-fills from the live rows, so those edits show up. With a draft, the draft wins on complete, and only the fields listed above are overwritten.
- A built-in row is missing because a slug was not found: `complete` returns 500 with a message, and the transaction rolls back.
- Two admins press complete at once. The second sees status `completed` inside the transaction and gets 409.
- The program is turned on through Settings → General while pending: the program is live, but the wizard still opens until it is completed.
- A multisite network activation runs `install()` per blog, so each blog gets its own option.
- A currency with 0 or 3 decimals: amounts are entered and validated as decimal strings, and displayed money uses the store's decimals.

**Decision record:** None. Starting a fresh install with the program off changes a product default, not an architectural choice.

## Alternatives considered

| Option | Why rejected |
|---|---|
| The SPA calls the existing `/settings`, `/campaigns/{id}`, `/rewards/{id}` and `/design` endpoints at Turn on | A failure midway leaves a half-configured program, and those endpoints need `manage_*` caps that view-only users lack. |
| Write each step to the live rows as you go, and flip `enabled` at the end | Back and Skip would edit live data, and it has the same capability problem. |
| Keep the draft in browser storage | Lost on another device, and another admin starts over. |
| Redirect after activation (the yay-wholesale-b2b pattern) | The user chose to open the wizard on the YayLoyalty menu instead. |
| Program stays live from activation and the wizard only tunes values | Conflicts with the design's "nothing published until step 5". |

## Rollout

- Existing installs are marked `completed` on upgrade and see no change.
- Fresh installs start with the program off, so a merchant who never finishes setup has no live program. The release note must say so.
- No feature flag.

## Open questions

None.
