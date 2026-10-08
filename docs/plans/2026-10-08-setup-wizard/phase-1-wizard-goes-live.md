# Phase 1: The wizard goes live

**Plan:** `README.md` in this directory, which holds the Status, constraints and interface contract
**Depends on:** nothing
**Delivers:** a fresh install starts with the program off. Opening YayLoyalty lands on the five-step wizard, which saves a draft as you go, and "Turn on my program" applies every answer and turns the program on. Step 4 has its two fields but no preview yet.
**Exit criterion:** the walkthrough below passes in wp-admin with `./scripts/run.sh dev` running.
1. With WP-CLI (or Local's site shell), run `wp option delete yayloyalty_db_version yayloyalty_setup`, then load any wp-admin page. Expected:
   - `wp option get yayloyalty_setup --format=json` prints `{"status":"pending","step":1,"draft":null,"first_run_dismissed":false}`;
   - the storefront shows no launcher.
2. Open `/wp-admin/admin.php?page=yayloyalty`. Expected: `#/setup`, at "Step 1 of 5".
3. Enter "Star"/"Stars", Continue, and set 2 points per "1". Switch signup off, Continue, and set 200 points = "1", min 200, max 50%. Continue, choose `bottom_left` and `#aa3300`, then Continue to step 5.
4. Press "Exit setup", then reload. Expected: `#/setup` at step 5, with every value kept.
5. Press "Turn on my program". Expected:
   - `#/dashboard`;
   - Campaigns lists purchase at 2 per 1, signup paused, review active;
   - Rewards lists 200 = 1, min 200, max 50%;
   - Settings → Advanced shows "Star"/"Stars";
   - the storefront launcher is bottom-left in `#aa3300` and says "Stars";
   - reloading YayLoyalty stays on `#/dashboard`.

### Task 1: Setup state, fresh-install detection and localized state

**Files:**
- Create: `includes/Helpers/SetupHelper.php`
- Modify: `includes/Database/Installer.php` (`install()`)
- Modify: `includes/Helpers/LocalizeHelper.php` (`get_admin_data()`)
- Test: `tests/integration/Database/InstallerSetupStateTest.php`

**Interfaces:**
- Consumes: `SettingsHelper::update_settings()`, `SettingsHelper::POINTS_LABEL_MAX_LENGTH`, `DesignSchema::schema()['launcher']['position']['options']`
- Produces: `SetupHelper`, as listed in the README contract, and `window.yayLoyaltyAdmin.setup`

- [ ] **Step 1: Write the failing test**

The test saves `Installer::OPTION_DB_VERSION`, `SetupHelper::OPTION_SETUP` and `SettingsHelper::OPTION_SETTINGS` in `setUp()` and restores each one in `tearDown()`, deleting it when it was absent. The suite runs against the real site.

```php
public function test_an_existing_install_is_marked_completed_and_stays_on(): void {
    update_option( Installer::OPTION_DB_VERSION, '0.9.0' );
    delete_option( SetupHelper::OPTION_SETUP );
    SettingsHelper::update_settings( [ 'general' => [ 'enabled' => true ] ] );

    Installer::install();

    $this->assertSame( SetupHelper::STATUS_COMPLETED, SetupHelper::get_state()['status'] );
    $this->assertTrue( SettingsHelper::get_settings()['general']['enabled'] );
}

public function test_a_fresh_install_starts_pending_with_the_program_off(): void {
    delete_option( Installer::OPTION_DB_VERSION );
    delete_option( SetupHelper::OPTION_SETUP );
    SettingsHelper::update_settings( [ 'general' => [ 'enabled' => true ] ] );

    Installer::install();

    $this->assertSame(
        [ 'status' => 'pending', 'step' => 1, 'draft' => null, 'first_run_dismissed' => false ],
        get_option( SetupHelper::OPTION_SETUP )
    );
    $this->assertFalse( SettingsHelper::get_settings()['general']['enabled'] );
}

public function test_an_upgrade_keeps_a_pending_setup_where_it_was(): void {
    update_option( Installer::OPTION_DB_VERSION, '0.9.0' );
    update_option( SetupHelper::OPTION_SETUP, [ 'status' => 'pending', 'step' => 3, 'draft' => null, 'first_run_dismissed' => false ] );

    Installer::install();

    $this->assertSame( SetupHelper::STATUS_PENDING, SetupHelper::get_state()['status'] );
    $this->assertSame( 3, SetupHelper::get_state()['step'] );
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter InstallerSetupStateTest`
Expected: an error, because class `SetupHelper` is not found.

- [ ] **Step 3: Implement `SetupHelper` and wire it in**

- `SetupHelper::on_install( bool $is_fresh ): void` does nothing when the option exists.
  - When `$is_fresh`, it adds the pending state and calls `SettingsHelper::update_settings( [ 'general' => [ 'enabled' => false ] ] )`.
  - Otherwise it adds `{completed, 5, null, true}`.
- `Installer::install()` reads `$is_fresh = false === get_option( self::OPTION_DB_VERSION )` as its first line. It calls `SetupHelper::on_install( $is_fresh )` after `verify()` and before the version stamp.
- `get_state()` coerces the option:
  - a missing or non-array option, or an unknown status, reads as `{completed, 5, null, true}`;
  - `step` is clamped to 1–5;
  - `draft` that fails `draft_error()` reads as `null`.
- `draft_error()` checks every rule in the README's SetupDraft block and rejects unknown keys. The colour must match `/^#[0-9a-fA-F]{6}$/`, and the position must be in the launcher schema's options.
- `save_draft()` and `mark_completed()` (status `completed`, step 5, draft `null`) write with `update_option( …, true )`. `dismiss_first_run()` does too.
- `LocalizeHelper::get_admin_data()` adds `'setup' => SetupHelper::localized_state()` beside `settings` and `design`.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `composer test:integration -- --filter InstallerSetupStateTest`
Expected: `OK (3 tests, …)`. Read the count; an exit code of 0 alone proves nothing.

Then check the missing-option case. Run `wp option delete yayloyalty_setup` (`yayloyalty_db_version` still set), then `wp eval 'var_export( \YayLoyalty\Helpers\SetupHelper::get_state()["status"] );'`. Expected: `'completed'`.

- [ ] **Step 5: Lint the changed PHP**

Run: `vendor/bin/phpcs includes/Helpers/SetupHelper.php includes/Database/Installer.php includes/Helpers/LocalizeHelper.php tests/integration/Database/InstallerSetupStateTest.php`
Expected: no output.

- [ ] **Step 6: Commit**

Stage: the four files above
Message: `feat(setup): start fresh installs with the program off and setup pending`

### Task 2: SetupApplier

**Files:**
- Create: `includes/Services/SetupApplier.php`
- Test: `tests/integration/Services/SetupApplierTest.php`

**Interfaces:**
- Consumes:
  - `CampaignRepository::find_by_slug()`, `CampaignRepository::DEFAULT_PURCHASE_SLUG`, and the slugs `'signup'` and `'review'`;
  - `RewardRepository::find_by_slug()`, `RewardRepository::CART_DISCOUNT_SLUG`;
  - `Campaign::config_is_valid()`, `Reward::config_is_valid()`;
  - `SettingsHelper::update_settings()`, `DesignHelper::update_design()`, `Transaction::run()`.
- Produces: `SetupApplier::apply( array $draft ): void` (see the README contract)

- [ ] **Step 1: Write the failing test**

`setUp()` saves and later restores `SettingsHelper::OPTION_SETTINGS`, `DesignHelper::OPTION_DESIGN` and `SetupHelper::OPTION_SETUP`. It then calls `Installer::install()`, which re-seeds the built-ins the parent truncated. `draft()` returns:
```
{ points_label_singular 'Star', points_label_plural 'Stars',
  earn { points 3, amount '2' },
  signup { enabled false, points 100 }, review { enabled true, points 50 },
  reward { points 200, amount '1', min_points 200, max_percent 100 },
  launcher { position 'bottom_left', brand_color '#aa3300' } }
```

```php
public function test_apply_writes_the_rates_and_turns_the_program_on(): void {
    $this->applier()->apply( $this->draft() );

    $campaigns = new CampaignRepository();
    $campaigns->flush_cache();
    $purchase = $campaigns->find_by_slug( CampaignRepository::DEFAULT_PURCHASE_SLUG );
    $this->assertEqualsWithDelta( 1.5, (float) $purchase->get_config()['points_per_unit'], 1e-9 );
    $this->assertSame( Status::ACTIVE, $purchase->get_status() );
    $this->assertSame( Status::PAUSED, $campaigns->find_by_slug( 'signup' )->get_status() );
    $this->assertSame( Status::ACTIVE, $campaigns->find_by_slug( 'review' )->get_status() );
    $this->assertSame( 50, (int) $campaigns->find_by_slug( 'review' )->get_config()['points'] );

    $reward = ( new RewardRepository() )->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );
    $this->assertEqualsWithDelta( 200.0, (float) $reward->get_config()['points_per_currency_unit'], 1e-9 );
    $this->assertSame( 200, (int) $reward->get_config()['min_points'] );
    $this->assertNull( $reward->get_config()['max_percent_of_subtotal'] );
    $this->assertSame( Status::ACTIVE, $reward->get_status() );

    $settings = SettingsHelper::get_settings();
    $this->assertSame( 'Star', $settings['advanced']['points_label_singular'] );
    $this->assertSame( 'Stars', $settings['advanced']['points_label_plural'] );
    $this->assertTrue( $settings['general']['enabled'] );
    $this->assertSame( 'bottom_left', DesignHelper::get_design()['launcher']['position'] );
    $this->assertSame( '#aa3300', strtolower( DesignHelper::get_design()['launcher']['brand_color'] ) );
}

public function test_a_cap_below_100_percent_is_stored(): void {
    $draft                          = $this->draft();
    $draft['reward']['max_percent'] = 50;

    $this->applier()->apply( $draft );

    $reward = ( new RewardRepository() )->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );
    $this->assertEqualsWithDelta( 50.0, (float) $reward->get_config()['max_percent_of_subtotal'], 1e-9 );
}

public function test_a_missing_builtin_changes_nothing(): void {
    SettingsHelper::update_settings( [ 'general' => [ 'enabled' => false ] ] );
    $settings_before = get_option( SettingsHelper::OPTION_SETTINGS );
    $design_before   = get_option( DesignHelper::OPTION_DESIGN );
    $GLOBALS['wpdb']->delete( Table::name( 'campaigns' ), [ 'slug' => 'review' ] );
    ( new CampaignRepository() )->flush_cache();

    try {
        $this->applier()->apply( $this->draft() );
        $this->fail( 'apply() should throw when a built-in is missing.' );
    } catch ( \RuntimeException $e ) {
        // Expected.
    }

    $campaigns = new CampaignRepository();
    $campaigns->flush_cache();
    $this->assertEqualsWithDelta( 1.0, (float) $campaigns->find_by_slug( CampaignRepository::DEFAULT_PURCHASE_SLUG )->get_config()['points_per_unit'], 1e-9 );
    $this->assertSame( $settings_before, get_option( SettingsHelper::OPTION_SETTINGS ) );
    $this->assertSame( $design_before, get_option( DesignHelper::OPTION_DESIGN ) );
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter SetupApplierTest`
Expected: an error, because class `SetupApplier` is not found.

- [ ] **Step 3: Implement `SetupApplier::apply( array $draft ): void`**

The whole body runs inside `Transaction::run()`. That function nests safely, so the REST route's own `exec_write()` transaction still works. The order is fixed so that options, which the object cache holds outside the transaction, are written only after every row write has succeeded.

1. Load the 3 campaigns and the reward. If any is null, throw `\RuntimeException` naming the slug.
2. Build each new config as the stored config with only these keys replaced:
   - purchase `points_per_unit = draft.earn.points / (float) draft.earn.amount`;
   - signup and review `points`;
   - reward `points_per_currency_unit = draft.reward.points / (float) draft.reward.amount`, `min_points`, and `max_percent_of_subtotal` (`null` when 100, otherwise the number).

   Validate each with `config_is_valid()`, and throw on a rejection.
3. Set the statuses (purchase and reward `ACTIVE`; signup and review `ACTIVE` when enabled, `PAUSED` when not), plus `set_updated_at( Dates::now() )` and `set_updated_by( get_current_user_id() )`. Write each row with `to_update_row()` and the repository's `update()`.
4. `SettingsHelper::update_settings()` with both labels and `general.enabled = true`.
5. `DesignHelper::update_design( [ 'launcher' => [ 'position' => …, 'brand_color' => … ] ] )`.

The applier doesn't validate the draft (the controller calls `draft_error()` first) and doesn't touch `yayloyalty_setup`.

- [ ] **Step 4: Run the test to confirm it passes**

Run: `composer test:integration -- --filter SetupApplierTest`
Expected: `OK (3 tests, …)`.

- [ ] **Step 5: Lint the changed PHP**

Run: `vendor/bin/phpcs includes/Services/SetupApplier.php tests/integration/Services/SetupApplierTest.php`
Expected: no output.

- [ ] **Step 6: Commit**

Stage: `includes/Services/SetupApplier.php`, `tests/integration/Services/SetupApplierTest.php`
Message: `feat(setup): apply the wizard's answers in one transaction`

### Task 3: SetupRestController

**Files:**
- Create: `includes/Controllers/SetupRestController.php`
- Modify: `includes/Engine/RestAPI.php` (`controllers()`)

**Interfaces:**
- Consumes: `SetupHelper` (Task 1), `SetupApplier` (Task 2), `AbstractRestController`, `exec_read()`, `exec_write()`, `error_invalid_arguments()`, `error_conflict()`
- Produces: `GET /setup`, `POST /setup/draft`, `POST /setup/complete` and `POST /setup/dismiss-first-run` (see the README contract)

- [ ] **Step 1: Implement `SetupRestController extends AbstractRestController`**

- `get_rest_base()` returns `'setup'`. The constructor takes `( CampaignRepository $campaigns, RewardRepository $rewards )`. Every route uses `$this->requires( Capabilities::VIEW )`.
- Reads go through `exec_read()` and writes through `exec_write()`.
- `GET /setup` returns `get_state()`'s `status`, `step` and `draft`, plus `live`, built from the stored settings, the 3 built-ins, the reward and `DesignHelper::get_design()['launcher']`. Keys and types are exactly those in the README's SetupLive block.
- `POST /setup/draft`:
  - returns 400 when `step` is not an integer from 1 to 5, or when `draft_error()` returns a message (that message is the error text);
  - otherwise returns `save_draft()`.
- `POST /setup/complete`:
  - returns 409 via `error_conflict( __( 'Setup is already complete.', 'yayloyalty' ) )` when `get_state()['status']` is `completed`; this is read inside `exec_write()`'s transaction;
  - returns 400 on `draft_error()`;
  - otherwise calls `( new SetupApplier( … ) )->apply( $draft )`, then `SetupHelper::mark_completed()`, and returns `[ 'status' => 'completed' ]`.

  A `RuntimeException` becomes `exec_write()`'s 500, with the error logged.
- `POST /setup/dismiss-first-run` calls `dismiss_first_run()` and returns `[ 'first_run_dismissed' => true ]`.
- In `RestAPI::controllers()`, add `new SetupRestController( $campaigns, $rewards )` after `SettingsRestController`.

- [ ] **Step 2: Lint the changed PHP**

Run: `vendor/bin/phpcs includes/Controllers/SetupRestController.php includes/Engine/RestAPI.php`
Expected: no output.

- [ ] **Step 3: Verify the routes as a shop manager**

In a wp-admin browser console, signed in as a `shop_manager` user, with the status reset to pending via `wp option update yayloyalty_setup '{"status":"pending","step":1,"draft":null,"first_run_dismissed":false}' --format=json`. Each call uses `fetch(yayLoyaltyMeta.wpMeta.restRoot + 'yayloyalty/v1/' + path, { method, headers: { 'X-WP-Nonce': yayLoyaltyMeta.wpMeta.restNonce, 'Content-Type': 'application/json' }, body })`.

| Call | Expected |
|---|---|
| `GET setup` | 200 with `status: "pending"` and `live.purchase_rate` |
| `POST setup/draft` with `{"step":9,"draft":{}}` | 400 |
| `POST setup/draft` with the Task 2 test draft and `"step":3` | 200; `wp option get yayloyalty_setup` shows step 3, and Campaigns is unchanged |
| `POST setup/complete` with the same draft | 200 `{"status":"completed"}` |
| `POST setup/complete` again | 409, and nothing changes |
| `POST settings` with `{}` | 403, so the user still can't use the Settings route |

- [ ] **Step 4: Commit**

Stage: `includes/Controllers/SetupRestController.php`, `includes/Engine/RestAPI.php`
Message: `feat(setup): add the setup REST routes`

### Task 4: SPA plumbing, route and wizard shell

**Files:**
- Create: `apps/admin/src/lib/schema/setup.schema.ts`, `apps/admin/src/lib/api/setup.api.ts`, `apps/admin/src/lib/queries/setup.queries.ts`
- Create: `apps/admin/src/pages/setup/SetupWizardPage.tsx`, `apps/admin/src/pages/setup/SetupWizardLayout.tsx`, `apps/admin/src/pages/setup/setup-wizard.copy.ts`, `apps/admin/src/pages/setup/setup-wizard.draft.ts`
- Modify: `apps/admin/src/types/localize.type.ts` (`AdminData.setup: SetupLocalized`), `apps/admin/src/router.tsx`

**Interfaces:**
- Consumes: the REST routes (Task 3), `window.yayLoyaltyAdmin.setup` (Task 1), `spendPairFromRate()` from `lib/helpers/points.helper.ts`, `toastApiError()`
- Produces: the SPA names in the README contract except `previewSetup` and `useSetupPreviewQuery`. Also `SETUP_STEPS` (step number → component), which Tasks 5 and 6 fill in.

- [ ] **Step 1: Schema, API and queries**

- `setupDraftSchema` mirrors `SetupHelper::draft_error()`'s rules. Amounts are `z.string().regex(/^\d+(\.\d+)?$/)` refined to > 0.
- `setupStateSchema` and `setupLiveSchema` mirror the README blocks.
- `*.api.ts` parses each response with its schema.
- `useCompleteSetupMutation()` `onSuccess`:
  - sets `window.yayLoyaltyAdmin.setup.status = 'completed'`;
  - writes the new settings into `SETTINGS_QUERIES.main` by invalidating it;
  - invalidates the `['campaigns']`, `['rewards']`, `['design']` and `['dashboard']` prefixes.
- `useSaveSetupDraftMutation()` updates `window.yayLoyaltyAdmin.setup.step`.

- [ ] **Step 2: `draftFromLive( live: SetupLive ): SetupDraft` in `setup-wizard.draft.ts`**

- `earn` comes from `spendPairFromRate(live.purchase_rate)`: `{ points, amount: String(spend) }`.
- `reward` comes from `spendPairFromRate(live.reward.points_per_currency_unit)` in the same way, with `max_percent: live.reward.max_percent_of_subtotal ?? 100`.
- Every other field is copied.

- [ ] **Step 3: Route and first-load redirect in `router.tsx`**

- Add a top-level `{ path: '/setup', element: guarded(CAP.VIEW, <SetupWizardPage />), loader }` beside `/`, outside `AppLayout`. Its loader returns `redirect('/dashboard')` when `window.yayLoyaltyAdmin.setup.status === 'completed'`.
- At the top of `getManagerRouter()`, when the status is `pending` and the hash isn't already `#/setup`, call `window.history.replaceState(null, '', '#/setup')`. This runs once per full page load, so after Exit the rest of that visit is normal.

- [ ] **Step 4: `SetupWizardPage` and `SetupWizardLayout`**

- `SetupWizardPage` loads `useSetupQuery()`. Its form is react-hook-form with `zodResolver(setupDraftSchema)` over `draft ?? draftFromLive(live)`. It starts at the state's `step`.
- Navigation:
  - Continue validates only the current step's fields (`trigger([...])`), then saves the draft with the next step and moves there.
  - Back moves one step without saving.
  - "Skip" saves the draft with step 5 and jumps there.
  - "Exit setup" saves the draft at the current step, then `navigate('/dashboard')`.
  - A `returnToReview` flag, set by step 5's Edit links, makes Continue go back to 5.
- `SetupWizardLayout` renders the header "YayLoyalty setup · Step n of 5 · Exit setup" and the footer "‹ Back · Skip · Continue" (no Back on step 1), with a `<Toaster />`. Save errors go to `toastApiError()`.
- All strings live in `setup-wizard.copy.ts`.

- [ ] **Step 5: Verify**

Run (from `apps/admin`): `node node_modules/typescript/bin/tsc --noEmit`
Expected: only the three known `TS2307` errors.

Run: `pnpm --filter @yayloyalty/admin-page exec eslint src/pages/setup src/lib/schema/setup.schema.ts src/lib/api/setup.api.ts src/lib/queries/setup.queries.ts src/router.tsx src/types/localize.type.ts`
Expected: no errors and no warnings.

In wp-admin, with the status pending and `./scripts/run.sh dev` running, open `/wp-admin/admin.php?page=yayloyalty`:
- Expected: `#/setup` at step 1, with empty step bodies. Continue moves to step 2, and `wp option get yayloyalty_setup` shows step 2.
- Press Exit. Expected: `#/dashboard`. Navigating to `#/campaigns` stays there, and reloading opens `#/setup` at step 2.

- [ ] **Step 6: Commit**

Stage: the eight files above
Message: `feat(setup): add the setup wizard route, draft saving and shell`

### Task 5: Steps 1 to 4

**Files:**
- Create: `apps/admin/src/pages/setup/SetupStepPointsName.tsx`, `SetupStepEarn.tsx`, `SetupStepSpend.tsx`, `SetupStepLook.tsx` (all in `apps/admin/src/pages/setup/`)
- Modify: `apps/admin/src/pages/setup/setup-wizard.draft.ts`, `setup-wizard.copy.ts`, `SetupWizardPage.tsx` (`SETUP_STEPS`)

**Interfaces:**
- Consumes: the form context and `SETUP_STEPS` (Task 4), and `formatPoints`, `formatStoreNumber`, `currencyDecimals`, `storeCurrency` and `storeCurrencySymbol` from `lib/helpers/`
- Produces: `earnedOn`, `rewardWorth`, `earnBackPercent` (see the README contract)

- [ ] **Step 1: Math in `setup-wizard.draft.ts`**

- `earnedOn(draft, subtotal) = Math.floor(subtotal × earn.points ÷ Number(earn.amount))`, rounded down as the built-in purchase campaign does.
- `rewardWorth(draft, points)` is `points × Number(reward.amount) ÷ reward.points`, rounded to `currencyDecimals()`.
- `earnBackPercent(draft) = (earn.points ÷ Number(earn.amount)) ÷ (reward.points ÷ Number(reward.amount)) × 100`.

- [ ] **Step 2: The four step components, each following its wireframe minus the skipped controls**

- **Step 1** (1a):
  - singular and plural inputs, at most 30 characters;
  - the preview line "You have 850 {plural}";
  - the store currency, read-only, as "`storeCurrency()` (`storeCurrencySymbol()`)", with the note to change it in WooCommerce → General.
  - No icon picker.
- **Step 2** (1b):
  - "N point(s) for every X {currency} spent";
  - the worked line "A {symbol}50 order earns `earnedOn(draft, 50)` {label}";
  - the note "Points arrive when the order is completed.";
  - two cards with a switch and a points input: signup (description "Awarded once, when the account is created.") and review ("Verified purchases only…");
  - the line "These three become your campaigns…".
  - No timing choice and no templates.
- **Step 3** (1c):
  - "N points = X {currency} off an order";
  - the help line "At the rate in step 2, a customer earns back `earnBackPercent`%";
  - "Smallest redemption", showing "= `rewardWorth(draft, min_points)`";
  - "Most of an order points can cover", 1–100%.
  - No checkout preview.
- **Step 4** (1d):
  - position as a two-option `Segmented` (Bottom right, Bottom left);
  - brand colour through `components/ui/color-picker.tsx`;
  - the "Later, in Settings → Design" note.
  - The preview area is left empty for Phase 2.

- [ ] **Step 3: Verify**

Run the Task 4 `tsc` and `eslint` commands on `src/pages/setup`.
Expected: only the three known `TS2307` errors, and no eslint errors or warnings.

In the wizard:
- Step 1: an empty plural, or one of 31 characters, blocks Continue with an inline error.
- Step 2: 3 points per "2" shows "A $50 order earns 75 Points"; 0 points blocks Continue.
- Step 3: 100 points = "1" with min 150 shows "= $1.50". Then set WooCommerce → Settings → General → Number of decimals to 0 and reload: the same values show "= $2". Restore the decimals afterwards.
- Step 4: the position and colour persist across Back and Continue.

- [ ] **Step 4: Commit**

Stage: the seven files above
Message: `feat(setup): add the points, earning, spending and look steps`

### Task 6: Step 5, review and turn on

**Files:**
- Create: `apps/admin/src/pages/setup/SetupStepReview.tsx`
- Modify: `apps/admin/src/pages/setup/SetupWizardPage.tsx` (`SETUP_STEPS[5]`, footer on step 5), `setup-wizard.copy.ts`

**Interfaces:**
- Consumes: `useCompleteSetupMutation()`, `useSaveSetupDraftMutation()` (Task 4), and the math from Task 5
- Produces: nothing new

- [ ] **Step 1: `SetupStepReview`, following artboard 1e minus the skipped rows**

- **Program card**, one row per answer, each with an Edit link that sets `returnToReview` and opens its step:
  - "Points are called: {singular} · {plural} · {currency}";
  - "Customers earn: N per X, when the order is completed";
  - signup, either "N points, once per customer" or "Off";
  - review, either "N points, verified purchases only" or "Off";
  - "Points are worth: N = X off";
  - "Redemption limits: from N points, up to M% of the subtotal";
  - "Launcher: {position label}, {colour}".
- **"What this creates":** the campaign names from the live Campaigns query (Points for purchases · Points for signing up · Points for writing a review), linking to `#/campaigns`, and the reward "Points for discount", linking to `#/rewards`.
- **"When you turn it on":** exactly two rows. "The three campaigns start earning on the next order that completes; orders already placed are not counted." and "The launcher appears on your storefront."
- **Footer on step 5:** the button "Turn on my program", which calls `completeSetup(draft)` and then `navigate('/dashboard')`, and the link "Save and finish later", which saves the draft at step 5 and then `navigate('/dashboard')`. On a 409, the mutation's error toast shows and the page goes to `#/dashboard`.

- [ ] **Step 2: Verify**

Run the Task 4 `tsc` and `eslint` commands on `src/pages/setup`.
Expected: only the three known `TS2307` errors, and no eslint errors or warnings.

Run Prettier on every SPA file this phase changed: `pnpm --filter @yayloyalty/admin-page exec prettier --check src/pages/setup src/lib/schema/setup.schema.ts src/lib/api/setup.api.ts src/lib/queries/setup.queries.ts src/router.tsx src/types/localize.type.ts`. Expected: every file passes. If not, run it again with `--write`.

Check the Pro/Lite split (spec criterion 14). The build validator can't run today: `release` fails at the type-check. Run: `git grep -nE "@/pro|YayLoyaltyPro|IS_PRO_BUILD" -- apps/admin/src/pages/setup apps/admin/src/lib/schema/setup.schema.ts apps/admin/src/lib/api/setup.api.ts apps/admin/src/lib/queries/setup.queries.ts includes/Helpers/SetupHelper.php includes/Services/SetupApplier.php includes/Controllers/SetupRestController.php`
Expected: no output.

Then run the phase exit walkthrough at the top of this file. Expected: every check in it passes.

- [ ] **Step 3: Commit**

Stage: the three files above, plus any file Prettier reformatted
Message: `feat(setup): add the review step and turn the program on`
