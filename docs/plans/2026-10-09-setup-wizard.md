# Setup Wizard Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. Read the spec linked below before starting; the plan argues from it.

**Status:** active
**Branch:** `paul/feat/wizard` (already created from `dev/edd`; PR into `dev/edd`)
**Goal:** a five-step setup wizard modal over the admin SPA. "Turn on my program" saves every choice in one `POST /yayloyalty/v1/setup`.
**Spec:** [docs/specs/2026-10-09-setup-wizard-design.md](../specs/2026-10-09-setup-wizard-design.md)
**Architecture:** one aggregate REST resource, `/setup`, with a `SetupService` that updates rows that already exist: the settings and design options, the three built-in campaigns and the `cart-discount` reward. It was chosen over calling five existing endpoints from the SPA because that sequence is not atomic. The SPA mounts the modal from `AppLayout` while the localized flag is false. This is the yay-reviews onboarding pattern.
**Touches:**
- PHP: `includes/Helpers/SettingsHelper.php`, `includes/Helpers/LocalizeHelper.php`, `includes/Engine/RestAPI.php`, new `includes/Services/SetupService.php` and `includes/Controllers/SetupRestController.php`.
- SPA: `apps/admin/src/AppLayout.tsx`, `apps/admin/src/types/localize.type.ts`, new `lib/{schema,api,queries}/setup.*.ts` and `components/setup-wizard/*`.
- No shared code.

**Tests:** `tests/integration/Controllers/SetupRouteTest.php`, integration suite. Accepted 2026-10-09. It guards the rate conversion and the writes to the built-in rows (Task 1).

## Global constraints

- Product PHP must run on PHP 7.4:
  - No enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
  - Also none of: `mixed`/`static`/`never` types, a `catch` without a variable, `throw` as an expression, or a trailing comma after the last declared parameter.
- REST callbacks are `fn( $request ) => $this->exec_read|exec_write( … )`. Validate before any write, and answer with `$this->error_*()`.
- Writes go controller → service → repository. A repository never opens a transaction.
- Wire data is snake_case. Mapping to camelCase happens only in `*.api.ts`. Rates are JSON numbers; points are integers.
- "No limit" is `null`, never 0: 100% is sent as `max_percent_of_subtotal: null`.
- Every TypeScript copy of a PHP shape opens with "Mirrors <PHP symbol>, the source of truth."
- Files stay under 200 lines. Components are PascalCase `.tsx`; other modules are `<owner>.<role>.ts`.
- Number formatting uses `formatPoints` / `formatStoreNumber` / `parseWPCurrency`, never `toLocaleString()`.
- No Pro code. The wizard is the same in Lite and Pro, so nothing goes in `src/pro/` and there are no `IS_PRO_BUILD` branches.
- Translatable strings use the `yayloyalty` text domain. Strings with placeholders get `/* translators: */`.
- Don't write `daily_cap` (the active plan `2026-10-08-launcher-advert-review-fixes` removes it).
- Known failures on `dev/edd`: `composer lint` on the whole repo, admin `lint`, and `tsc --noEmit` (TS2307 for `@emoji-mart/*` and `@radix-ui/react-progress`). For all of these, expect **no new errors** in the changed files.

## Review focus

- **Purchase rate.** The design's "X points for every Y spent" is stored as `points_per_unit` = X ÷ Y. The spend field must not be stored as the rate. Checked by the SetupRouteTest assertion `points_per_unit = 2`.
- **Reward rate.** The design's "N points = M off" is stored as `points_per_currency_unit` = N ÷ M, not as N. Checked in SetupRouteTest (200 points = $1 gives 200) and in the Task 5 walkthrough (500 points = $5 gives 100).
- **Max percent.** 100% must become `null`, never `100` or `0`. Checked in SetupRouteTest.
- **Config merges.** A wizard save must keep the config keys the wizard doesn't show (`currency`, `require_verified`, `base`, …). SetupRouteTest asserts that `cart-discount.config.currency` and `review.config.require_verified` survive.
- **Return to review.** After an Edit from step 5, Continue must go back to step 5, not on to the next step. Checked in Task 5 Step 3.

---

### Task 1: Setup option, service and REST route

**Files:**
- Modify: `includes/Helpers/SettingsHelper.php` (constants next to `OPTION_REVIEWED`)
- Modify: `includes/Helpers/LocalizeHelper.php` (`get_meta()` → `loyaltyMeta`)
- Create: `includes/Services/SetupService.php`
- Create: `includes/Controllers/SetupRestController.php`
- Modify: `includes/Engine/RestAPI.php` (`controllers()`)
- Test: `tests/integration/Controllers/SetupRouteTest.php`

**Interfaces:**
- Produces:
  - `SettingsHelper::OPTION_SETUP_COMPLETED = 'yayloyalty_setup_completed'`
  - `window.yayLoyaltyMeta.loyaltyMeta.setupCompleted: bool` (`(bool) get_option( OPTION_SETUP_COMPLETED, false )`)
  - `GET /yayloyalty/v1/setup` (`Capabilities::VIEW`) and `POST /yayloyalty/v1/setup` (`Capabilities::MANAGE_SETTINGS`). Both return the **setup payload**:
    ```json
    {
      "points_label_singular": "Point", "points_label_plural": "Points",
      "purchase": { "points_per_unit": 1 },
      "signup":   { "enabled": true, "points": 100 },
      "review":   { "enabled": true, "points": 50 },
      "cart_discount": { "points_per_currency_unit": 100, "min_points": 100, "max_percent_of_subtotal": null },
      "brand_color": "#1f6fb2"
    }
    ```
    POST takes the same shape and returns it re-read after the save.
  - `SetupService::current(): array` and `SetupService::complete( array $input ): array`.

- [ ] **Step 1: Write the failing test** `tests/integration/Controllers/SetupRouteTest.php`, extending `IntegrationTestCase`.
  - `setUp()`:
    - Call `rest_get_server()`.
    - Call `\YayLoyalty\Database\Installer::install()`. `IntegrationTestCase` truncates the tables, so the built-ins must be re-seeded.
    - Snapshot `get_option( 'yayloyalty_settings' )` and `get_option( 'yayloyalty_design' )`.
  - `tearDown()`: restore both snapshots, `delete_option( 'yayloyalty_setup_completed' )`, then `wp_set_current_user( 0 )`.

```php
public function test_post_saves_every_choice_onto_the_builtin_rows(): void {
    $this->act_as_admin();

    $response = $this->send( 'POST', '/yayloyalty/v1/setup', [
        'points_label_singular' => 'Star',
        'points_label_plural'   => 'Stars',
        'purchase'              => [ 'points_per_unit' => 2 ],
        'signup'                => [ 'enabled' => false, 'points' => 100 ],
        'review'                => [ 'enabled' => true, 'points' => 75 ],
        'cart_discount'         => [ 'points_per_currency_unit' => 200, 'min_points' => 200, 'max_percent_of_subtotal' => 50 ],
        'brand_color'           => '#ff0000',
    ] );

    $this->assertSame( 200, $response->get_status() );

    $campaigns = new CampaignRepository();
    $purchase  = $campaigns->find_by_slug( 'default-purchase' );
    $signup    = $campaigns->find_by_slug( 'signup' );
    $review    = $campaigns->find_by_slug( 'review' );
    $discount  = ( new RewardRepository() )->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );

    $this->assertEquals( 2, $purchase->get_config()['points_per_unit'] );
    $this->assertSame( Status::PAUSED, $signup->get_status() );
    $this->assertSame( Status::ACTIVE, $review->get_status() );
    $this->assertSame( 75, $review->get_config()['points'] );
    $this->assertTrue( $review->get_config()['require_verified'] );
    $this->assertEquals( 200, $discount->get_config()['points_per_currency_unit'] );
    $this->assertSame( 200, $discount->get_config()['min_points'] );
    $this->assertEquals( 50, $discount->get_config()['max_percent_of_subtotal'] );
    $this->assertNotEmpty( $discount->get_config()['currency'] );
    $this->assertSame( 'Stars', SettingsHelper::get_settings()['points_label_plural'] );
    $this->assertTrue( SettingsHelper::get_settings()['enabled'] );
    $this->assertSame( '#ff0000', DesignHelper::get_design()['launcher']['brand_color'] );
    $this->assertTrue( (bool) get_option( SettingsHelper::OPTION_SETUP_COMPLETED ) );
}

public function test_a_null_max_percent_is_stored_as_no_limit(): void {
    $this->act_as_admin();
    $body = $this->send( 'GET', '/yayloyalty/v1/setup' )->get_data();
    $body['cart_discount']['max_percent_of_subtotal'] = null;

    $this->send( 'POST', '/yayloyalty/v1/setup', $body );

    $discount = ( new RewardRepository() )->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );
    $this->assertNull( $discount->get_config()['max_percent_of_subtotal'] );
}

public function test_get_returns_the_seeded_defaults(): void {
    $this->act_as_admin();
    $data = $this->send( 'GET', '/yayloyalty/v1/setup' )->get_data();

    $this->assertEquals( 1, $data['purchase']['points_per_unit'] );
    $this->assertSame( [ 'enabled' => true, 'points' => 100 ], $data['signup'] );
    $this->assertSame( [ 'enabled' => true, 'points' => 50 ], $data['review'] );
    $this->assertEquals( 100, $data['cart_discount']['points_per_currency_unit'] );
    $this->assertNull( $data['cart_discount']['max_percent_of_subtotal'] );
}
```

  If any getter name differs on `Campaign` or `Reward` (for example `get_config()` or `get_status()`), use the model's actual getter. The assertions stay the same.

- [ ] **Step 2: Run the test to confirm it fails**

Run: `composer test:integration -- --filter SetupRouteTest`
Expected: FAIL with a 404, because there is no route `/yayloyalty/v1/setup`. If the output shows "Error establishing a database connection" or "plugin is not active", see `docs/known-issues.md` (`YAYLOYALTY_TEST_SOCKET`). That output does not count as a run.

- [ ] **Step 3: Add the flag.**
  - `SettingsHelper`: `public const OPTION_SETUP_COMPLETED = 'yayloyalty_setup_completed';`
  - `LocalizeHelper::get_meta()`: add `'setupCompleted' => (bool) get_option( SettingsHelper::OPTION_SETUP_COMPLETED, false ),` to `loyaltyMeta`, after `reviewed`.

- [ ] **Step 4: Implement `includes/Services/SetupService.php`** (`namespace YayLoyalty\Services`, with a class docblock).
  - Constructor: `__construct( CampaignRepository $campaigns, RewardRepository $rewards )`.
  - `current(): array` builds the setup payload:
    - The labels come from `SettingsHelper::get_settings()`.
    - `brand_color` comes from `DesignHelper::get_design()['launcher']['brand_color']`.
    - `purchase.points_per_unit` comes from `find_by_slug( 'default-purchase' )`.
    - For `signup` and `review`, `enabled` is `Status::ACTIVE === status`, and `points` comes from the config.
    - `cart_discount` takes its three keys from the `cart-discount` config, with `max_percent_of_subtotal` defaulting to `null`.
  - `validation_error( array $input ): ?string` returns a translated message when something is wrong, otherwise null:
    - Each of the three campaigns and the reward must exist.
    - The merged config of each must pass `Campaign::config_is_valid( $trigger, $merged )` or `Reward::config_is_valid( RewardType::FLEXIBLE_DISCOUNT, $merged )`.
    - `brand_color` must pass `sanitize_hex_color()`.
  - `complete( array $input ): array`:
    1. Merge the input keys over each row's stored `get_config()`, so keys the wizard doesn't show survive.
    2. Signup and review get `set_status( enabled ? Status::ACTIVE : Status::PAUSED )`. The purchase campaign's status is left alone.
    3. Stamp `set_updated_at( Dates::now() )` and `set_updated_by( get_current_user_id() )`, as in `CampaignRestController::apply_update`.
    4. Write each row through `$row->to_update_row()` and `repository->update( id, data, format )`.
    5. Call `SettingsHelper::update_settings( [ 'points_label_singular' => …, 'points_label_plural' => …, 'enabled' => true ] )`.
    6. Call `DesignHelper::update_design( [ 'launcher' => [ 'brand_color' => … ] ] )`.
    7. Call `update_option( SettingsHelper::OPTION_SETUP_COMPLETED, true )`.
    8. Return `current()`.

- [ ] **Step 5: Implement `includes/Controllers/SetupRestController.php`** (`extends AbstractRestController`, `get_rest_base()` returns `'setup'`). Copy the route block from `SettingsRestController`:
  - The GET callback, `get_setup`, returns `$this->setup->current()`.
  - The POST callback, `complete_setup`:
    1. Reads `get_json_params()`.
    2. Answers `error_invalid_arguments()` if the body isn't an array.
    3. Answers `error_invalid_arguments( $message )` if `validation_error()` returns a message.
    4. Otherwise returns `complete()`.
  - Constructor: `__construct( SetupService $setup )`.

- [ ] **Step 6: Register it** in `RestAPI::controllers()`: `new SetupRestController( new SetupService( $campaigns, $rewards ) ),`, after `DesignRestController`, reusing the `$campaigns` and `$rewards` already built there.

- [ ] **Step 7: Run the test to confirm it passes**

Run: `composer test:integration -- --filter SetupRouteTest`
Expected: PASS, 3 tests.

- [ ] **Step 8: Lint the changed files**

Run: `composer lint -- includes/Services/SetupService.php includes/Controllers/SetupRestController.php includes/Engine/RestAPI.php includes/Helpers/SettingsHelper.php includes/Helpers/LocalizeHelper.php tests/integration/Controllers/SetupRouteTest.php`
Expected: no errors in these files.

- [ ] **Step 9: Commit**

Stage: the six files above.
Message: `feat(setup): add setup completed flag and /setup REST route`

### Task 2: SPA data layer and modal shell with step 1

**Files:**
- Modify: `apps/admin/src/types/localize.type.ts` (`loyaltyMeta.setupCompleted: boolean`)
- Create: `apps/admin/src/lib/schema/setup.schema.ts`
- Create: `apps/admin/src/lib/api/setup.api.ts`
- Create: `apps/admin/src/lib/queries/setup.queries.ts`
- Create: `apps/admin/src/components/setup-wizard/setup-wizard.form.ts`
- Create: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx`
- Create: `apps/admin/src/components/setup-wizard/NamePointsStep.tsx`
- Modify: `apps/admin/src/AppLayout.tsx`

**Interfaces:**
- Consumes: the Task 1 setup payload and `loyaltyMeta.setupCompleted`.
- Produces:
  - `setupSchema` / `type Setup` (zod, snake_case, mirrors `SetupService::current()`).
  - `getSetup(): Promise<Setup>` and `completeSetup( data: Setup ): Promise<Setup>`.
  - `useSetupQuery()` (key `['setup', 'main']`) and `useCompleteSetupMutation()`. On success, the mutation invalidates `['settings']`, `['design']`, `['campaign']` and `['reward']`, and sets `window.yayLoyaltyMeta.loyaltyMeta.setupCompleted = true`.
  - `type SetupForm`, the wizard's form values:
    - `pointsLabelSingular`, `pointsLabelPlural`
    - `earnPoints`, `earnSpend`
    - `signupEnabled`, `signupPoints`, `reviewEnabled`, `reviewPoints`
    - `worthPoints`, `worthAmount`
    - `minPoints`, `maxPercent`
    - `brandColor`
  - `toSetupForm( setup: Setup ): SetupForm`:
    - `earnSpend` and `earnPoints` come from `spendPairFromRate( purchase.points_per_unit )`.
    - `worthAmount = 1` and `worthPoints = points_per_currency_unit`.
    - `maxPercent = max_percent_of_subtotal ?? 100`.
  - `toSetupPayload( form: SetupForm ): Setup`:
    - `points_per_unit = rateFromSpend( earnSpend, earnPoints )`.
    - `points_per_currency_unit = rateFromSpend( worthAmount, worthPoints )`.
    - `max_percent_of_subtotal = maxPercent >= 100 ? null : maxPercent`.
  - `type WizardStep = 1 | 2 | 3 | 4 | 5`.
  - The step component contract: `( { form }: { form: UseFormReturn<SetupForm> } ) => JSX.Element`.

- [ ] **Step 1: Add the schema, API and queries.** Follow `settings.api.ts` and `settings.queries.ts` for the shape. The zod schema file opens with "Mirrors SetupService::current(), the source of truth."

- [ ] **Step 2: Implement `setup-wizard.form.ts`.** It holds `SetupForm`, `toSetupForm` and `toSetupPayload` as listed in Interfaces. There is no zod resolver: the server validates, per the spec.

- [ ] **Step 3: Implement `SetupWizardModal.tsx`** with props `{ open: boolean; onClose: () => void }`.
  - Data and form:
    - It calls `useSetupQuery()` and renders nothing until data arrives.
    - It runs `useForm<SetupForm>( { values: toSetupForm( data ) } )`.
  - State:
    - `step: WizardStep`
    - `returnToReview: boolean`
  - Layout:
    - `Dialog` / `DialogContent` with max width about 900px, no close X, and outside clicks ignored.
    - Header: "YayLoyalty setup", the store name (`window.yayLoyaltyMeta.wpMeta` site title if present, otherwise omitted), "Step {n} of 5", and a link-styled "Exit setup" button that calls `onClose`.
    - A five-segment progress bar: segments up to `step` are filled with the primary colour.
  - Footer:
    - "‹ Back" is disabled on step 1.
    - Continue: if `returnToReview` is set, it goes to 5 and clears `returnToReview`; otherwise it goes to `step + 1`.
    - On step 5 the primary button is "Turn on my program" (wired in Task 5).
  - Step switch: 1 → `NamePointsStep`. Steps 2–5 render a placeholder until Tasks 3–5 replace it.
  - It exposes `goToStep( n: WizardStep )` to the review step, which sets `returnToReview = true`.

- [ ] **Step 4: Implement `NamePointsStep.tsx`** following artboard 1a.
  - Title "What should we call your points?" with the subtitle from the design.
  - Two inputs: "One of them" (`pointsLabelSingular`) and "More than one" (`pointsLabelPlural`), each with `maxLength={POINTS_LABEL_MAX_LENGTH}` from `pages/settings/general-settings.form.ts`, and the hint "Both forms, so nothing reads “1 points”."
  - A preview box reading `You have 850 {plural}`, built with `sprintf` and `formatPoints( 850 )`.

- [ ] **Step 5: Mount it in `AppLayout.tsx`.**
  - `const [showSetup, setShowSetup] = useState( () => ! window.yayLoyaltyMeta.loyaltyMeta.setupCompleted && can( CAP.MANAGE_SETTINGS ) );`
  - Render `<SetupWizardModal open={showSetup} onClose={() => setShowSetup( false )} />` beside the `<Toaster />`.

- [ ] **Step 6: Verify in the admin**

Run: `wp option delete yayloyalty_setup_completed`, then `./scripts/run.sh dev`.
Open: wp-admin → YayCommerce → YayLoyalty, as an administrator.
Expected:
- The modal shows "Step 1 of 5" with one progress segment filled, and the fields show Point and Points.
- Typing "Star" and "Stars" changes the preview to "You have 850 Stars".
- "Exit setup" closes the modal, and a reload reopens it.
- As a shop_manager without `yayloyalty_manage_settings` (or after removing the capability), no modal appears.

- [ ] **Step 7: Type-check and test**

Run: `node node_modules/typescript/bin/tsc --noEmit` from `apps/admin`, then `pnpm --filter @yayloyalty/admin-page test`.
Expected: tsc shows only the known TS2307 errors (`@emoji-mart/*`, `@radix-ui/react-progress`). Vitest passes.

- [ ] **Step 8: Commit**

Stage: the eight files above.
Message: `feat(setup): add wizard modal shell, data layer and step 1`

### Task 3: Steps 2 and 3 (earn and spend)

**Files:**
- Create: `apps/admin/src/components/setup-wizard/EarnPointsStep.tsx`
- Create: `apps/admin/src/components/setup-wizard/SpendPointsStep.tsx`
- Modify: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx` (step switch)

**Interfaces:**
- Consumes: the step component contract and `SetupForm` from Task 2.

- [ ] **Step 1: Implement `EarnPointsStep.tsx`** (artboard 1b).
  - Title "How do customers earn points?"
  - "Points for spending money": `PointsInput` (`earnPoints`), "for every", `MoneyInput` (`earnSpend`) with the currency code from `storeCurrency()`, then "spent".
  - Hint: "Calculated on the order subtotal, before shipping and tax."
  - Preview: `A {parseWPCurrency(50)} order earns {formatPoints( Math.floor( 50 * earnPoints / earnSpend ) )} points`.
  - "Two more ways to earn, suggested": two rows, each with a `Switch` and a `PointsInput`:
    - `signupEnabled` / `signupPoints`, labelled "Points for creating an account".
    - `reviewEnabled` / `reviewPoints`, labelled "Points for writing a product review", with the hint "Verified purchases only…".
  - Note box: "These three become your campaigns. From tomorrow you edit them on the Campaigns screen, not here."

- [ ] **Step 2: Implement `SpendPointsStep.tsx`** (artboard 1c).
  - Title "How do customers spend their points?"
  - "What are points worth?": `PointsInput` (`worthPoints`) = `MoneyInput` (`worthAmount`), followed by "off an order".
  - Hint: "At the rate in step 2, a customer earns back {pct}% of what they spend", where `pct = earnRate / ( worthPoints / worthAmount ) * 100`.
  - Preview: `{5 × worthPoints / worthAmount} points = {parseWPCurrency(5)} off`.
  - Two columns:
    - "Smallest redemption": `PointsInput` (`minPoints`) followed by `= {parseWPCurrency( minPoints * worthAmount / worthPoints )}`.
    - "Most of an order points can cover": `NumberInput` (`maxPercent`, 1–100) followed by "% of the subtotal".
  - The hints come from the design.

- [ ] **Step 3: Wire both** into the step switch (2 → Earn, 3 → Spend).

- [ ] **Step 4: Verify in the admin** (same setup as Task 2 Step 6).
Action: Continue to step 2 and set 3 points for every 2.00. Then go to step 3 and set 500 points = 5.00.
Expected:
- Step 2's preview reads "A $50.00 order earns 75 points", and turning a switch off greys out its points input.
- Step 3's preview reads "500 points = $5.00 off", and the hint reads "…earns back 1.5%…".
- Back returns to step 2 with the earlier values kept.

- [ ] **Step 5: Type-check** as in Task 2 Step 7. Expected: no new errors.

- [ ] **Step 6: Commit**

Stage: the three files above.
Message: `feat(setup): add earn and spend wizard steps`

### Task 4: Step 4 (brand colour and storefront preview)

**Files:**
- Create: `apps/admin/src/components/setup-wizard/LauncherLookStep.tsx`
- Create: `apps/admin/src/components/setup-wizard/LauncherPreview.tsx`
- Modify: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx` (step switch)

**Interfaces:**
- Consumes: the step component contract and `SetupForm` from Task 2.
- Produces: `LauncherPreview( { color, pluralLabel, balance: 850, earnPoints, earnSpend, worthPoints, worthAmount, minPoints, device: 'desktop' | 'mobile' } )`, a static simplified storefront.

- [ ] **Step 1: Implement `LauncherLookStep.tsx`** (artboard 1d).
  - Title "Make it look like your store".
  - Left column (320px):
    - "Your brand colour" with four swatches (`#1f6fb2`, `#2a2a2a`, `#6a6a6a`, `#c8c8c8`) and a hex input, both editing `brandColor` through `ColorPicker` from `components/ui/color-picker.tsx`.
    - The hint from the design.
    - The "Later, in Settings → Design" note box.
  - Right column: "On your storefront", a Desktop/Mobile toggle (local state), then `LauncherPreview`, then the caption "A simplified page, not your theme…".

- [ ] **Step 2: Implement `LauncherPreview.tsx`.**
  - Grey placeholder blocks for the page, at full width on desktop and 375px wide on mobile.
  - The open panel, with a header in `color`:
    - "You have 850 {plural}", "worth {parseWPCurrency( 850 * worthAmount / worthPoints )}"
    - "Earn {earnPoints} {label} for every {parseWPCurrency(earnSpend)} you spend"
    - A static slider with "{minPoints} minimum" and "850 maximum"
    - An "Apply points" button in `color`
  - The closed launcher button in `color`.

- [ ] **Step 3: Wire it** into the step switch (4 → `LauncherLookStep`).

- [ ] **Step 4: Verify in the admin.**
Action: on step 4, click the `#2a2a2a` swatch, then type `ff0000` into the hex input, then toggle Mobile.
Expected:
- The panel header, the Apply button and the launcher button turn #2a2a2a, then #ff0000.
- The panel shows the label and rates from steps 1–3.
- Mobile narrows the preview page.

- [ ] **Step 5: Type-check** as in Task 2 Step 7. Expected: no new errors.

- [ ] **Step 6: Commit**

Stage: the three files above.
Message: `feat(setup): add brand colour step with storefront preview`

### Task 5: Step 5 (review, Edit links and Turn on)

**Files:**
- Create: `apps/admin/src/components/setup-wizard/ReviewProgramStep.tsx`
- Modify: `apps/admin/src/components/setup-wizard/SetupWizardModal.tsx` (step switch and submit)

**Interfaces:**
- Consumes: `goToStep( n: WizardStep )`, `toSetupPayload` and `useCompleteSetupMutation()` from Task 2.
- Produces: `ReviewProgramStep( { form, onEdit }: { form: UseFormReturn<SetupForm>; onEdit: ( step: WizardStep ) => void } )`.

- [ ] **Step 1: Implement `ReviewProgramStep.tsx`** (artboard 1e).
  - Title "Ready to turn it on?"
  - A "Your program" card with seven rows. Each row has a label, a value and an `Edit` link that calls `onEdit`:

    | Label | Value | Edit goes to |
    |---|---|---|
    | Points are called | `{singular} · {plural}` | 1 |
    | Customers earn | `{earnPoints} point per {currency(earnSpend)} spent` | 2 |
    | Account signup | `{signupPoints} points, once per customer`, or "Off" | 2 |
    | Product review | `{reviewPoints} points, verified purchases only`, or "Off" | 2 |
    | Points are worth | `{worthPoints} points = {currency(worthAmount)} off` | 3 |
    | Redemption limits | `from {minPoints} points, up to {maxPercent}% of the subtotal` | 3 |
    | Launcher | `brand colour {brandColor uppercased}` | 4 |

- [ ] **Step 2: Wire the submit** in `SetupWizardModal`.
  - Step 5 renders `ReviewProgramStep` with `onEdit={goToStep}`.
  - The primary button is "Turn on my program", with the muted text "Under 30 minutes from activation" to its left. On click it runs `mutation.mutate( toSetupPayload( form.getValues() ) )` and shows the loading state while pending.
  - On success: `toast.success( __( 'Your loyalty program is on.', 'yayloyalty' ) )`, then `onClose()`.
  - On error: `toastApiError( error )`, and stay on step 5.

- [ ] **Step 3: Verify the Edit links** (Review focus: return to review).
Action: on step 5, click the Edit on "Customers earn".
Expected: step 2 opens. Clicking Continue lands on step 5, not step 3. Repeat for the rows that go to 1, 3 and 4: each opens its step, and Continue returns to step 5.

- [ ] **Step 4: End-to-end verification** (the spec's acceptance criterion 7, the plan's exit criterion).
Run: `wp option delete yayloyalty_setup_completed` and reload wp-admin → YayLoyalty.
Action:
1. Step 2: 2 points for every 1.00, signup off, review 75.
2. Step 3: 200 points = 1.00, smallest redemption 200, most 50%.
3. Step 4: colour `ff0000`.
4. Step 5: "Turn on my program".

Expected:
- The modal closes with the success toast, and `wp option get yayloyalty_setup_completed` prints `1`.
- Campaigns shows the purchase rate of 2 points per $1, Signup paused, and Review active at 75.
- Rewards shows the cart discount at 200 points per $1, min 200, max 50%.
- Settings → Design shows the launcher colour `#ff0000`.
- A reload shows no modal.

- [ ] **Step 5: Type-check and test** as in Task 2 Step 7, then run `composer test:integration -- --filter SetupRouteTest`.
Expected: no new tsc errors, Vitest passes, and SetupRouteTest passes 3 tests.

- [ ] **Step 6: Commit**

Stage: the two files above.
Message: `feat(setup): add review step and turn-on submit`
