# Setup Wizard Design

**Status:** accepted 2026-10-09
**Related:** design file `YayLoyalty Setup Wizard.dc.html` (F16, artboards 1a–1e); pattern from `yay-reviews` onboarding (`OnboardingModal.tsx`, `POST /onboarding/complete`, option `yayrev_onboarding_complete`)

## Goal

A merchant who opens YayLoyalty for the first time sets up the program in five steps in a modal over the admin app: name the points, choose how customers earn, choose how they spend, pick a brand colour, then review. Nothing is saved until "Turn on my program", which writes every choice in one request.

## Context

- There is no wizard or onboarding code today.
- **Labels:** `points_label_singular` and `points_label_plural` are stored in option `yayloyalty_settings` (`includes/Helpers/SettingsHelper.php`). `settings.enabled` defaults to `true`.
- **Brand colour:** `launcher.brand_color` in `yayloyalty_design` (`includes/Helpers/DesignHelper.php`, `DesignSchema.php`).
- **Earning:** the installer seeds the built-in campaigns `default-purchase`, `signup` and `review` (`Installer::seed_builtin_campaigns()`), all `active`.
  - `purchase` config: `points_per_unit`
  - `signup` config: `points`
  - `review` config: `points`, `require_verified` (default `true`)
  - Code: `includes/Repositories/CampaignRepository.php` (`find_by_slug`, `update`) and `includes/Models/Campaign.php` (`validate_config`).
- **Spending:** the built-in reward with slug `cart-discount` (`RewardRepository::CART_DISCOUNT_SLUG`, type `flexible_discount`). Its config holds `points_per_currency_unit`, `min_points` and `max_percent_of_subtotal` (null means 100%). Validation is `Reward::validate_config`.
- **Flags passed to the SPA:** `LocalizeHelper::get_meta()` sends `loyaltyMeta.reviewed` from `SettingsHelper::OPTION_REVIEWED`. The setup flag follows the same pattern.
- **REST:** `AbstractRestController`, registered in `includes/Engine/RestAPI.php::controllers()`, with `requires( Capabilities::X )`. Writes go through `exec_write`, which runs in a transaction.
- **SPA:**
  - Data: ky `api` (`apps/admin/src/lib/api/api.ts`), TanStack Query, react-hook-form with zod, and `Dialog` (`components/ui/dialog.tsx`).
  - Inputs: `PointsInput` and `MoneyInput` (`components/ui/custom/amount-inputs.tsx`), `ColorPicker` (`components/ui/color-picker.tsx`).
  - Rate helpers: `rateFromSpend` and `spendPairFromRate` (`lib/helpers/points.helper.ts`).
  - The app shell is `AppLayout.tsx`.

## Scope

**In:**
- A new option and REST endpoints `GET` and `POST /yayloyalty/v1/setup`, and `POST /yayloyalty/v1/setup/dismiss`.
- A five-step wizard modal in the SPA, matching the design: header with "Step N of 5" and "Exit setup", a five-segment progress bar, Back and Continue buttons, and the live preview line on each step.
- Step 4's storefront preview, with the Desktop/Mobile toggle changing the preview width.
- Step 5's review card with an Edit link per row. Edit opens that step. While editing from review, Continue on that step returns to step 5.
- "Turn on my program" posts everything, closes the modal and refreshes the settings, design, campaigns and rewards data.

**Out:**
- Edge cases (per the request): validation beyond the existing server validators, a failed partial save, concurrent edits, other users.
- Uninstall cleanup of the new option.
- Activation redirect from PHP.
- A re-run entry point after completion.
- Any change to the Campaigns, Rewards or Settings screens.
- Pro-only steps. Lite and Pro get the same five steps.
- `daily_cap`, which the active plan `2026-10-08-launcher-advert-review-fixes` removes.

## Acceptance criteria

1. Given a fresh install (`yayloyalty_setup_completed` absent), when a user with `yayloyalty_manage_settings` opens the YayLoyalty admin page, the wizard modal opens on step 1, prefilled from `GET /setup`: Point, Points, 1 point per 1.00, signup on at 100, review on at 50, 100 points = 1.00, min 100, max 100%, `#1f6fb2`.
2. A user without `yayloyalty_manage_settings` never sees the modal.
3. Each step's preview line updates as fields change. Step 1 shows "You have 850 Points". Step 2 shows "A $50 order earns 50 points". Step 3 shows "500 points = $5 off".
4. "Exit setup" saves none of the answers. It sends `POST /setup/dismiss`, which only sets `yayloyalty_setup_completed`, then closes the modal, and reloading does not reopen it. Changed 2026-10-09 at the user's request: it first closed without saving or marking setup done, then saved the answers so far.
5. On step 5, each Edit link opens its step: labels → 1; earn, signup, review → 2; worth, limits → 3; launcher → 4. Continue on that step then returns to step 5.
6. "Turn on my program" sends one `POST /yayloyalty/v1/setup`. Afterwards:
   - `settings.points_label_*` and `enabled = true` are saved.
   - `design.launcher.brand_color` is saved.
   - `default-purchase.config.points_per_unit` is set to points ÷ spend.
   - The `signup` and `review` campaigns get their `points`. Each one's status is `active` if its toggle is on, otherwise `paused`.
   - `cart-discount.config` gets `points_per_currency_unit` = points ÷ currency, `min_points`, and `max_percent_of_subtotal` (null when 100).
   - Option `yayloyalty_setup_completed` = `true`.
   - The modal closes and does not reopen on reload.
7. End to end: on a site with the option deleted (`wp option delete yayloyalty_setup_completed`), open wp-admin → YayLoyalty. Set 2 points per $1, signup off, review 75, 200 points = $1, min 200, max 50%, colour `#ff0000`, then Turn on. Expected: Campaigns shows the purchase rate of 2 points per $1, Signup paused, Review active at 75. Rewards shows the cart discount at 200 points per $1, min 200, max 50%. Settings → Design shows the launcher colour `#ff0000`. Reloading shows no modal.

## Constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted properties, `?->`, named arguments or union types.
- Wire data is snake_case. Mapping to camelCase happens only in `*.api.ts`. Rates are JSON numbers; points are integers.
- "No limit" is `null`, never 0, so 100% is sent as `max_percent_of_subtotal: null`.
- Writes go controller → service → repository. A repository never opens a transaction.
- Every TypeScript copy of a PHP shape opens with "Mirrors <PHP symbol>, the source of truth."
- Files stay under 200 lines. Components are PascalCase `.tsx`; other modules are `<owner>.<role>.ts`.
- No Pro code. The wizard is the same in Lite and Pro.
- Number formatting uses `formatStoreNumber` / `formatPoints`, never `toLocaleString()`.

## Approach

**Chosen:** a modal over the app plus one aggregate setup endpoint (the yay-reviews pattern).

**Architecture:** `AppLayout` reads `window.yayLoyaltyMeta.loyaltyMeta.setupCompleted`. If it is false and the user can `MANAGE_SETTINGS`, it mounts `<SetupWizardModal>`. The modal fetches `GET /setup` and holds all five steps in one react-hook-form. The steps only change which fields render. "Turn on" posts the whole form to `POST /setup`. `SetupService` applies each write inside the `exec_write` transaction and sets the option.

**Components:**
- **PHP**
  - `includes/Controllers/SetupRestController.php`: `GET /setup` (`VIEW`), `POST /setup` and `POST /setup/dismiss` (`MANAGE_SETTINGS`). Registered in `RestAPI::controllers()`.
  - `includes/Services/SetupService.php`:
    - `current(): array` reads settings, design, the three campaigns by slug, and `cart-discount`.
    - `complete( array $input )` validates through `Campaign::validate_config` and `Reward::validate_config`, merges into the existing config, updates the rows, calls `SettingsHelper::update_settings` and `DesignHelper::update_design`, then sets the option.
  - `SettingsHelper::OPTION_SETUP_COMPLETED = 'yayloyalty_setup_completed'`.
  - `LocalizeHelper::get_meta()` adds `loyaltyMeta.setupCompleted`.
- **SPA** (`apps/admin/src/`)
  - `lib/schema/setup.schema.ts`: zod copy of the payload.
  - `lib/api/setup.api.ts`: `getSetup`, `completeSetup`, `dismissSetup`.
  - `lib/queries/setup.queries.ts`: `useSetupQuery`, `useCompleteSetupMutation`, which refreshes the settings, design, campaigns and rewards data, and `useDismissSetupMutation`.
  - `components/setup-wizard/SetupWizardModal.tsx`: the shell (header, progress, Back/Continue, step switch, the `returnToReview` state).
  - `components/setup-wizard/setup-wizard.form.ts`: the form type, mapping from GET, and building the POST body using `rateFromSpend`.
  - Step components in `components/setup-wizard/`:
    - `NamePointsStep.tsx`
    - `EarnPointsStep.tsx`
    - `SpendPointsStep.tsx`
    - `LauncherLookStep.tsx` with `LauncherPreview.tsx`
    - `ReviewProgramStep.tsx`
  - `AppLayout.tsx`: mounts the modal.
  - `types/localize.d.ts`: adds `setupCompleted`.

**Data and storage:** the new option `yayloyalty_setup_completed` (bool, absent means false). Everything else uses existing storage, as listed in Context. No migration is needed: existing installs have no option, so they see the wizard once.

**Integration points:** `GET` and `POST /yayloyalty/v1/setup`. Both bodies use this shape:

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

`POST` returns the same shape after the save.

**UI:** a large modal (`Dialog`, max width about 900px) over the app. The copy follows the design artboards 1a–1e. The step 4 preview is a static simplified storefront, drawn with the chosen colour, labels and rates.

**Edge cases and failure modes:** out of scope per the request. A 400 from the validators is shown with `toastApiError`, and the modal stays open on step 5.

**Decision record:** None. It follows the existing controller, service and repository layering and the existing localized-flag pattern.

## Alternatives considered

| Option | Why rejected |
|---|---|
| Flag as a key in `yayloyalty_settings` | The user chose a separate option, like yay-reviews |
| Full-page `#/setup` route with an AppLayout redirect | The user chose a modal, like yay-reviews |
| Reuse the existing settings, design, campaign and reward endpoints (5–6 calls) | Not atomic, and the SPA would have to orchestrate them; one `POST /setup` is simpler |
| Leave toggled-off campaigns untouched | A switched-off suggestion would still be live; `paused` matches what the merchant chose |

## Rollout

Existing installs see the wizard once on their next admin visit, because the option is absent. It is prefilled with their current values, so "Turn on" changes nothing they didn't change. Release note: "New five-step setup wizard."

## Open questions

None.
