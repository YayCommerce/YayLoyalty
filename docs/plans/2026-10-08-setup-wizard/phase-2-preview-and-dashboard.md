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

Expanded on 2026-10-09 against `paul/feat/setup-wizard` @ `64123b7`. Settings are flat since `90de730`: the labels are `yayloyalty_settings.points_label_singular` and `points_label_plural`, not under `advanced`.

### Task 1: Setup preview route and step 4 preview

**Files:**
- Modify: `includes/Engine/Storefront/LauncherPreview.php` (`documents()`; shared, consumed by `DesignRestController::preview_design()`, whose behaviour must not change)
- Modify: `includes/Helpers/SetupHelper.php` (add `rate()`), `includes/Services/SetupApplier.php` (use it)
- Modify: `includes/Controllers/SetupRestController.php` (add `POST /setup/preview`)
- Modify: `apps/admin/src/lib/api/setup.api.ts`, `apps/admin/src/lib/queries/setup.queries.ts`, `apps/admin/src/pages/setup/SetupStepLook.tsx`, `apps/admin/src/pages/settings/DesignLauncherPreview.tsx` (export three constants)
- Create: `apps/admin/src/pages/setup/SetupLookPreview.tsx`

**Interfaces:**
- Consumes: `DesignHelper::with_unsaved()`, `LauncherPreviewFrame` (`pages/settings/LauncherPreviewFrame.tsx`), `designPreviewSchema`, `DesignPreviewViewer`, and `SetupHelper::draft_error()`
- Produces:
  - `LauncherPreview::documents( array $design, bool $is_member, ?array $earn = null ): array`
  - `SetupHelper::rate( int $points, string $amount ): float`
  - `POST /setup/preview`
  - `previewSetup(draft, viewer)`, `useSetupPreviewQuery(draft, viewer)`

  (All but `rate()` are in the README contract.)
- Decided:
  - The labels are overridden only for the call, by a `pre_option_yayloyalty_settings` filter that returns the stored settings with the draft's two labels. It is added before `documents()` and removed in a `finally`. `SettingsHelper::get_settings()` caches on the raw option, so it picks up the filtered value.
  - `$earn` is `{purchase: points ÷ amount, signup: points or null when off, review: points or null when off}`. `documents()` still nulls `signup` when `$payload['register']` is null, as `LauncherEarnRules::advertise()` does, so the preview never offers signup points the store can't pay.
  - Only the earn rows and labels come from the draft. The redeem rate, the member's worth and `purchase_min` stay live.
  - The step 4 preview shows the member/guest switch and both frames (collapsed, expanded), desktop only. It reuses the Design tab's frame heights, stage labels and 300 ms redraw delay.
  - The query runs only while the draft parses with `setupDraftSchema`. A half-typed colour keeps the last preview up instead of sending a 400.

- [ ] **Step 1: Record the Design preview's baseline**

Run twice:
```
wp eval 'foreach ( [ true, false ] as $m ) { echo md5( wp_json_encode( \YayLoyalty\Engine\Storefront\LauncherPreview::documents( \YayLoyalty\Helpers\DesignHelper::get_design(), $m ) ) ), "\n"; }'
```
Expected: the same two hashes both times. Note them. If the member hash (first line) differs between the two runs, compare only the guest hash in Step 3.

- [ ] **Step 2: Add `$earn` to `LauncherPreview::documents()`**

`includes/Engine/Storefront/LauncherPreview.php`: `documents( array $design, bool $is_member, ?array $earn = null ): array`. When `$earn` is not null, it replaces `$payload['earn']` before `Launcher::view()`, with `signup` set to null when `null === $payload['register']`. Document `@param array{purchase: ?float, signup: ?int, review: ?int}|null $earn` as "the setup wizard's draft rates; null keeps the store's own".

- [ ] **Step 3: Confirm the Design preview didn't change**

Rerun the Step 1 command. Expected: the same hashes as Step 1.
Run `vendor/bin/phpcs includes/Engine/Storefront/LauncherPreview.php`. Expected: no output.

- [ ] **Step 4: Add `SetupHelper::rate()` and use it in `SetupApplier`**

`public static function rate( int $points, string $amount ): float`: points per 1 currency unit, `$points / (float) $amount`. The caller has validated the draft, so `$amount` is above 0. Replace the two inline divisions in `SetupApplier::apply()` (`points_per_unit` and `points_per_currency_unit`) with it.

Run `composer test:integration -- --filter SetupApplierTest`. Expected: every test passes, with a non-zero test count.

- [ ] **Step 5: Add `POST /setup/preview`**

`includes/Controllers/SetupRestController.php`:
- Register `/setup/preview`: `CREATABLE`, callback `fn( $request ) => $this->exec_read( [ $this, 'preview' ], $request )`, `requires( Capabilities::VIEW )`. It is POST, like `/design/preview`, because the draft is too large for a query string. Add it to the class docblock.
- `public function preview( \WP_REST_Request $request )`:
  - an invalid draft (`SetupHelper::draft_error()`) returns `error_invalid_arguments( $error )`;
  - the design is `DesignHelper::with_unsaved( [ 'launcher' => $draft['launcher'] ] )`;
  - `$earn` follows the Decided line above, with `purchase` from `SetupHelper::rate()`;
  - the member flag is `'guest' !== $request->get_param( 'viewer' )`;
  - the label filter follows the Decided line. Read the raw option with `get_option( SettingsHelper::OPTION_SETTINGS, [] )` before adding the filter, and merge the two labels over it (an array when it isn't one).
  - It returns `LauncherPreview::documents( $design, $is_member, $earn )`.

- [ ] **Step 6: Verify the route and that the labels don't leak**

Run, with `1` replaced by an administrator's ID:
```
wp eval --user=1 '
$draft = [ "points_label_singular" => "Star", "points_label_plural" => "Stars", "earn" => [ "points" => 2, "amount" => "1" ], "signup" => [ "enabled" => false, "points" => 100 ], "review" => [ "enabled" => true, "points" => 50 ], "reward" => [ "points" => 200, "amount" => "1", "min_points" => 200, "max_percent" => 50 ], "launcher" => [ "position" => "bottom_left", "brand_color" => "#aa3300" ] ];
$r = new WP_REST_Request( "POST", "/yayloyalty/v1/setup/preview" );
$r->set_header( "content-type", "application/json" );
$r->set_body( wp_json_encode( [ "draft" => $draft, "viewer" => "guest" ] ) );
$res = rest_do_request( $r );
echo $res->get_status(), " | ", false !== strpos( $res->get_data()["expanded"], "Stars" ) ? "Stars" : "no Stars", " | ", false !== stripos( $res->get_data()["expanded"], "aa3300" ) ? "colour" : "no colour", " | ", \YayLoyalty\Helpers\SettingsHelper::points_label_plural(), "\n";
$draft["earn"]["amount"] = "0";
$r->set_body( wp_json_encode( [ "draft" => $draft, "viewer" => "guest" ] ) );
echo rest_do_request( $r )->get_status(), "\n";'
```
Expected (assuming the stored plural label isn't "Stars"):
```
200 | Stars | colour | Points
400
```
The last field of the first line is the stored label, which proves the filter was removed.
Run `vendor/bin/phpcs includes/Controllers/SetupRestController.php includes/Helpers/SetupHelper.php includes/Services/SetupApplier.php`. Expected: no output.

- [ ] **Step 7: SPA API and query**

- `apps/admin/src/lib/api/setup.api.ts`: `previewSetup(draft: SetupDraft, viewer: DesignPreviewViewer): Promise<DesignPreview>` posts `setup/preview` with `{ draft, viewer }` and parses with `designPreviewSchema`.
- `apps/admin/src/lib/queries/setup.queries.ts`: `useSetupPreviewQuery(draft: SetupDraft, viewer: DesignPreviewViewer)`. It has key `['setup', 'preview', viewer, draft]`, `staleTime: Infinity` and `placeholderData: keepPreviousData`, and is `enabled` only when `setupDraftSchema.safeParse(draft).success`.

- [ ] **Step 8: Step 4 preview component**

- `apps/admin/src/pages/settings/DesignLauncherPreview.tsx`: export `STAGES`, `FRAME_HEIGHT_PX` and `REDRAW_DELAY_MS` unchanged.
- Create `apps/admin/src/pages/setup/SetupLookPreview.tsx` with `export function SetupLookPreview()`:
  - `useWatch({ control })` on the whole `SetupDraft`, debounced with `useDebouncedValue(draft, REDRAW_DELAY_MS)` from `rooks`;
  - a `Card` titled `PREVIEW_TITLE()`, with the member/guest `Segmented` (`PREVIEW_VIEWER_MEMBER`, `PREVIEW_VIEWER_GUEST`) and no device switch;
  - the two `STAGES` as `LauncherPreviewFrame` with `device="desktop"` and `FRAME_HEIGHT_PX.desktop[key]`, a `Skeleton` before the first data, and `PREVIEW_ERROR()` when `isError && !data`.

  The copy comes from `pages/settings/design-settings.copy.ts`; add no new strings.
- `SetupStepLook.tsx`: render `<SetupLookPreview />` after the fields card and before `LOOK_LATER`.

- [ ] **Step 9: Check the SPA and step 4 by hand**

- Run `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: only the three `TS2307` errors listed in the README.
- Run `pnpm --filter @yayloyalty/admin-page exec eslint src/lib/api/setup.api.ts src/lib/queries/setup.queries.ts src/pages/setup src/pages/settings/DesignLauncherPreview.tsx`, then `prettier --check` on the same paths. Expected: no errors.
- With the site reset (`wp option delete yayloyalty_db_version yayloyalty_setup`, then load wp-admin) and `./scripts/run.sh dev`, open YayLoyalty. Enter "Star"/"Stars" and 2 per "1", Continue to step 4. Expected:
  - both frames show the launcher; the expanded guest frame says "Stars", with an earn line at 2 per unit;
  - choosing bottom-left and `#aa3300` redraws both frames within about half a second, and DevTools → Network shows one `setup/preview` request per pause, not one per keystroke;
  - clearing the colour field keeps the last preview, with no 400 in Network.
- Log in as a user with `yayloyalty_view` but not `yayloyalty_manage_settings` (a shop manager) and repeat. Expected: the same preview, and no 403.

- [ ] **Step 10: Commit**

Stage `includes/Engine/Storefront/LauncherPreview.php`, `includes/Helpers/SetupHelper.php`, `includes/Services/SetupApplier.php`, `includes/Controllers/SetupRestController.php`, `apps/admin/src/lib/api/setup.api.ts`, `apps/admin/src/lib/queries/setup.queries.ts`, `apps/admin/src/pages/settings/DesignLauncherPreview.tsx`, `apps/admin/src/pages/setup/SetupLookPreview.tsx` and `apps/admin/src/pages/setup/SetupStepLook.tsx`.
Message: `feat(setup): preview the launcher with the draft on step 4`.

### Task 2: Dashboard setup banners and program card

**Files:**
- Create: `apps/admin/src/pages/dashboard/components/DashboardSetupPending.tsx` and `apps/admin/src/pages/dashboard/components/DashboardFirstRun.tsx`
- Modify: `apps/admin/src/pages/dashboard/DashboardPage.tsx`, `apps/admin/src/pages/dashboard/dashboard-page.copy.ts`
- Modify: `apps/admin/src/lib/queries/setup.queries.ts` (`useSetupBuiltinNames()`), `apps/admin/src/pages/setup/SetupStepReview.tsx` (use it)
- Modify: `includes/Helpers/LocalizeHelper.php` (`wpMeta.homeUrl`), `apps/admin/src/types/localize.type.ts` (`Meta.wpMeta.homeUrl: string`)

**Interfaces:**
- Consumes: `window.yayLoyaltyAdmin.setup`, `useSetupQuery()` (its `live` block), `useDismissFirstRunMutation()` (Phase 1 Task 4), `spendPairFromRate()`, `can()`/`CAP`, and the `REVIEW_CAMPAIGNS_DEFAULT_NAMES` and `REVIEW_REWARD_DEFAULT_NAME` copy from `pages/setup/setup-wizard.copy.ts`
- Produces: `useSetupBuiltinNames(): { campaigns: string[] | undefined; reward: string | undefined }`, used only by this task and `SetupStepReview`
- Decided:
  - `DashboardSetupPending` renders while the status is `pending`: "Finish setting up your program", linking to `#/setup`.
  - `DashboardFirstRun` renders while the status is `completed` and `first_run_dismissed` is false:
    - the banner "Your program is live", with "Customers can earn from their next completed order." and "View your storefront", linking to `homeUrl`;
    - the "Your program" card: campaign names, "Points for discount · N points = X", and links to Campaigns, Rewards and Settings.

    Dismissing either one calls the mutation and hides both.
  - Both sit above `DashboardNoticeStrip`. The "Test it yourself" checklist, the Expiry row and the Pro tiles are not built.
  - The card works for a view-only user. `GET /campaigns` and `GET /rewards` need `manage_campaigns` and `manage_rewards`, so:
    - the names come from those lists only for users who hold the cap, with the seeded names otherwise (the pattern `SetupStepReview`'s `WhatThisCreates` already uses, moved into `useSetupBuiltinNames()`);
    - the rate and labels come from `GET /setup`'s `live` block, which needs only `yayloyalty_view`;
    - each link is shown only to users with its cap (`MANAGE_CAMPAIGNS`, `MANAGE_REWARDS`, `MANAGE_SETTINGS`).
  - The page reads `window.yayLoyaltyAdmin.setup` at render. `DashboardFirstRun` hides itself while the dismissal is pending or has succeeded. On an error it calls `toastApiError()` and shows again.

- [ ] **Step 1: `homeUrl` in localized meta**

`includes/Helpers/LocalizeHelper.php`: add `'homeUrl' => esc_url_raw( home_url() )` to `wpMeta`, after `siteUrl`. `apps/admin/src/types/localize.type.ts`: add `homeUrl: string` to `Meta.wpMeta`, at the same place.
Run `vendor/bin/phpcs includes/Helpers/LocalizeHelper.php`. Expected: no output. In wp-admin's console, `yayLoyaltyMeta.wpMeta.homeUrl` prints the site's front-end URL.

- [ ] **Step 2: Move the built-in names lookup into `useSetupBuiltinNames()`**

`apps/admin/src/lib/queries/setup.queries.ts`: `useSetupBuiltinNames()` holds `LIST_PARAMS` (`{ perPage: 100 }`) and the two cap-gated list queries from `WhatThisCreates`. It returns the built-in campaign names and the built-in reward's name, each `undefined` until loaded or when the user lacks the cap. `WhatThisCreates` in `SetupStepReview.tsx` calls it and keeps its fallbacks. Step 5 renders exactly as before.

- [ ] **Step 3: Copy**

`apps/admin/src/pages/dashboard/dashboard-page.copy.ts`, arrow functions with the `yayloyalty` domain:
- `SETUP_PENDING_TITLE` "Finish setting up your program"
- `SETUP_PENDING_ACTION` "Finish setup"
- `FIRST_RUN_TITLE` "Your program is live"
- `FIRST_RUN_BODY` "Customers can earn from their next completed order."
- `FIRST_RUN_STOREFRONT` "View your storefront"
- `FIRST_RUN_DISMISS` "Dismiss"
- `FIRST_RUN_DISMISS_ERROR` "Could not dismiss this."
- `PROGRAM_CARD_TITLE` "Your program"
- `PROGRAM_CARD_CAMPAIGNS` "Campaigns", `PROGRAM_CARD_REWARDS` "Rewards", `PROGRAM_CARD_SETTINGS` "Settings"
- `PROGRAM_CARD_REWARD(points: string, label: string, money: string)` "Points for discount · %1$s %2$s = %3$s", with a translators comment

- [ ] **Step 4: `DashboardSetupPending`**

`export function DashboardSetupPending()`: a `role="status"` card styled like `DashboardNoticeCard`'s non-affected severity, with `SETUP_PENDING_TITLE()` and a primary `Button asChild` wrapping `<Link to="/setup">` labelled `SETUP_PENDING_ACTION()`. It has no dismiss.

- [ ] **Step 5: `DashboardFirstRun`**

`export function DashboardFirstRun()`, built from `components/ui/`:
- a banner: `FIRST_RUN_TITLE()`, `FIRST_RUN_BODY()`, an `<a href={homeUrl} target="_blank" rel="noreferrer">` labelled `FIRST_RUN_STOREFRONT()`, and an `XIcon` dismiss button with the `aria-label` `FIRST_RUN_DISMISS()`;
- a `Card` titled `PROGRAM_CARD_TITLE()`:
  - the campaign names joined with " · ", or `REVIEW_CAMPAIGNS_DEFAULT_NAMES()`;
  - once `useSetupQuery()` has data, `PROGRAM_CARD_REWARD(formatPoints(points), label, parseWPCurrency(spend))` from `spendPairFromRate(live.reward.points_per_currency_unit)`. The label is `live.points_label_singular` when `points === 1`, else the plural;
  - the cap-gated links to `/campaigns`, `/rewards` and `/settings`.

Dismissal follows the Decided line.

- [ ] **Step 6: Wire into `DashboardPage`**

`const setup = window.yayLoyaltyAdmin.setup;` Directly above `{data && <DashboardNoticeStrip … />}`, render `DashboardSetupPending` when `setup.status === 'pending'`, and `DashboardFirstRun` when `setup.status === 'completed' && !setup.first_run_dismissed`. Neither waits for the dashboard query.

- [ ] **Step 7: Checks**

- Run `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit`. Expected: only the three `TS2307` errors.
- Run eslint, then `prettier --check`, on `src/pages/dashboard`, `src/pages/setup/SetupStepReview.tsx`, `src/lib/queries/setup.queries.ts` and `src/types/localize.type.ts`. Expected: no errors.
- In the wizard, step 5's "What this creates" shows the same names as before Step 2.

- [ ] **Step 8: Commit**

Stage `includes/Helpers/LocalizeHelper.php`, `apps/admin/src/types/localize.type.ts`, `apps/admin/src/lib/queries/setup.queries.ts`, `apps/admin/src/pages/setup/SetupStepReview.tsx`, `apps/admin/src/pages/dashboard/DashboardPage.tsx`, `apps/admin/src/pages/dashboard/dashboard-page.copy.ts`, `apps/admin/src/pages/dashboard/components/DashboardSetupPending.tsx` and `apps/admin/src/pages/dashboard/components/DashboardFirstRun.tsx`.
Message: `feat(setup): show setup and first-run banners on the dashboard`.

- [ ] **Step 9: Phase exit**

Run the exit criterion at the top of this file, end to end, as an administrator. Then:
- Reset again and repeat up to Turn on as a shop manager, who holds `view`, `manage_campaigns` and `manage_rewards` but not `manage_settings`. Expected:
  - the program card shows "Points for discount · 200 Stars = $1.00", in the store's currency format, with Campaigns and Rewards links and no Settings link;
  - Network shows no 403.
- Create a view-only user with `wp user create ylviewer ylviewer@example.test --role=subscriber`, then `wp user add-cap ylviewer yayloyalty_view`. Reset, log in as that user, and repeat up to Turn on. Expected:
  - the program card shows the seeded campaign names (`REVIEW_CAMPAIGNS_DEFAULT_NAMES`) and the same reward line, with no links;
  - Network shows no 403.

  Delete the user afterwards with `wp user delete ylviewer --yes`.
- Expected for the whole walkthrough: every check passes. Then set this phase's row and the plan's Status in `README.md` to done, and commit `README.md` with `docs(plans): mark the setup wizard plan done`.
