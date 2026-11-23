# Phase 2: Drawer tier card, manual floors and labels

**Plan:** `README.md` in this directory: the Status, constraints and interface contract
**Depends on:** Phase 1, all tasks: `TierEvaluator::assign_floor()` and `clear_floor()`, `TierRunner`'s floor-end step, `TIER_QUERIES`, `TierBadge` and `useTiersEnabled()`.
**Delivers:**
- The drawer's tier card, with Assign tier and Remove manual tier.
- Manual floors that end on the daily run.
- The activity tab's "Tier changes" filter, with readable `tier_changed` rows.
- "{Tier} tier bonus" in the admin ledger, the store ledger and My Account's points history.

**Exit criterion:** spec AC 10, 11, 12 and 15 pass by the checks in Task 1 Step 4, Task 2's last step and Task 3's last step.

### Task 1: Customer tier routes

**Done when:** `GET/POST/DELETE /customers/{id}/tier` meet spec AC 10–12 over REST.

**Files:**
- Create: `includes/Pro/Controllers/CustomerTierRestController.php`
- Modify: `includes/Pro/YayLoyaltyPro.php` (`add_controllers()`)

**Interfaces:**
- Consumes: Phase 1's `TierEvaluator` and `Customer` getters; `TierRepository::live()`, `find_tier()`.
- Produces: the three routes in the README contract, with the spec's response shape.
  - **Constructor:** `( CustomerRepository $customers, TierRepository $tiers )`.
  - **Route params:** `id` uses `member_id_param()` for GET and `user_id_param()` for POST and DELETE. POST's args are `tier_id` (integer, required, minimum 1) and `days` (`[ 'integer', 'null' ]`, minimum 1, maximum 3650, default null).
  - **Response:** one private `respond( int $user_id ): array`, used by all three.

Steps: expand after Phase 1 lands.

### Task 2: Drawer card, Assign and Remove dialogs, activity rows

**Done when:** spec AC 10–12 hold through the drawer UI, and `tier_changed` rows read as the spec's UI section says.

**Files:**
- Create: `apps/admin/src/pro/customers/CustomerTierCard.tsx` (the card plus its Assign dialog and Remove confirm, each with one caller), `apps/admin/src/pro/customers/customer-tier-assign.form.ts`
- Modify:
  - `apps/admin/src/lib/schema/tier.schema.ts` (`customerTierSchema`);
  - `apps/admin/src/pro/tiers/tiers.api.ts`, `apps/admin/src/pro/tiers/tiers.queries.ts`;
  - `apps/admin/src/pro/index.ts`;
  - `apps/admin/src/pages/customers/drawer/CustomerDrawer.tsx` (an `IS_PRO_BUILD &&` slot between Stats and Actions);
  - `includes/Controllers/Schemas/ActivityQueryArgs.php` (group `tiers`), `apps/admin/src/lib/schema/activity.schema.ts` (`ACTIVITY_GROUPS` gains `'tiers'`);
  - `apps/admin/src/pages/customers/drawer/tabs/ActivityLogTab.tsx` (the "Tier changes" option, only when `IS_PRO_BUILD`);
  - `apps/admin/src/pages/customers/drawer/customer-drawer.activity-rows.ts`, `apps/admin/src/pages/customers/drawer/customer-drawer.activity.copy.ts` (the `tier_changed` sub-line "{from or No tier} → {to or No tier} · {reason label}", with the spec's reason labels).

**Interfaces:**
- Consumes: Task 1's routes; Phase 1's `TierBadge` and `useTiersEnabled()`.
- Produces:
  - `customerTierSchema`, `getCustomerTier`, `assignCustomerTier`, `removeCustomerTier`;
  - `TIER_QUERIES.customer(userId)`, `useCustomerTierQuery(userId)`, `useAssignCustomerTierMutation(userId)`, `useRemoveCustomerTierMutation(userId)`. Each mutation invalidates `['tier']` and the customer's detail, list, activity and ledger query keys from `lib/queries/customer.queries.ts`;
  - `CustomerTierCard` from `@/pro`. It renders null unless `useTiersEnabled()`.

Steps: expand after Phase 1 lands. The last step is the drawer walkthrough of spec AC 10–12 on wp-env, plus the Chrome console check (Claude) on `#/customers/<id>`.

### Task 3: Bonus labels

**Done when:** spec AC 15 holds: "Silver tier bonus" in the drawer Ledger tab, on the Pro Ledger page and in My Account, and still after Silver is deleted.

**Files:**
- Create: `includes/Controllers/LedgerItemTierNames.php`
- Modify:
  - `includes/Controllers/LedgerRestController.php` (`get_history()` and the item schema), `includes/Pro/Controllers/StoreLedgerRestController.php` (`get_ledger()`);
  - `includes/Engine/Storefront/ShopperActivityRow.php` (`earn_label()` takes the entry and resolves the name through `TierRepository::find_many()`, once per `items()`/`history_rows()` call);
  - `apps/admin/src/lib/schema/ledger.schema.ts`, `apps/admin/src/lib/helpers/ledger.helper.ts` (`describeEarn()` `tier_bonus` case: "%s tier bonus", or "Tier bonus" when `tier_name` is null).

**Interfaces:**
- Consumes: Phase 1's `LedgerSource::TIER_BONUS` and `TierRepository::find_many()`.
- Produces: `LedgerItemTierNames::attach()` and the ledger entry's `tier_name` (README contract).

Steps: expand after Phase 1 lands. The last step checks AC 15 on wp-env: the drawer Ledger tab, `#/ledger`, and http://localhost:8889/my-account/ points history as the customer. Then it deletes Silver and checks all three again.
