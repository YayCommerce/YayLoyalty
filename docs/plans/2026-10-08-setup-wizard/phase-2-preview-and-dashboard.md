# Phase 2: Launcher preview and first-run dashboard

**Plan:** `README.md` in this directory, which holds the Status, constraints and interface contract
**Depends on:** Phase 1, Tasks 1 to 6: `SetupHelper`, the `/setup` routes, `SETUP_STEPS`, and the form context
**Delivers:**
- Step 4 previews the launcher with the draft's labels, rate and look.
- The dashboard shows "Finish setup" while setup is pending. After going live, it shows a dismissible "Your program is live" banner and a "Your program" card.

**Exit criterion:** spec acceptance criterion 15, run end to end on a site reset with `wp option delete yayloyalty_db_version yayloyalty_setup`. In addition:
- step 4's preview shows "Stars", bottom-left, in `#aa3300`, before Turn on;
- the dashboard shows "Finish setup" after Exit;
- after Turn on, the dashboard shows the live banner and the program card;
- dismissing them keeps them hidden after a reload, and `wp option get yayloyalty_setup` shows `"first_run_dismissed":true`.

### Task 1: Setup preview route and step 4 preview

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPreview.php` (`documents()`; shared, consumed by `DesignRestController::preview_design()`, whose behaviour must not change)
- Modify: `includes/Controllers/SetupRestController.php` (add `POST /setup/preview`)
- Modify: `apps/admin/src/lib/api/setup.api.ts`, `apps/admin/src/lib/queries/setup.queries.ts`, `apps/admin/src/pages/setup/SetupStepLook.tsx`

**Interfaces:**
- Consumes: `DesignHelper::with_unsaved()`, `LauncherPreviewFrame` (`pages/settings/LauncherPreviewFrame.tsx`), `designPreviewSchema`, and `SetupHelper::draft_error()`
- Produces:
  - `LauncherPreview::documents( array $design, bool $is_member, ?array $earn = null ): array`
  - `POST /setup/preview`
  - `previewSetup(draft, viewer)`, `useSetupPreviewQuery(draft, viewer)`

  (All of these are in the README contract.)
- Decided:
  - The labels are overridden only for the call, by a `pre_option_yayloyalty_settings` filter that returns the stored settings with the draft's two labels. It is added before `documents()` and removed in a `finally`. `SettingsHelper::get_settings()` caches on the raw option, so it picks up the filtered value.
  - `$earn` is `{purchase: points ÷ amount, signup: points or null when off, review: points or null when off}`.

Steps: expand after Phase 1 lands.

### Task 2: Dashboard setup banners and program card

**Files:**
- Create: `apps/admin/src/pages/dashboard/components/DashboardSetupPending.tsx` and `apps/admin/src/pages/dashboard/components/DashboardFirstRun.tsx`
- Modify: `apps/admin/src/pages/dashboard/DashboardPage.tsx`, `apps/admin/src/pages/dashboard/dashboard-page.copy.ts`
- Modify: `includes/Helpers/LocalizeHelper.php` (`wpMeta.homeUrl`), `apps/admin/src/types/localize.type.ts` (`Meta.wpMeta.homeUrl: string`)

**Interfaces:**
- Consumes: `window.yayLoyaltyAdmin.setup`, `useDismissFirstRunMutation()` (Phase 1 Task 4), and the campaigns and rewards queries for the card's names and the "N points = X" rate
- Produces: nothing other phases use
- Decided:
  - `DashboardSetupPending` renders while the status is `pending`: "Finish setting up your program", linking to `#/setup`.
  - `DashboardFirstRun` renders while the status is `completed` and `first_run_dismissed` is false:
    - the banner "Your program is live", with "Customers can earn from their next completed order." and "View your storefront", linking to `homeUrl`;
    - the "Your program" card: campaign names, "Points for discount · N points = X", and links to Campaigns, Rewards and Settings.

    Dismissing either one calls the mutation and hides both.
  - Both sit above `DashboardNoticeStrip`. The "Test it yourself" checklist, the Expiry row and the Pro tiles are not built.

Steps: expand after Phase 1 lands.
