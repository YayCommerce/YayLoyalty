# Remove Tiers and Referrals Implementation Plan

> **For agentic workers:** execute tasks in order, one at a time. Steps use `- [ ]` for tracking. There is no spec; the Requirements section below is the source.

**Status:** done 2026-10-06
**Goal:** v1.0 ships no tier or referral UI or logic. Only the stored data shape stays, so the next phase builds both features from scratch.
**Spec:** none, planned from the request
**Architecture:** Pure removal, one task per surface, each leaving the plugin working. The stored shape stays: the `customers` columns and the enum values that stored rows can carry, with their TypeScript mirrors. Two backlog items record what phase 2 must build.
**Touches:** `includes/Controllers/`, `includes/Engine/RestAPI.php`, `includes/Repositories/CustomerRepository.php`, `includes/Enums/CustomerFilters.php`, `includes/Access/Capabilities.php`, `includes/Helpers/Design*.php`, `includes/Models/*ConditionSet.php`, `includes/Utils/ConditionValueRules.php`, `includes/Pro/{Controllers,Helpers,Emails}/`, `scripts/validate-build.php`, `apps/admin/src/{router.tsx,components/layout,lib,pages,pro,types}`, `docs/architecture.md`, `docs/backlog/`
**Tests:** none. Nothing here touches points or money math or `LedgerService`.

## Requirements

- **Goal:** No tier or referral page, nav item, tab, tile, filter, column, setting, email, capability or REST field remains.
- **Kept (stored data only):**
  - The `customers` columns `tier_id`, `tier_evaluated_at`, `tier_progress_value` and `referral_code`, with their indexes.
  - The enum values, with their TS mirrors:
    - `CampaignTrigger::REFERRAL`
    - `LedgerSource::REFERRAL`, and its ledger label
    - `ReferenceType::REFERRAL`
    - `ActivityEvent::TIER_CHANGED`, and its title
    - `CampaignConditionType::TIER` and `RewardConditionType::TIER`, which stay in `UNAVAILABLE`
  - `CustomerFacts::$tier_id`.
  - The plugin description "Referral & VIP".
- **Out of scope:** `docs/plans/` and `docs/specs/`, which are history.
- **Decided with the user:**
  - Remove the Design keys from the schema and the UI.
  - Remove the stub emails (tier, referral, invite).
  - Remove `MANAGE_TIERS`, with a role migration.
  - Keep the plugin description.
  - Note both features as backlog items.
- **Acceptance criteria:**
  1. `git grep -n -i -E 'tier|referr' -- includes apps/admin/src scripts` matches only the kept items in this section, comments about them, and "tier" meaning the Lite/Pro build.
  2. `GET /customers/{id}/referrals` returns `rest_no_route`. `GET /dashboard/pro` has no `referrals` or `tier_distribution`. `GET /customers/{id}` has no `counts.referrals` or `loose_ends.credited_referrals`.
  3. The administrator role lacks `yayloyalty_manage_tiers`, and `yayloyalty_caps_version` is `2`.
  4. The Pro walk in Task 7 passes with no zod errors, and the type-check prints only the baseline errors.

## Global constraints

- Product PHP must run on PHP 7.4.
- Lite names Pro in exactly one place. SPA Pro code is imported only through `apps/admin/src/pro/index.ts`, behind `IS_PRO_BUILD`.
- Never hand-edit generated output.

### Task 1: Referrals REST, drawer tab and customer fields

**Files:** delete `includes/Controllers/ReferralsRestController.php`, `apps/admin/src/lib/schema/referral.schema.ts`, `pages/customers/drawer/customer-drawer.referral-rows.ts`, `customer-drawer.referrals.copy.ts`, `tabs/ReferralsTab.tsx`, `tabs/ReferralsTabHeader.tsx`, `tabs/ReferralsTabRows.tsx`. Modify `includes/Engine/RestAPI.php`, `CustomerItemFormatter.php`, `Schemas/CustomerItemSchema.php`, `lib/api/customer-drawer.api.ts`, `lib/queries/customer.queries.ts`, `lib/schema/customer.schema.ts`, `CustomerDrawerTabs.tsx`, `CustomerDrawerLooseEnds.tsx`, `customer-drawer.copy.ts`, `customer-drawer.panels.copy.ts`, and the Customers table and card files that carry `showProColumns` and the Referrals column or sort key.

- [ ] Remove the route, the fetchers, the tab, `counts.referrals`, `loose_ends.credited_referrals`, the `referrals` sort key, the Referrals column and the `showProColumns` prop chain.
- [ ] Verify: `GET /customers/1/referrals` returns `rest_no_route`. The drawer has no Referrals tab. The table has no Referrals column.
- [ ] Commit: `refactor: remove the referrals placeholder from customers`

### Task 2: Tier list filter and segments

**Files:** `includes/Enums/CustomerFilters.php`, `CustomerListFormatter.php`, `CustomerRowFilters.php`, `CustomerRepository.php`, `CustomerAdjustPreviewRestController.php`, `Pro/Helpers/CustomerSegmentsHelper.php`, `Models/CampaignConditionSet.php`, `Models/RewardConditionSet.php`, `Utils/ConditionValueRules.php`, `pro/customers/customer-segments.api.ts`, `types/localize.type.ts`, `lib/schema/customer.schema.ts`.

- [ ] Remove `TIER_RANGES`, the `tier` param and its criteria, the segment `tier` key, and the unreachable `case 'tier'`. Reword the "user_role or tier condition" message.
- [ ] Verify: `get_segments()` drops a stored `tier`. A saved segment applies in the SPA. `git grep -n TIER_RANGES` finds nothing.
- [ ] Commit: `refactor: remove the tier customer filter`

### Task 3: Dashboard referrals and tier distribution

**Files:** delete `pro/dashboard/DashboardTierDistribution.tsx`. Modify `Pro/Controllers/DashboardProRestController.php`, `lib/schema/dashboard-pro.schema.ts`, `pro/dashboard/DashboardProPanels.tsx`, `pages/dashboard/dashboard-page.pro-slots.ts`, `DashboardPage.tsx`, `components/DashboardProTeasers.tsx`.

- [ ] Verify: `GET /dashboard/pro` returns `range`, `campaign_series` and `top_campaigns`. The Pro dashboard renders with no zod error.
- [ ] Commit: `refactor: remove the referrals and tier dashboard panels`

### Task 4: Pages, nav, drawer action, activity group, capability

**Files:** delete `pages/vip-tiers/VipTiersPage.tsx`, `pages/referral/ReferralPage.tsx`. Modify `router.tsx`, `components/layout/HeaderMenu.tsx`, `lib/access.tsx`, `CustomerDrawerActions.tsx`, `CustomersBulkActionBar.tsx`, `customer-drawer.copy.ts`, `lib/schema/activity.schema.ts`, `tabs/ActivityLogTab.tsx`, `customer-drawer.activity-rows.ts`, `customer-drawer.activity.copy.ts`, `Controllers/Schemas/ActivityQueryArgs.php`, `Access/Capabilities.php`.

**Interfaces:** `Capabilities::VERSION = 2`. `migrate()` removes `yayloyalty_manage_tiers` from every role when the stored version is below 2.

- [ ] Verify: the header has no Tiers or Referral item. `wp eval` shows that `get_role('administrator')->has_cap('yayloyalty_manage_tiers')` is `false` and the caps version is `2`.
- [ ] Commit: `refactor: remove the tiers and referral pages and capability`

### Task 5: Design settings keys

**Files:** delete `pro/design/DesignTierSection.tsx`, `pro/design/DesignTierBadgeRow.tsx`. Modify `includes/Helpers/DesignSchema.php`, `DesignCoercion.php`, `scripts/validate-build.php`, `lib/schema/design.schema.ts`, `pro/index.ts`, `pages/settings/DesignSettingsPage.tsx`, `DesignStorefrontTextSection.tsx`, `pro/design/DesignPanelSection.tsx`, `design-settings.copy.ts`, `design-settings.option-labels.ts`.

- [ ] Verify: the Design page saves. `DesignHelper::get_design()` has no `tier` key.
- [ ] Commit: `refactor: remove the tier and referral design settings`

### Task 6: Pro stub emails

**Files:** delete `includes/Pro/Emails/{ReferralRewardEarnedEmail,TierReachedEmail,TierEndingSoonEmail,TierLostEmail,InviteSentEmail}.php` and their templates. Modify `includes/Pro/Emails/ProEmails.php`.

- [ ] Verify: the WooCommerce email list has no tier, referral or invite email.
- [ ] Commit: `refactor: remove the tier and referral stub emails`

### Task 7: Docs, backlog, final check

**Files:** `docs/architecture.md`, create `docs/backlog/2026-10-06-tiers-not-built.md` and `docs/backlog/2026-10-06-referrals-not-built.md`.

- [ ] Run the grep in acceptance criterion 1. Expect only the kept items.
- [ ] Run `composer lint`. Expect the baseline warnings only.
- [ ] Run the tsc type-check. Expect the 3 baseline TS2307 errors only.
- [ ] Run ESLint and Prettier on the changed files. Expect no new issues.
- [ ] Pro walk: dashboard, Customers, Design and Emails.
- [ ] Commit: `docs: record tiers and referrals as backlog`
