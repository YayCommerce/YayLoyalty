# Share Duplicated Logic Plan

**Status:** active
**Branches:** `trace/refactor/share-core-php-logic`, `trace/refactor/share-rest-logic`, `trace/refactor/share-spa-logic`, each from `dev/edd`; one PR each into `dev/edd`
**Goal:** where two places implement the same logic, one calls the other or both call one shared function.
**Spec:** none, planned from the request.
**Tests:** `tests/unit/Engine/Rewards/ClampTest.php` (unit) and a `pointsForSpend` case in `apps/admin/tests/points-math.test.ts` (Vitest), both accepted.

## Context

The user asked for a cleanup: wherever two places implement the same logic, make one use the other, or move both into one shared function. Three read-only sweeps (core PHP, REST/Pro PHP, admin SPA) found about 56 duplicates. Agreed with the user:
- **Scope:** high-value items only. Low and medium items are left as they are.
- **Copies that differ:** merge them onto the canonical helper. Each change in behaviour gets its own commit and is named in the PR description.
- **Delivery:** 3 PRs into `dev/edd`, by area.
- **Tests:** add the two accepted points-math tests (PR 1, PR 3).

Constraints that apply throughout:
- PHP 7.4 syntax only.
- Lite never names Pro. Shared code for a Lite/Pro duplicate goes in Lite, and SPA shared code goes outside `src/pro/`.
- Only `LedgerService` writes ledger rows; the sweep confirmed nothing else does today.
- No hand edits to generated output.
- Minimal comments: one-line docblocks, plus a "why" only where the code would look wrong without it.
- Commits use Conventional Commits. The user publishes branches through GitHub Desktop, so commit only and unset the `dev/edd` upstream on each new branch.

---

## PR 1: core PHP (`<name>/refactor/share-core-php-logic`)

1. **Enum validation trait.** Add `includes/Enums/StringEnumMethods.php`, modelled on the existing `includes/Enums/ConditionTypeMethods.php`.
   - It provides `is_valid()` and `assert()`, plus an abstract static `noun()` that supplies the noun for the error message.
   - Use it in `Status`, `LedgerType`, `LedgerSource`, `RewardType` and `CampaignTrigger`.
   - `ReferenceType` keeps its own nullable branch and calls the trait for non-null values.
   - Keep the exact message wording, including `Status`'s extra sentence (pass it as a suffix).

2. **Whole-number and limit checks.** Reuse `ConditionValueRules::is_int_like()` (`includes/Utils/ConditionValueRules.php:111`):
   - in `Campaign.php:320` and `:353`
   - in `Reward.php:332`
   - in `SettingsHelper::is_limit()` (`:198`), which becomes `is_int_like( $v ) && (float) $v >= 1`.

   Add `ConditionValueRules::is_limit()` for the rule "null, or a whole number ≥ 1". `SettingsHelper` and Campaign's `limit` case (`:338`) both call it.

3. **Config spec check.** Add `includes/Utils/ConfigSpec.php` with `matches( $value, array $spec ): bool` and `within_bounds()`.
   - It covers the union of both spec types:
     - from Campaign: `int_list` and `limit`
     - from Reward: `max`, `enum_list`, `currency`, `product`, `nullable_int` and `nullable_number`
   - `Campaign::value_matches_spec` (`:308`) and `Reward::value_matches_spec` (`:296`) / `is_within_bounds` (`:359`) are deleted and both call `ConfigSpec`.
   - **Behaviour change:** Campaign `int` specs now also honour `max` and `exclusive_min` if they declare them. No Campaign spec declares them today, so nothing changes in practice. Confirm this with a grep of `CAMPAIGN_CONFIG_FIELDS` / the spec arrays.

4. **Currency and country codes.** Move the identical currency check (`CampaignConditionSet.php:330`, `Reward.php:387`) and `is_country_code` (`:343`) into `ConditionValueRules::is_currency_code()` and `is_country_code()`.

5. **Condition-set tidy, without a full merge.** The class docblock says the two condition sets are kept apart on purpose, so they are not merged.
   - `RewardConditionSet::bad()` (`:202`) delegates to `ConditionValueRules::bad()`.
   - Drop `CampaignConditionSet`'s three pass-through wrappers (`:317–325`) and call `ConditionValueRules` directly.
   - **Bug fix (own commit):** the `currency_list` max error (`:252–258`) wrongly says "ids … use a user_role condition". Give it currency wording.

6. **Repository helpers on `AbstractRepository`:**
   - `find_one_by( $column, $value, $placeholder )` replaces `find_by_slug` in `CampaignRepository:47` and `RewardRepository:34`. The public methods stay and become one-line callers.
   - `seed_builtin_by_slug()` replaces both `seed_builtin` (`CampaignRepository:259`, `RewardRepository:157`).
   - `bulk_set_int_column( $column, array $id_to_value )` builds the `CASE id WHEN` update for both `reorder()`s (`CampaignRepository:173–191`, `RewardRepository:83–100`).
   - **Bug fix (own commit):** `bulk_set_int_column` checks `false === $result` and throws `QueryException`, as the other repository writes do. Today a failed reorder returns `true`.

7. **Clamp rounding.** In `Engine/Rewards/Clamp.php:90`, `points_in` becomes `max( 0, PointsMath::round_points( $amount * $rate, PointsMath::ROUND_DOWN ) )`.
   - **Behaviour change:** the snap goes from 6 to 9 decimals.
   - **Test (accepted):** add `tests/unit/Engine/Rewards/ClampTest.php`, which calls `Clamp::apply` with a flexible reward. It asserts the cart-points cap at 0.29 × 100 = 29 and 0.57 × 100 = 57, and that the max-percent-of-subtotal cap holds.
   - Write the test first against the current code, so it proves the results don't change.

8. **Listener guard.** The four `try/catch → Log::error` blocks in `Engine/CustomerActivityListener.php` (`:34`, `:78`, `:102`, `:126`) use `ListenerGuard::run()` (`Engine/Earning/Listeners/ListenerGuard.php:18`). Log lines gain ListenerGuard's "X failed" prefix.

9. **Email balance values.** Add a protected `balance_values( LedgerEntry $entry ): array` on `Engine/Emails/LedgerEntryEmail.php`, building the 7 shared keys.
   - `PointsEarnedEmail::build_values` (`:67`) and `PointsAdjustedEmail::build_values` (`:63`) merge their own keys over it.
   - Template variable names are unchanged.

## PR 2: REST controllers and Pro PHP (`<name>/refactor/share-rest-logic`)

1. **Deleted member's identity.** Add `CustomerMetaRepository::deleted_identity( int $user_id ): array` (`['name' => …, 'email' => …]`, or `''`), reading the latest `ACCOUNT_DELETED` entry.
   - Use it in `CustomerListFormatter.php:117–131`, `CustomerItemFormatter.php:49–61` and `Pro/Repositories/CampaignPerformanceRepository.php:160–164`. Remove the "copies" comment there.
   - Each caller keeps its own rule for when to fall back.
   - The list formatter batches rows, so check it doesn't turn into N+1 queries. If it would, add a batched `deleted_identities( array $ids )` instead.

2. **Reorder endpoint.** On `AbstractRestController`:
   - a `MAX_REORDER_IDS` constant
   - `reorder_args()`
   - `handle_reorder( $request, callable $reorder, string $dup_message )`

   `CampaignRestController` (`:24`, `:76–94`, `:160–172`) and `RewardRestController` (`:25`, `:82–102`, `:232–244`) use them. Their message strings are kept.

3. **Sort keys.** Make `CustomerRowSort::KEYS` and `DEFAULT_COLUMN` public. `Schemas/CustomerRouteArgs.php:20–21` reads them.

## PR 3: admin SPA (`<name>/refactor/share-spa-logic`)

1. **Campaign config types and fields.**
   - **Types:** add `PurchaseConfig`, `SignupConfig` and `ReviewConfig` (the Pro superset, with optional keys) to `lib/schema/campaign.schema.ts`, beside `CAMPAIGN_CONFIG_FIELDS`. The rounding field uses `RoundingMode`.
   - **Fields:** extract the shared Award, Base amount and Rounding controls and the minimum-subtotal input into `pages/campaigns/` field components, following the `pages/rewards/FlexibleDiscountFields.tsx` pattern.
   - **Users:** `BuiltInCampaignList.tsx:161–340` and `pro/campaigns/editor/CampaignEditorRewardPurchase.tsx` / `CampaignEditorReward.tsx` use them. Pro passes its extra fields as children or props.

2. **Debounced search.** `pro/campaigns/performance/CampaignPerformanceEntries.tsx:97–121` uses `hooks/useDebouncedSearchCommit`, and its `onCommit` resets the page to 1.

3. **`toastApiError`.** Replace the inline `toast.error(await getApiErrorMessage(...))` calls with `toastApiError` (`lib/api/api.ts:166`). Sites:
   - `customers-saved-segment-row.state.ts:90`
   - `CustomersSaveSegmentDialog.tsx:45`
   - `CampaignArchiveDialog.tsx:250`
   - `customers-page.bulk-actions.ts:129`
   - `CustomerDrawerActions.tsx:50`
   - `EmailsSettingsPage.tsx:69–71`

   **Behaviour change:** a 403 refusal no longer toasts twice, and the Emails page now shows a 403 that carries its own message.

4. **Settings save flow.** Add `hooks/useSettingsFormSave` on top of `useRouteLeaveGuard`, sharing the `leaveDialogProps` shape with `useEditorSave`. It covers `useForm` with keep-dirty, the save → reset → confirmLeave → toast sequence, and the unsaved-changes dialog props. Use it in `GeneralSettingsPage.tsx:46–92`, `EmailsSettingsPage.tsx:51–129` and `DesignSettingsPage.tsx:46–127`.

5. **Not-found and load states.**
   - Add `isNotFoundError` to `lib/api/api.ts`. It replaces the four inline `HTTPError && status === 404` checks (`RewardEditor.tsx:78`, `CampaignEditor.tsx:57` and `:217`, `CampaignPerformance.tsx:30`).
   - Generalise `resolveRewardLoad` (`RewardEditor.tsx:66–92`) into a non-Pro `components/resolve-query-load.tsx`, used by the reward editor, the campaign editor and campaign performance.

6. **`ErrorState`.** Replace the plain "Could not load …" paragraphs with `components/ui/custom/error-state.tsx`, with retry wired to the query's `refetch`, and remove its `TODO(error-state)`. Sites:
   - `BuiltInCampaignList.tsx:42`
   - `BuiltInRewardEditor.tsx:31`
   - `RewardTable.tsx:114`
   - `CampaignTable.tsx:431`
   - `ConditionsPreview.tsx:62`
   - the custom card in `EmailsSettingsPage.tsx:33–43`

7. **Archive dialog shell.** Extract the shared pieces of `CampaignArchiveDialog` and `RewardArchiveDialog` into a Pro-internal `pro/shared/ArchiveOrDeleteDialog.tsx`: `archivedLink`, `run(action)` with pending/busy, and the dialog layout. Each dialog passes its extra action ("Pause instead" or "Archive instead") and its copy. `reward-archive.copy.test.ts` must still pass.

8. **Dead duplicate UI files.** Delete `components/ui/custom/empty.tsx`, `components/ui/avatar.tsx` and `components/ui/custom/avatar.tsx`. Grep first to confirm they have no importers.
   - The two dialog primitives are **not** merged: their z-index stacking differs and needs its own check. Add a backlog item instead with `/yaybacklog`.

9. **Stored UTC "now".** Add `dateToStored(date)` to `lib/helpers/format.helper.ts` as the inverse of `utcToDate`. Use it in `lib/schema/campaign.schema.ts:61`, `pro/campaigns/editor/campaign-editor.form.ts:93` and `components/ui/custom/schedule-fields.tsx:139–160`.

10. **Percent format (behaviour change: store separators).** Add `formatPercent(value, maxDecimals)` to `points.helper.ts`, built on `formatStoreDecimal`.
    - Use it in `dashboard-page.copy.ts:43` and `:135`, and in `DashboardStatsGrid.tsx:74`.
    - In `CustomerDrawerExpiryPanel.tsx:23`, combine it with `pctOf`.

11. **Wizard points example (behaviour change).** `setup-wizard.form.ts` `pointsForSpend` uses `roundPoints(amount * earnPoints / earnSpend, ROUNDING.DOWN)` from `lib/points-math`.
    - **Test (accepted):** in `apps/admin/tests/points-math.test.ts`, check that `pointsForSpend` returns 57 for 0.57 spend at 100 points per 1, and 0 when `earnSpend` is 0.

12. **Money decimals (behaviour change).** Change `MoneyInput` / `OptionalMoneyInput` in `components/ui/custom/amount-inputs.tsx` from `decimalScale={2}` to `currencyDecimals()`.

## Verification

**PR 1**
- Run `composer test`; the new `ClampTest` and the existing `PointsMathTest` must pass.
- Run `composer test:integration` and confirm from the output that tests actually ran (exit 0 alone doesn't prove it).
- Run `composer lint` and compare against `dev/edd`: no new violations.
- Grep `includes/` for `?->`, `match (`, `readonly`, `enum ` and union types in the changed files.
- In wp-admin:
  - Save a campaign and a reward; both succeed.
  - Save an invalid config (a limit of 0); the response is 400.
  - Drag-reorder campaigns and rewards; the new order survives a reload.
- In WooCommerce emails: preview "Points earned" and "Points adjusted"; the balance lines render.

**PR 2**
- Run `composer test` and `composer test:integration`.
- Reorder campaigns and rewards in the SPA.
- Send `POST …/reorder` with duplicate ids; the response is 400 with the original message.
- Check a deleted customer:
  - In the customer list and drawer, the stored name and email show.
  - In Pro campaign performance, the stored name shows.
- In the customer list, sort by each column; an unknown sort key is rejected.
- Build a Lite zip (`./scripts/run.sh release lite --no-prompt` after Pro, or the validator) and confirm `validate-build.php` reports no Pro references. Run `composer install` afterwards.

**PR 3**
- Run `pnpm --filter @yayloyalty/admin-page test`; the new `pointsForSpend` case must pass.
- Run tsc and eslint, and compare with `dev/edd`: no new errors.
- Use a scratch build if pnpm's auto-install gets in the way (see the scratch-SPA memory).
- Browser checks with `./scripts/run.sh dev` on :3030:
  - **Settings pages (General, Emails, Design):** edit, then navigate away; the unsaved-changes dialog appears. Saving clears it.
  - **Bad URLs:** opening `#/campaigns/999999` and a missing reward shows the 404 view.
  - **Archive and delete:** the campaign and reward dialogs work end to end.
  - **Load errors:** with the network blocked, `ErrorState` appears and Retry refetches.
  - **Separators:** with a store using `,` decimals and `.` thousands, dashboard percentages follow them.
  - **Money inputs:** with a 3-decimal currency (KWD) or a 0-decimal one (VND), money inputs accept the right number of decimals.
  - **Setup wizard:** the earn example at 0.57 × 100 shows 57.
- Build Lite (`./scripts/run.sh build lite`, a known failure; use the scratch build). Confirm `pro/shared/ArchiveOrDeleteDialog` is not in the Lite bundle.
