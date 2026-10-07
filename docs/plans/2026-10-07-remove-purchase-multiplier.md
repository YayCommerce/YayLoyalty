# Remove the purchase multiplier mode Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source.

**Status:** active
**Goal:** Purchase campaigns earn only at their own rate; the "Multiply the default rate" mode and every piece of code that serves it are gone.
**Spec:** none, planned from the request
**Architecture:** The SPA drops the mode first (Task 1), so the editor never sends `mode`/`multiplier` while the server still accepts them. The server then drops the mode (Task 2) and keeps accepting the two legacy keys on input without validating or storing them, so a campaign saved before this change still saves. No migration: `set_config()` already drops keys outside the whitelist, so leftover keys disappear on a campaign's next save, and the engine never reads them.
**Touches:** `apps/admin/src/lib/schema/campaign.schema.ts` (shared: consumers are the campaign editor, `campaign-labels.ts`, `campaign-reward.copy.ts`), `apps/admin/src/lib/queries/campaign.queries.ts` (shared: `usePurchaseCampaignsQuery` stays for `pages/rewards/FlexibleDiscountFields.tsx`), the Pro campaign editor, labels, copy, templates and performance tiles, `includes/Engine/Points/Triggers/PurchaseHandler.php` (shared: consumers are `Registry`, `CampaignRestController`, `LauncherEarnRules`, `CampaignPerformanceRestController`), `includes/Engine/Points/Triggers/Registry.php`, `includes/Engine/Points/PointsEngine.php`, `includes/Models/Campaign.php`, `includes/Controllers/CampaignRestController.php`, `includes/Engine/Storefront/LauncherEarnRules.php`, `includes/Engine/Storefront/LauncherPayload.php`, `includes/Pro/Controllers/CampaignPerformanceRestController.php`, `includes/Repositories/CampaignRepository.php` (docblock only)
**Tests:** none

## Requirements

- **Goal:** A non-default purchase campaign's Reward step shows the same single Rate field the default campaign shows, and every purchase campaign earns `points_per_unit` per currency unit.
- **Out of scope:** tier multipliers, the adjust-points typo guard (`TYPO_GUARD_MULTIPLIER`), the dashboard anomaly `multiple`, the date-range picker's `mode` prop. None of these is the campaign mode. Also out of scope: `CampaignRepository::find_default_purchase()`, which stays because `DashboardNotices` uses it.
- **Acceptance criteria:**
  1. The editor for a non-default purchase campaign has no mode cards, no "Multiply the default rate" text and no request to `campaigns?trigger=purchase&per_page=100`. It shows one Rate field (`pts / <currency>`) with the description "Points for every 1 <currency> of the order amount. Advanced sets what counts.".
  2. The campaigns table "Gives" column shows `N pt(s) per <amount>` for every purchase campaign, never `×N points`.
  3. A `PUT campaigns/<id>` whose config has `"mode":"multiplier","multiplier":2` alongside a valid `points_per_unit` returns 200. Afterwards the campaign's stored config has neither key.
  4. A purchase `POST`/`PUT` with no `points_per_unit` returns 400 "A purchase campaign needs a rate.", whatever `mode` says.
  5. The campaign performance response has no `liability_above_default` key, and the "Cost in liability" tile shows no "above the default rate" line.
  6. End to end: a non-default purchase campaign at 2 pts per currency unit, with priority above the default, pays 2 × the counted amount, rounded per its setting, on a completed test order. The ledger row and the launcher's "Ways to earn" rate both show that rate.
- **Decided with the user:**
  - Remove multiplier entirely, keep own rate only.
  - Saved data: no migration. Ignore leftover `mode`/`multiplier` keys; local data is fixed by hand.
  - Tests: none. Verify by hand.

## Global constraints

- Product PHP must run on PHP 7.4: no enums, `match`, `readonly`, promoted constructor properties, `?->`, named arguments or union types.
- Lite names Pro in exactly one place. This change adds no Pro reference outside `includes/Pro/`.
- SPA Pro code stays in `apps/admin/src/pro/`, and is imported only through `apps/admin/src/pro/index.ts`.
- Only `LedgerService` writes ledger rows and balances. Nothing here touches it.
- Never hand-edit `vendor/`, `vendor-prefixed/`, `node_modules/`, `assets/dist/`, `release/`.
- Run on Node 22 (`nvm use`). Use pnpm only. Composer tooling needs PHP ≥ 8.2.
- Commits follow Conventional Commits, `type(scope): summary`, and end with `Co-Authored-By: Claude <model> <noreply@anthropic.com>`. Stage files by name. Commit only when asked.
- These checks fail repo-wide on `dev/edd` today (`docs/known-issues.md`): `composer lint`, SPA lint, `tsc --noEmit`. For each, expect no new findings in the touched files.

## Review focus

- **A campaign saved with `mode: "rate"` (most existing rows, because the old editor wrote it).** Opening it and saving must succeed, not answer 400 "unknown key". Task 2 Step 4 checks this.
- **A leftover multiplier row with no `points_per_unit`.** It now earns nothing, and the engine treats it as a 0-point match that stops lower-priority campaigns (see `lacks_purchase_rate()`'s docblock). Opening it and saving is refused until a rate is entered, which is the intended fix path. Task 2 Step 5 finds any such row on the local site.
- **The launcher advertising a rate the engine wouldn't pay.** After the change, both read `points_per_unit` through the same `PurchaseHandler::rate_of()`. Task 2 Step 6 checks the launcher and the ledger agree.

---

### Task 1: SPA: one Rate field for every purchase campaign

**Files:**
- Modify: `apps/admin/src/pro/campaigns/editor/CampaignEditorRewardPurchase.tsx`
- Modify: `apps/admin/src/pro/campaigns/editor/campaign-reward.copy.ts`
- Modify: `apps/admin/src/pro/campaigns/campaign-labels.ts`
- Modify: `apps/admin/src/lib/schema/campaign.schema.ts`
- Modify: `apps/admin/src/lib/queries/campaign.queries.ts` (docblock only)
- Modify: `apps/admin/src/lib/schema/campaign-performance.schema.ts`
- Modify: `apps/admin/src/pro/campaigns/performance/CampaignPerformanceTiles.tsx`
- Modify: `apps/admin/src/pro/campaigns/add/campaign-templates.ts`

**Interfaces:**
- Consumes: nothing.
- Produces: `lacksPurchaseRate(config: Record<string, unknown>): boolean` is `typeof config.points_per_unit !== 'number'`.
  - `resolvePurchaseMode`, `resolvePurchaseMultiplier`, `DEFAULT_PURCHASE_MODE`, `effectiveRateSentence`, `defaultPurchaseRate` and `multipliedRate` no longer exist.
  - `CAMPAIGN_CONFIG_FIELDS.purchase` has no `mode` or `multiplier`.

- [ ] **Step 1: Reduce `RewardFieldsPurchase` in `CampaignEditorRewardPurchase.tsx` to the Rate field plus Advanced, for every purchase campaign**
  - Render the existing `Field` with `PurchaseRateInput` and `PurchaseRateDescription` unconditionally.
  - Delete the `isDefaultCampaign` branch and the `usePurchaseCampaignsQuery` call.
  - Delete `isMultiplierStranded` and its `Alert`, and `PurchaseModeChoice` and `PurchaseModeCard`.
  - Delete `mode` and `multiplier` from `PurchaseConfig`, and the `tabIndex` prop from `PurchaseRateInput`.
  - Remove the imports this leaves unused: `TriangleAlert`, `Alert`, `AlertDescription`, `RadioGroup`, `RadioGroupItem`, `FieldTitle`, `cn`, `findBuiltinCampaign`, `isBuiltinCampaignActive`, `givesLabel`, `useCampaignEditorContext`, `usePurchaseCampaignsQuery`, and the mode helpers. Remove each one only if nothing else in the file uses it.
  - Rewrite the component docblock to one line: the rate, then Advanced.
  - Keep `SMALLEST_PURCHASE_FACTOR`, and reword its comment to name only the rate.

- [ ] **Step 2: Delete `effectiveRateSentence`, `defaultPurchaseRate` and `multipliedRate` from `campaign-reward.copy.ts`**
  - `effectiveRateSentence` already has no caller.
  - Drop the imports they leave unused: `Campaign`, `resolvePurchaseMode`, `resolvePurchaseMultiplier`, `givesLabel`, `isBuiltinCampaignActive`.

- [ ] **Step 3: In `campaign-labels.ts`, delete `givesLabel`'s `config.mode === 'multiplier'` branch and the `resolvePurchaseMultiplier` import**
  - Reword `isBuiltinCampaignActive`'s docblock so it no longer cites "a multiplier's default". It mirrors the engine skipping any campaign that isn't active.

- [ ] **Step 4: In `campaign.schema.ts`, delete the `mode` and `multiplier` entries of `CAMPAIGN_CONFIG_FIELDS.purchase`, `DEFAULT_PURCHASE_MODE`, `resolvePurchaseMode` and `resolvePurchaseMultiplier`, and simplify `lacksPurchaseRate` as in Interfaces**
  - Delete the comment "Missing means 'rate'; see resolvePurchaseMode()".

- [ ] **Step 5: Remove the "above the default rate" liability line**
  - Delete `liability_above_default` from `campaign-performance.schema.ts`. zod drops the key while the server still sends it, until Task 2.
  - In `CampaignPerformanceTiles.tsx`, the "Cost in liability" tile's `subline` becomes `__('nothing awarded', 'yayloyalty')` when `totals.liability_amount` is `null` or `0`, and is omitted otherwise. This drops the multiplier-only sentence and stops the current else-branch saying "nothing awarded" under a non-zero amount.

- [ ] **Step 6: Reword the leftovers**
  - `campaign-templates.ts:31`: "Earn points on completed orders, at a rate per amount spent."
  - `usePurchaseCampaignsQuery`'s docblock in `campaign.queries.ts`: it serves the flexible-discount reward's earn-back line, not the editor's multiplier mode.

- [ ] **Step 7: Verify nothing still names the mode**
  - Run: `git grep -n -i "multiplier\|resolvePurchaseMode\|multipliedRate\|liability_above_default" -- apps/admin/src`
  - Expected: matches only in `pages/customers/components/customers-adjust-points.*` (the typo guard).

- [ ] **Step 8: Check the touched files**
  - From `apps/admin`, run `node node_modules/typescript/bin/tsc --noEmit -p . 2>&1 | grep -E "<each touched file name>"`. Expected: no output.
  - Run `node node_modules/eslint/bin/eslint.js <touched files>`. Expected: no output.
  - Run `node node_modules/prettier/bin/prettier.cjs --check <touched files>`. Expected: "All matched files use Prettier code style!".
  - Run `pnpm --filter @yayloyalty/admin-page test`. Expected: all pass (64 on the current branch).

- [ ] **Step 9: Verify in the admin (Vite on :3030, Pro)**
  - Open `http://yaydevs.local/wp-admin/admin.php?page=yayloyalty#/campaigns/4/edit` ("Hello ne", a non-default purchase campaign).
  - Expected: Reward shows one Rate field and the Advanced toggle, with no cards and no warning. DevTools Network shows no `trigger=purchase&per_page=100` request on load or while editing.
  - Change the rate to 2 and save. Expected: "Campaign saved.", you stay on the editor, and the rate reads 2 after a reload.
  - Open `#/campaigns`. Expected: the "Gives" column shows a per-amount rate for every purchase row.
  - Open `#/campaigns/<default purchase id>/edit`. Expected: the same single Rate field.
  - Open a purchase campaign's performance page. Expected: the "Cost in liability" tile has no "above the default rate" line.

- [ ] **Step 10: Commit**
  - Stage: the eight files above, by name.
  - Message: `refactor(campaigns): drop the purchase multiplier mode from the admin`

### Task 2: Server: purchase campaigns pay their own rate only

**Files:**
- Modify: `includes/Engine/Points/Triggers/PurchaseHandler.php`
- Modify: `includes/Engine/Points/Triggers/Registry.php:37-40`
- Modify: `includes/Engine/Points/PointsEngine.php:35-37`
- Modify: `includes/Models/Campaign.php:200-220` (`config_schema`), `:287-312` (`config_is_valid`)
- Modify: `includes/Controllers/CampaignRestController.php:150-162`, `:290-298`
- Modify: `includes/Engine/Storefront/LauncherEarnRules.php`
- Modify: `includes/Engine/Storefront/LauncherPayload.php:66`
- Modify: `includes/Pro/Controllers/CampaignPerformanceRestController.php:197-253`
- Modify: `includes/Repositories/CampaignRepository.php:104` (docblock only)

**Interfaces:**
- Consumes: Task 1's SPA no longer sends `mode`/`multiplier` and no longer reads `liability_above_default`.
- Produces:
  - `PurchaseHandler::rate_of( array $config ): float` returns `(float) ( $config['points_per_unit'] ?? 0 )`.
  - `PurchaseHandler::is_multiplier()`, `::multiplier()` and `::multiplier_default()` no longer exist.
  - `PurchaseHandler::__construct( BaseCalculator $base_calculator )`
  - `Registry::with_lite_handlers(): self`, with no parameter.
  - `LauncherEarnRules::advertise( array $live, bool $can_register ): array`
  - `Campaign::LEGACY_CONFIG_KEYS = [ CampaignTrigger::PURCHASE => [ 'mode', 'multiplier' ] ]`, a private const array. PHP 7.4 allows const arrays.

- [ ] **Step 1: Simplify `PurchaseHandler`**
  - Delete the `CampaignRepository` property and constructor parameter.
  - Delete the multiplier check in `is_eligible()`.
  - `compute_points()` calls `self::rate_of( $config )`.
  - Delete `is_multiplier()`, `multiplier()` and `multiplier_default()`. Change `rate_of()` to match Interfaces.
  - Remove the "A multiplier campaign…" sentence from the class docblock, and any `use` it leaves unused (`Status`, `CampaignRepository`).
  - Update `Registry::with_lite_handlers()` to `new PurchaseHandler( new BaseCalculator() )` with no parameter, and drop its `CampaignRepository` import if unused.
  - In `PointsEngine.php:37`, call `Registry::with_lite_handlers()`.

- [ ] **Step 2: Drop the mode from the model and tolerate its keys**
  - In `Campaign::config_schema()`, delete the purchase `mode` and `multiplier` entries.
  - Add `LEGACY_CONFIG_KEYS` (Interfaces), with a one-line docblock: keys an earlier build stored, accepted on input and never stored.
  - In `config_is_valid()`, `continue` past a key in `self::LEGACY_CONFIG_KEYS[ $trigger ] ?? []`, beside the existing `Json::SCHEMA_VERSION_KEY` skip.
  - `set_config()` → `validate_config()` already drops them, because they're off the whitelist. No change there.

- [ ] **Step 3: Simplify the campaigns controller**
  - `lacks_purchase_rate()` becomes `PURCHASE === $trigger && ! isset( $config['points_per_unit'] )`. Remove the multiplier sentence from its docblock.
  - Delete the "default campaign cannot multiply itself" guard at lines 290–298 and its comment.
  - Remove the `PurchaseHandler` import if unused.

- [ ] **Step 4: Verify that legacy keys are accepted and dropped**
  - Use the `wp eval` socket recipe in memory `wp-cli-local-socket`. Run `wp eval 'echo wp_json_encode( ( new \YayLoyalty\Repositories\CampaignRepository() )->find( 4 )->get_config() );'`, adjusting the constructor to the repository's real one.
  - From the editor page's DevTools console, run `wp.apiFetch({ path: '/yayloyalty/v1/campaigns/4', method: 'PUT', data: { config: { points_per_unit: 2, mode: 'multiplier', multiplier: 2 } } })`.
  - Expected: it resolves, and the returned `config` has `points_per_unit: 2` and no `mode` or `multiplier`.
  - Repeat the PUT with `config: { mode: 'rate' }` and no rate. Expected: 400 "A purchase campaign needs a rate.".

- [ ] **Step 5: Find leftover multiplier rows on the local site**
  - Run (socket recipe): `wp eval 'global $wpdb; foreach ( $wpdb->get_results( "SELECT id, name, config FROM {$wpdb->prefix}yayloyalty_campaigns WHERE config LIKE \"%multiplier%\"" ) as $r ) { echo "$r->id $r->name $r->config\n"; }'`. Adjust the table name to `Installer::schemas()`.
  - Expected: a list, possibly empty. For each row, open its editor, set a rate and save. A row with no `points_per_unit` is refused until a rate is entered (Review focus).

- [ ] **Step 6: Simplify the launcher**
  - `LauncherEarnRules::advertise()` drops `$default_purchase`.
  - `purchase_rate( array $config ): ?float` uses `PurchaseHandler::rate_of( $config )`.
  - Remove the multiplier sentences from the class and parameter docblocks.
  - In `LauncherPayload.php:66`, call `LauncherEarnRules::advertise( $this->live_campaigns( $now ), null !== $register )`. Remove `$this->campaigns` from `LauncherPayload` only if nothing else uses it.

- [ ] **Step 7: Remove the above-default liability**
  - In `CampaignPerformanceRestController`, delete the `liability_above_default` key from `shape_totals()`, its assignment, the `liability_above_default()` method, and the `PurchaseHandler` import.
  - Keep `PointsMath`, which `liability_amount` still uses.

- [ ] **Step 8: Reword `CampaignRepository::find_default_purchase()`'s docblock to "The built-in purchase campaign. Null if archived."**

- [ ] **Step 9: Verify nothing still names the mode, and check PHP**
  - Run `git grep -n -i "multiplier\|is_multiplier\|multiplier_default\|liability_above_default" -- includes`. Expected: no campaign-mode matches. Tier or anomaly wording may remain.
  - Run `vendor/bin/phpcs <touched PHP files>`. Expected: no findings on lines this task changed. Compare with `git stash` on `dev/edd` if unsure.
  - Run `composer test`. Expected: same result as on `dev/edd`.
  - Re-read the diff for PHP 8-only syntax. phpcs doesn't catch it.

- [ ] **Step 10: End-to-end check (acceptance criterion 6)**
  - Make "Hello ne" active at 2 pts per currency unit and dragged above the default purchase campaign, with no conditions.
  - Place a test order on the storefront and mark it completed.
  - Expected:
    - The customer's ledger row (Customers → drawer → Ledger) shows points = 2 × the counted amount, rounded per the campaign's Rounding.
    - The storefront launcher's "Ways to earn" purchase line shows 2 pts per unit.
    - The campaign's performance page totals include the order, with no "above the default rate" line.
  - Afterwards, restore the campaign's earlier status and priority.

- [ ] **Step 11: Commit**
  - Stage: the nine PHP files above, by name.
  - Message: `refactor(campaigns): remove the purchase multiplier mode from the engine`
