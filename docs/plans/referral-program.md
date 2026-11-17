---
id: P-3
title: Referral data, settings and link attribution
status: in-progress
from: BS-3
roadmap: R-4
design: UX-2
trello:
type: feat
branch: feat/p-3-referral-data-settings-and-link-attribution
pr:
editions: [pro]
created: 2026-11-17
---
# P-3 Referral data, settings and link attribution

Slice 1 of 4 for R-4, split from the first P-3 draft (2026-11-17), which planned the superseded "points for both, set in a campaign" scope. Build order: **P-3** → P-4 (friend discount, hold, payout) → P-5 (admin Referrals and Approvals) → P-6 (My Account Referrals tab). Each slice merges to `dev` alone. R-4 is done when all four are.

## Goal
A Pro merchant opens the new **Referral** header item, goes to the **Settings** tab and sets up the program: the friend's first-order discount (fixed or percentage, minimum order, how many days the coupon is valid), the advocate's points, the hold before payout, whether orders that used another coupon are excluded, and the cookie window. They switch **Program live** on and save. While the program is live, a visitor who opens `?yl_ref=CODE` and then registers within the cookie window becomes a `pending` referral. The referral keeps a snapshot of the terms at signup, and self-referrals and existing accounts never create one.

Done means:
- UX-2's Settings acceptance passes: set $10 fixed, $50 minimum, 30 days, 1,000 points and a 14-day hold, then save and reload. The values are kept and the preview reads "Give $10.00, get 1,000 points". A percentage of 150 is blocked with "Percentage must be 1 to 100".
- The attribution cases in Verify pass.
- Lite has no referrals table, no Referral menu item, and `#/referral/settings` shows the 404 page.

R-4's acceptance (copy the link, discount on the first order, points after the hold, approvals, no self-referrals or refunds) is completed by P-4 to P-6.

## Non-goals
- Paying anyone, the friend's discount, the hold job, reversal: P-4.
- The Referrals and Approvals tabs: P-5. Until then the Referral item has only Settings.
- Generating a customer's code and showing their link: P-6. P-3 only looks a code up.
- A click table and click stats (BL-5, R-10), the `/r/CODE` landing page, share buttons, a code typed at checkout, fraud flags, the per-advocate cap (BL-8, R-12), referral emails, the Lite upsell (R-10..R-14).
- Attributing an existing account that opens a link.
- Activity-timeline events for referrals. BL-10.

## Findings
1. **Settings are program-level, not a campaign.** UX-2 dropped the earlier "Referral campaign" draft (UX-2 Decisions, "Navigation"). Lite's `yayloyalty_settings` belongs to `SettingsHelper` and is Lite's. Pro already keeps its own option with its own helper: `yayloyalty_customer_segments` → `CustomerSegmentsHelper` (`includes/Pro/Helpers/CustomerSegmentsHelper.php:18`). → T2 adds the Pro option `yayloyalty_referral_settings` through a `ReferralSettings` helper of the same shape. Pro data in a Pro option, not in a Lite one, means a downgraded site keeps it and Lite ignores it.
2. **"Settings change only new referrals"** (UX-2 Decisions) needs the terms on the row. BS-3's data model has no column for them. → T1 adds a `terms` JSON snapshot (`Utils\Json`, `schema_version` 1), plus `coupon_expires_at` and `hold_until` as real columns, because P-4's daily job queries them.
3. **Statuses grew** to `pending`, `on_hold`, `rewarded`, `rejected`, `reversed` and `expired` (BS-3 Revised). → T1's enum, with `KEY (status, hold_until)` and `KEY (status, coupon_expires_at)` for P-4's job.
4. **A Pro table isn't installed on fresh activation or on a Lite → Pro switch at the same version.**
   - `ActDeact::single_activate()` runs `Installer::install()` before `plugins_loaded`, so Pro hasn't added its schema yet (`includes/Engine/ActDeact.php:107`).
   - `Installer::maybe_upgrade()` returns while `yayloyalty_db_version` equals the version (`includes/Database/Installer.php:75`).
   - The `yayloyalty_table_schemas` filter is there (`Installer.php:49`).
   → T1's `ProInstaller` adds the schema through the filter and stamps `yayloyalty_pro_db_version`. When that stamp differs from `YAYLOYALTY_VERSION`, Pro runs `Installer::install()` once, which is idempotent.
5. **Self-registration is already defined** by `SignupListener::is_self_registration()` (`includes/Engine/Earning/Listeners/SignupListener.php:58-64`). The customer row is created on `user_register` by `CustomerActivityListener`. → T3 hooks at priority 20, after it.
6. **`customers.referral_code`** is a unique, nullable Lite column (`includes/Database/Schema/CustomersTable.php:40,53`) that nothing reads or writes. → T3 adds the read (`find_user_by_referral_code`) to `CustomerRepository`, the table's only repository. P-6 adds the write.
7. **`?ref=` is AffiliateWP's parameter**, among others. → `yl_ref`, as UX-2 shows.
8. **The header menu is static JSX** (`apps/admin/src/components/layout/HeaderMenu.tsx:15-45`), and a route's cap must match its item's cap (`router.tsx`, `guarded()`). → T4 adds the item behind an `IS_PRO_BUILD` ternary (hard rule 2), under `CAP.VIEW`, and a Lite-safe route wrapper like `pages/ledger/LedgerPage.tsx`.
- **Differs:**
  - `docs/knowledge/architecture.md` says Pro "writes no tables of its own" and lists neither the option nor the routes.
  - `admin-spa.md`'s route list has no Referral item.
  - `decisions.md` has no entry for the first Pro-only table or its install stamp.
  - → T6 updates them.

## Approach
- **Data (Pro, T1):** `ReferralsTable` (slug `referrals`) in `includes/Pro/Database/`.
  - Columns:
    - `id`, `advocate_user_id`, `friend_user_id`
    - `referral_code` (a snapshot of the code used), `status`, `reject_reason`
    - `terms` (LONGTEXT JSON: discount type, amount and minimum in base currency, advocate points, hold days, exclude other coupons)
    - `coupon_expires_at`, `first_order_id`, `hold_until`, `created_at`, `updated_at`, all UTC
  - Indexes: `UNIQUE friend_user_id`, `KEY (advocate_user_id, status)`, `KEY first_order_id`, `KEY (status, hold_until)`, `KEY (status, coupon_expires_at)`.
  - A `Referral` model, a `ReferralRepository` (one table, SQL only), and a `ReferralStatus` constant class. The model has no getters yet: P-4 adds the ones its writers read, and P-4 adds `ReferralRejectReason` with the first writer of a reason.
  - `ProInstaller` (Finding 4).
  - A site downgraded to Lite keeps the orphan table, and Lite ignores it. The plugin has no uninstall routine, so the table stays like every other table.
- **Settings (Pro, T2):** `ReferralSettings::get() / update() / defaults()` over `yayloyalty_referral_settings`. Keys and defaults:
  - `enabled` false
  - `discount_type` `fixed` (`fixed` or `percent`)
  - `discount_amount` "10", above 0, at most 100 for a percentage
  - `min_order` "0", where 0 means no minimum. UX-2's $50 is the walk-through value, not the default.
  - `coupon_days` 30 (1–365)
  - `advocate_points` 1000 (≥1)
  - `hold_days` 14 (0–90)
  - `exclude_other_coupons` true
  - `cookie_days` 30 (1–365)

  Money is a string in base currency. Unknown keys are dropped, as with `SettingsHelper::update_settings()`. REST: `ReferralSettingsRestController` serves `GET referral/settings` under `Capabilities::VIEW` and `POST referral/settings` under `Capabilities::MANAGE_CAMPAIGNS` (UX-2: without it, Settings is read-only) through `exec_write`, and is added via `yayloyalty_rest_controllers`.
- **Attribution (Pro, T3):** `ReferralAttribution`.
  - **Visit:** on `template_redirect`, while the program is enabled, a well-formed `yl_ref` naming an existing customer code sets the first-party cookie `yayloyalty_ref` for `cookie_days` (HttpOnly, SameSite=Lax, secure on HTTPS, holding only the code, via `wc_setcookie`). Logged-in visitors are skipped. Nothing is written to the database.
  - **Signup:** on `user_register` at priority 20, only when `is_self_registration()`, the program is enabled, the cookie names a customer, and that customer is not the new user. One `pending` row is inserted with the terms snapshot and `coupon_expires_at = registered + coupon_days`, and the cookie is cleared. The unique friend makes a repeat a no-op. All of it runs in `ListenerGuard::run()`.
- **Admin SPA (T4–T5), Pro only:**
  - A "Referral" header item between Rewards and Settings, under `CAP.VIEW`.
  - Route `referral` with a tab layout like `SettingsLayout`, child `settings`. `#/referral` redirects to `settings` until P-5 adds Referrals as the default.
  - The Settings tab builds UX-2 "Referral → Settings": numbered cards, `MoneyInput`, `NumberInput`, a `RadioGroup` for the discount type, a `Checkbox`, the `Switch`, the static preview rail, the settings page's save bar and the toast "Referral settings saved". Fields are disabled without `CAP.MANAGE_CAMPAIGNS`.
  - Lite: no menu item, and `pages/referral/ReferralPage.tsx` renders `NotFoundPage`.
- **Editions:** everything new is in `includes/Pro/` and `apps/admin/src/pro/`, except `CustomerRepository::find_user_by_referral_code()` (Lite's repository for a Lite column, naming nothing Pro), the route wrapper and the menu ternary.
- **Compatibility:** PHP 7.4. No order access in this slice. The cookie is functional, so no consent change.

## Tasks
- [x] T1 Pro referrals table, model, repository, status constants and Pro install stamp — `includes/Pro/Database/ReferralsTable.php` (new), `includes/Pro/Database/ProInstaller.php` (new), `includes/Pro/Models/Referral.php` (new), `includes/Pro/Repositories/ReferralRepository.php` (new), `includes/Pro/Enums/ReferralStatus.php` (new), `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `AbstractTableSchema` with `RedemptionsTable` as the shape (`includes/Database/Schema/`), filter `yayloyalty_table_schemas` and `Installer::install()` (`includes/Database/Installer.php`), `AbstractRepository` (`includes/Repositories/AbstractRepository.php`), `AbstractModel` (`includes/Models/AbstractModel.php`), `StringEnumMethods` (`includes/Enums/StringEnumMethods.php`), `Utils\Json::encode|decode`, `Utils\Dates::now()`
  - Callers: `ProInstaller::add_schemas()` → filter `yayloyalty_table_schemas`; `ProInstaller::maybe_install()` → action `plugins_loaded` priority 20, registered in `YayLoyaltyPro::boot()`; `ReferralRepository::insert_pending()`, `find_by_friend()` → `ReferralAttribution` (T3); `ReferralStatus` → the repository; `ProInstaller::register()` → `YayLoyaltyPro::boot()`
- [x] T2 Referral settings option and REST route — `includes/Pro/Referrals/ReferralSettings.php` (new), `includes/Pro/Controllers/ReferralSettingsRestController.php` (new), `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `CustomerSegmentsHelper` (`includes/Pro/Helpers/CustomerSegmentsHelper.php`) and `SettingsHelper::update_settings()` as the pattern, `Utils\ConfigSpec::within_bounds`, `AbstractRestController::requires()` with `exec_read` / `exec_write`, `Capabilities::VIEW`, `Capabilities::MANAGE_CAMPAIGNS`
  - Callers: `ReferralSettings::get()` → `ReferralAttribution` (T3), the controller; `update()` → `POST referral/settings`; `ReferralSettingsRestController` → filter `yayloyalty_rest_controllers` (`YayLoyaltyPro::add_controllers()`); routes → the Settings tab (T5)
- [x] T3 Referral link capture and signup attribution — `includes/Pro/Referrals/ReferralAttribution.php` (new), `includes/Repositories/CustomerRepository.php`, `includes/Pro/YayLoyaltyPro.php` [risk: 3]
  - Reuse: `SignupListener::is_self_registration()`, `ListenerGuard::run()`, `wc_setcookie()`, `ReferralSettings::get()` (T2), `ReferralRepository::insert_pending()` (T1), `Dates::now()` / `Dates::offset()`
  - Callers: `ReferralAttribution::register()` → `YayLoyaltyPro::boot()`; `on_template_redirect()` → action `template_redirect`; `on_user_register()` → action `user_register` priority 20; `CustomerRepository::find_user_by_referral_code()` → `ReferralAttribution` (P-6 adds a second caller)
- [x] T4 Referral header item, route and tab layout (Pro only) — `apps/admin/src/components/layout/HeaderMenu.tsx`, `apps/admin/src/router.tsx`, `apps/admin/src/pages/referral/ReferralPage.tsx` (new, Lite-safe wrapper), `apps/admin/src/pro/referral/ReferralLayout.tsx` (new), `apps/admin/src/pro/index.ts`
  - Reuse: `pages/settings/SettingsLayout.tsx` (tab links, `aria-current`), `pages/ledger/LedgerPage.tsx` (Lite 404 wrapper), `guarded()` and `CAP`, `IS_PRO_BUILD`, `PageLayout`; design UX-2 "Referral → Settings" (page and tabs)
  - Callers: `ReferralLayout` (pro export) → `pages/referral/ReferralPage.tsx` → route `referral` in `router.tsx`; header item → route `referral`
- [x] T5 Referral → Settings tab — `apps/admin/src/pro/referral/settings/ReferralSettingsPage.tsx` (new), `apps/admin/src/components/ui/custom/amount-inputs.tsx`, `apps/admin/src/pro/referral/settings/ReferralOfferPreview.tsx` (new), `apps/admin/src/pro/referral/settings/referral-settings.form.ts` (new), `apps/admin/src/pro/referral/settings/referral-settings.copy.ts` (new), `apps/admin/src/pro/referral/referral-settings.schema.ts` (new), `apps/admin/src/pro/referral/referral.api.ts` (new), `apps/admin/src/pro/referral/referral.queries.ts` (new), `apps/admin/src/router.tsx`
  - Reuse: `GeneralSettingsPage.tsx` with `general-settings.form.ts` (cards, `Field`, save bar, dirty-only update), `MoneyInput` (`components/ui/custom/amount-inputs.tsx`, given an optional `id` so its label is tied to it), `NumberInput` with a unit as `pro/campaigns/editor/CampaignEditorEarn.tsx:147-180`, `useEditorSave`, `useRouteLeaveGuard`, `formatWPCurrency` / `formatStoreNumber`, `pointsPhrase`, `reward.api.ts` / `reward.queries.ts` (API pattern), `Can` / `CAP.MANAGE_CAMPAIGNS`; design UX-2 "Referral → Settings"
  - Callers: `ReferralSettingsPage` → child route `referral/settings`; `ReferralOfferPreview` → `ReferralSettingsPage`; `referralSettingsSchema` ("Mirrors `ReferralSettings`") → `referral.api.ts`, the form
- [x] T6 Knowledge docs for the Pro table, option, routes and SPA item — `docs/knowledge/architecture.md`, `docs/knowledge/decisions.md`, `docs/knowledge/admin-spa.md`, `docs/knowledge/helpers.md`, `docs/knowledge/e2e.md`, `docs/knowledge/testing.md` [risk: 1]
  - Reuse: n/a

**Tests:** [unit] ReferralSettingsTest (`tests/php/unit/Pro/Referrals/ReferralSettingsTest.php`) — defaults when unset, out-of-range days, points and percentages rejected, unknown keys dropped, money kept as a string; [integration] ReferralAttributionTest (`tests/php/integration/Pro/Referrals/ReferralAttributionTest.php`) — a self-registration with a valid cookie while live inserts one pending row with the terms snapshot and `coupon_expires_at`, while an admin-created user, an unknown or malformed code, a deleted advocate's code, a program that's off, or a second `user_register` inserts nothing more; [integration] ReferralSettingsRestControllerTest (`tests/php/integration/Pro/Controllers/ReferralSettingsRestControllerTest.php`) — a viewer can read but gets 403 on save, and a manager's save returns the sanitized settings; [js] referral-settings.form (`tests/js/pro/referral/referral-settings.form.test.ts`) — the form schema blocks a percentage over 100, a hold over 90 days and 0 points, with UX-2's messages

**E2E:** referral-settings: Referral → Settings saves $10 fixed, $50 minimum, 30 days, 1,000 points and a 14-day hold, the values survive a reload, and the preview reads "Give $10.00, get 1,000 points" (new flow, tag `@P-3 @flow-referral-settings`).

## Verify
- `yayh test run unit --id=P-3` → OK (unit)
- `yayh test run integration --id=P-3` → OK (integration)
- `yayh test run js` → passed (js)
- `yayh e2e run --grep @P-3` → passed (e2e)
- `wp db query "SHOW TABLES LIKE '%yayloyalty_referrals'"` → yayloyalty_referrals
- `wp db query "SHOW INDEX FROM $(wp db prefix)yayloyalty_referrals WHERE Key_name='friend_user_id'"` → friend_user_id
- `wp eval 'echo get_option("yayloyalty_pro_db_version") === YAYLOYALTY_VERSION ? "stamped" : "missing";'` → stamped
- `wp eval 'wp_set_current_user(1); echo wp_json_encode( rest_do_request( new WP_REST_Request( "GET", "/yayloyalty/v1/referral/settings" ) )->get_data() );'` → "hold_days":14
- `vendor/bin/phpcs includes/Pro/Referrals includes/Pro/Database includes/Pro/Models/Referral.php includes/Pro/Repositories/ReferralRepository.php includes/Pro/Enums includes/Pro/Controllers/ReferralSettingsRestController.php` → exit 0
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `pnpm --filter @yayloyalty/admin-page lint` → exit 0
- `./scripts/run.sh build pro` → exit 0
- `./scripts/run.sh release lite 1.0.0 --no-prompt` → passed
- `unzip -l release/yayloyalty-lite-1.0.0.zip | grep -i referral` → exit 1
- wp-admin → YayLoyalty → Referral → Settings, as UX-2 Acceptance: set $10 fixed, $50 minimum, 30 days, 1,000 points, 14-day hold, Save, reload. The values are kept and the preview card reads "Give $10.00, get 1,000 points". Percentage 150 shows "Percentage must be 1 to 100." and Save is blocked. `wp option get yayloyalty_referral_settings --format=json` shows `"advocate_points":1000` (e2e).
- As a shop manager without `yayloyalty_manage_campaigns`, the Settings fields are disabled and there's no Save.
- Attribution: give a customer a code (`wp db query "UPDATE $(wp db prefix)yayloyalty_customers SET referral_code='TESTREF' WHERE user_id=<id>"`), switch the program live, open `/?yl_ref=TESTREF` in a private window, and register. Then `wp db query "SELECT status, advocate_user_id, friend_user_id, coupon_expires_at FROM $(wp db prefix)yayloyalty_referrals ORDER BY id DESC LIMIT 1"` → `pending` with the new user as friend and an expiry 30 days out (integration).
- Lite build on a fresh site: `wp db query "SHOW TABLES LIKE '%yayloyalty_referrals'"` prints nothing, there's no Referral menu item, and `#/referral/settings` shows the 404 page.
- Fresh Pro activation, and Lite → Pro at the same version: after one page load the table exists (Finding 4).

## Risks
- **Table missing on some install path:** `ProInstaller`'s stamp re-runs the idempotent install on the next load. Rollback: `wp option delete yayloyalty_pro_db_version` forces a re-run. Dropping the table loses only pending referrals, which pay nothing in this slice.
- **Attributing the wrong account:** only a self-registration with nobody logged in, a code owned by someone else, and a unique friend. Rollback: switch Program live off; nothing pays until P-4.
- **Unrelated:** the Lite zip fatals on activation on `dev` (`YayLoyaltyPluginAdapter` implements a stripped interface, from `ff4b13e`). It blocks the Lite manual checks, not the zip validator, and it's not fixed here.
- Open: BL-8 Should one advocate's paid referrals be capped? (default: no cap; R-12)
- Open: BL-9 Does a guest order the friend later claims from My Points count as their first order? (default: no; P-4 plans the default)
