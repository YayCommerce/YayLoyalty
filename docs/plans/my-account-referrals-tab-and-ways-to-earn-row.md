---
id: P-6
title: My Account Referrals tab and Ways to earn row
status: done
from: BS-3
roadmap: R-4
design: UX-2
trello:
type: feat
branch: feat/p-6-my-account-referrals-tab-and-ways-to-earn-row
pr: 200
editions: [pro]
created: 2026-11-17
---
# P-6 My Account Referrals tab and Ways to earn row

Slice 4 of 4 for R-4. It needs P-3 (table, settings, code lookup), P-4 (statuses, `hold_until`) and P-5 T5 (`assign_referral_code()`, `counts_for_advocate()`) merged first.

## Goal
While the referral program is live, a Pro customer has a **Referrals** tab in My Account, after Rewards. It shows:
- the offer worded from the settings ("Give $10, get 1,000 points")
- their own link `?yl_ref=CODE` with **Copy link**, which shows "Copied"
- four counts: Invited, Ordered, Points earned, and On the way with the next release date
- "Your invitations", newest first, 10 per page, with masked friend emails and statuses in the customer's words

Ways to earn, wherever it shows (launcher, My Points, the shortcode, the block and the page builder elements), gets a "Refer a friend" row with the advocate's points that links to the tab. With the program off, the tab and the row are gone.

Done means:
- UX-2's My Account Acceptance passes: the offer, a `?yl_ref=` link, Copy link → "Copied" with the link on the clipboard, and the tab and the Ways to earn row gone with the program off.
- At 375px the counts are two by two, the invitation rows are stacked and Copy link is full width, with no horizontal scroll.

That completes R-4's "A customer copies their referral link from My Account → Referrals".

## Non-goals
- A referral code shown on its own, share buttons, the launcher referral card and share sheet, and the `/r/CODE` landing page (R-11).
- Regenerating a customer's code.
- AJAX paging for the invitations: plain page links reload the tab (Decided).

## Findings
1. **Pro has no storefront assets today, and the Lite strip only removes `includes/Pro/`** (`scripts/run.sh:338`). Every file in `assets/storefront/` ships in Lite, and Pro's `RewardsPanel` uses only Lite's styles. → The tab's CSS and copy script go in `includes/Pro/Storefront/assets/`, are enqueued with `plugins_url()` relative to that folder, and get their version from `Launcher::asset_version()`. That keeps them out of the Lite zip.
2. **A new endpoint needs a rewrite flush.** `AccountMyPoints::maybe_flush_rewrite_rules()` flushes only when Lite's `REWRITE_VERSION` changes (`includes/Engine/Storefront/AccountMyPoints.php:61-68`). → `AccountReferrals` keeps its own stamp, `yayloyalty_pro_rewrite_version`. Lite's deactivation already deletes Lite's stamp (`includes/Engine/ActDeact.php:101`), so a reactivation flushes with Pro's query var already registered.
3. **Codes are created lazily** by `CustomerRepository::assign_referral_code()`, which P-5 T5 adds for the admin drawer (moved there 2026-11-18). → T1 reuses it: lazy, 8 random characters from an unambiguous uppercase alphabet, retried on a unique-key collision. It's random, not name-based as in UX-2's mockup "BERGSTROM-4K2", so a link never reveals a surname (Decided).
4. **The Ways to earn row** has a working pattern: `BirthdayEarnRow` on `yayloyalty_launcher_earn_rows` (`includes/Pro/Birthday/BirthdayEarnRow.php:30-39`), rendered by `rule-list-item.php`. Icons come from `AccountMyPointsIcon`, which has no "users" icon. → T3 adds one, a generic SVG in Lite naming nothing Pro.
- **Differs:** `architecture.md` (Storefront: endpoints, Pro assets) and `integrations.md` (My Account row) list only `my-points`. `build-release.md` doesn't say where Pro storefront assets live. → T4.

## Approach
- **Repository (T1):**
  - `CustomerRepository::assign_referral_code()` and `ReferralListRepository::counts_for_advocate()` come from P-5 T5. `counts_for_advocate()` returns what the tiles need:
    - invited (all rows)
    - ordered (rows with a `first_order_id`)
    - points earned (the snapshot's `advocate_points` summed over `rewarded` rows)
    - on the way (summed over `on_hold` rows) with the earliest `hold_until`
  - The invitations page is `ReferralListRepository::paginate( [ 'advocate' => $user_id, 'per_page' => 10 ] )` from P-5, which already joins `users` for the friend's email.
- **Tab (Pro, T2):** `AccountReferrals` with endpoint `referrals`, modelled on `AccountMyPoints`:
  - The query var comes through `woocommerce_get_query_vars`, and the menu item goes after Rewards only while `ReferralSettings::get()['enabled']`.
  - It has its own title and render, and its own flush stamp.
  - Template `includes/Pro/Storefront/templates/account-referrals.php` builds UX-2 "My Account → Referrals":
    - the offer sentence variants from UX-2 Copy (with or without hold or minimum), with money through `wc_price` from base via `CurrencyRate::from_base()` and points through `SettingsHelper::points_text()`
    - the link input (`dir="ltr"`, read-only)
    - the four count tiles in `points-card.css` style
    - the invitations `<table>` in `points-history` style, with masked emails and statuses in the customer's words
    - the empty state and the privacy note
  - The copy script uses `navigator.clipboard.writeText()` and falls back to selecting the text. "Copied" sits in an `aria-live="polite"` span for 2 seconds.
  - Below 600px, CSS gives the 2×2 counts and stacked rows.
  - Everything is escaped late (`esc_html`, `esc_attr`, `esc_url`).
- **Ways to earn row (Pro, T3):** `ReferralEarnRow` on `yayloyalty_launcher_earn_rows` shows "Refer a friend · When a friend you referred completes their first order." with the advocate's points and a link to `AccountReferrals::url()`, only while the program is enabled. The row's icon is `AccountMyPointsIcon`'s existing two-person `join` icon, mapped to `CampaignTrigger::REFERRAL` in `for_trigger()`.
- **Editions:** everything is in `includes/Pro/`, except the Lite repository method and the icon.

## Tasks
- [x] T1 The advocate's figures for the tab: `ReferralListRepository::counts_for_advocate()` gains the points on hold and the next release, and the invitations page is P-5's `ReferralListRepository::paginate()` with the advocate filter (no new query) — `includes/Pro/Repositories/ReferralListRepository.php`, `includes/Pro/Models/Referral.php` [risk: 3]
  - Reuse: `AbstractRepository` query helpers, `Utils\Table::name()`; `CustomerRepository::assign_referral_code()` and `ReferralListRepository::counts_for_advocate()` (P-5 T5)
  - Callers: `ReferralListRepository::paginate()`, `counts_for_advocate()`, `assign_referral_code()` → `AccountReferrals` (T2); `Referral::get_hold_until()` → `counts_for_advocate()`
- [x] T2 My Account Referrals tab: endpoint, template, copy script and styles — `includes/Pro/Storefront/AccountReferrals.php` (new), `includes/Pro/Storefront/templates/account-referrals.php` (new), `includes/Pro/Storefront/assets/account-referrals.css` (new), `includes/Pro/Storefront/assets/referral-link.js` (new), `includes/Pro/Storefront/ReferralAccountText.php` (new, the tab's wording), `includes/Pro/YayLoyaltyPro.php`
  - Reuse: `AccountMyPoints` (endpoint, menu, title, flush pattern), `templates/points-history.php` and `points-card.css` (markup and look), the `.yayloyalty-account__section` classes in `assets/storefront/account/account-rewards.css`, `Launcher::asset_version()`, `CurrencyRate::from_base()`, `SettingsHelper::points_text()`, `Dates::to_site_date()`, `ReferralSettings::get()` (P-3), `ReferralStatus` (P-3); design UX-2 "My Account → Referrals"
  - Callers: `AccountReferrals::register()` → `YayLoyaltyPro::boot()`; `add_query_var()` → filter `woocommerce_get_query_vars`; `add_menu_item()` → filter `woocommerce_account_menu_items`; `render()` → action `woocommerce_account_referrals_endpoint`; `maybe_flush_rewrite_rules()` → action `init`; `enqueue()` → action `wp_enqueue_scripts`; `AccountReferrals::url()` → `ReferralEarnRow` (T3)
- [x] T3 "Refer a friend" row in Ways to earn — `includes/Pro/Referrals/ReferralEarnRow.php` (new), `includes/Engine/Storefront/AccountMyPointsIcon.php`, `includes/Pro/YayLoyaltyPro.php` [risk: 1]
  - Reuse: `BirthdayEarnRow` (`includes/Pro/Birthday/BirthdayEarnRow.php`), `LauncherEarnRules` row shape, `rule-list-item.php`, `ReferralSettings::get()` (P-3); design UX-2 "Ways to earn row"
  - Callers: `ReferralEarnRow::register()` → `YayLoyaltyPro::boot()`; `add_row()` → filter `yayloyalty_launcher_earn_rows`; `AccountMyPointsIcon::for_trigger( REFERRAL )` → the row's icon
- [x] T4 Knowledge docs and readme feature line — `docs/knowledge/architecture.md`, `docs/knowledge/integrations.md`, `docs/knowledge/build-release.md`, `docs/knowledge/e2e.md`, `readme.txt` [risk: 1]
  - Reuse: n/a
- [x] T5 Create the referral code when the customer's row is created (added 2026-11-18 at the developer's request) — `includes/Repositories/CustomerRepository.php`, `includes/Pro/Referrals/ReferralCodeAssigner.php` (new), `includes/Pro/YayLoyaltyPro.php`, `docs/knowledge/architecture.md` [risk: 2]
  - Reuse: `CustomerRepository::assign_referral_code()` (P-5 T5), `ListenerGuard::run()`
  - Callers: `ReferralCodeAssigner::register()` → `YayLoyaltyPro::boot()`; `on_customer_created()` → action `yayloyalty_customer_created` (fired by `ensure_row()` on a new row)

- [x] T6 Unlicensed Pro shows customers nothing of YayLoyalty (added 2026-11-18 at the developer's request) — `includes/Engine/Earning/ProgramGate.php`, `includes/Engine/Storefront/{AccountMyPoints,AccountHistoryAjax,AccountClaimOrder,Placements,Launcher,RedeemCard}.php`, `includes/Helpers/DesignHelper.php`, `includes/Pro/YayLoyaltyPro.php`, `includes/Pro/Storefront/AccountReferrals.php`, `includes/Pro/Referrals/ReferralCoupon.php`, `includes/Pro/Birthday/BirthdayFields.php`, `tests/php/integration/bootstrap.php`, `docs/knowledge/architecture.md` [risk: 2]
  - Reuse: `ProgramGate::program_enabled()` (every storefront surface already asks it), AdminShell `LicenseState::allows_features()`
  - Callers: `ProgramGate::storefront_enabled()` / `storefront_allowed()` → the surfaces listed; `YayLoyaltyPro::storefront_allowed()` → filter `yayloyalty_storefront_allowed`
- [x] T7 Switching the program back on decides a first order that completed while it was off (added 2026-11-19 at the developer's request) — `includes/Pro/Referrals/ReferralLifecycle.php`, `includes/Pro/Referrals/ReferralRunner.php`, `includes/Pro/Repositories/ReferralRepository.php`, `includes/Pro/YayLoyaltyPro.php`, `docs/knowledge/architecture.md` [risk: 2]
  - Reuse: `ReferralLifecycle::qualify()` (P-4), the `yayloyalty_referral_run` batching and `ExpiryRunner::GROUP`
  - Callers: `ReferralLifecycle::settle()` → `ReferralRunner::run()` (`recheck` and `expire` phases); `ReferralRunner::on_settings_updated()` → `update_option_yayloyalty_referral_settings`; `ReferralRepository::pending_after()` → the `recheck` phase

**Tests:** [integration] AccountReferralsTest (`tests/php/integration/Pro/Storefront/AccountReferralsTest.php`) — with the program live, the tab renders the offer from the settings, a `?yl_ref=` link with the customer's lazily assigned code (stable across renders), the counts and the customer-worded statuses, a page past the end shows the last page, and with it off, the menu item and the Ways to earn row are absent; [integration] extend `CustomerRepository` coverage in the same class — two customers never get the same code; [integration] ReferralCodeAssignerTest (`tests/php/integration/Pro/Referrals/ReferralCodeAssignerTest.php`) — a new customer row gets a code at once, whoever creates it, and an existing row without one is left to the lazy path; [integration] StorefrontLicenseTest (`tests/php/integration/Pro/StorefrontLicenseTest.php`) — with no key or a revoked key the gate is closed, My Points and Referrals leave the menu and placements print nothing, while the program setting stays on; an activated key opens it; [integration] ReferralRecheckTest (`tests/php/integration/Pro/Referrals/ReferralRecheckTest.php`) — a first order that completed while the program was off is held or rejected once it is switched on, a lapsed window doesn't expire it meanwhile, and saving other settings queues nothing

**E2E:** referral-account: as a customer, My Account → Referrals shows the offer and link, Copy link shows "Copied" and the clipboard holds the link, and at 375px there's no horizontal scroll (new flow, tag `@P-6 @flow-referral-account`).

## Verify
- `yayh test run integration --id=P-6` → OK (integration)
- `yayh e2e run --grep @P-6` → passed (e2e)
- `curl -s -o /dev/null -w "%{http_code}" "$(wp option get home)/my-account/referrals/"` → 200
- `vendor/bin/phpcs includes/Pro/Storefront includes/Pro/Referrals/ReferralEarnRow.php includes/Repositories/CustomerRepository.php includes/Engine/Storefront/AccountMyPointsIcon.php` → exit 0
- `./scripts/run.sh release lite 1.0.0 --no-prompt` → passed
- `unzip -l release/yayloyalty-lite-1.0.0.zip | grep -i referral` → exit 1
- As a customer with the program live (UX-2 Acceptance):
  - My Account → Referrals shows "Give $10, get 1,000 points", a `?yl_ref=` link, and Copy link → "Copied" (e2e).
  - `wp db query "SELECT referral_code FROM $(wp db prefix)yayloyalty_customers WHERE user_id=<id>"` shows the code in the link, unchanged after a reload (integration).
- After P-4's walk-through, the customer's list shows "On the way · arrives {date}" for the held friend, "Completed" and "+1,000" once rewarded, and "Didn't qualify" for a rejected one. The counts match (integration).
- Ways to earn in the launcher and My Points shows "Refer a friend · 1,000 points", linking to the tab.
- With the program off: the tab is gone from the account menu, `/my-account/referrals/` shows the account dashboard or a 404, and the Ways to earn row is gone (integration).
- At 375px (e2e for the scroll, manual for the look): the counts are two by two, the rows are stacked, Copy link is full width, and the link input stays LTR in an RTL locale.

## Risks
- Decided (T7): switching the program on queues one async `recheck` run that settles every pending referral from the friend's first live order in its window; while the program is off, the daily expiry leaves a referral with such an order pending instead of expiring it. A partial refund during the hold still rejects the referral, as the developer confirmed (2026-11-19).
- Decided (T6): the license gates what customers see, not what the program does: points are still earned, emails still sent and the admin works, so activating a key later loses nothing (BL-15). The check is made at render time, not by skipping registration, so the `my-points` and `referrals` rewrite rules survive while unlicensed. An expired key keeps the storefront, as AdminShell's policy says (only a missing or revoked key blocks). The gate is a Lite filter, `yayloyalty_storefront_allowed`, because Lite never has a key and must not name the license.
- Decided (T5): `ensure_row()` fires `yayloyalty_customer_created` only when its insert added a row (1 affected, not the 0 of the no-op update), and Pro's `ReferralCodeAssigner` assigns the code then. The code isn't written in the INSERT itself: `ON DUPLICATE KEY UPDATE` also matches the `referral_code` unique key, so a colliding code would silently skip the new customer's row. Lite fires the action but makes no code. Customers whose rows predate this keep getting their code on first view (no backfill). A failure is logged, never rethrown, because the row may be created inside a ledger write.
- Decided (whole-branch review): the tab renders through `OutputCapture`, so a failure while creating the code shows "Referrals are currently unavailable" instead of breaking the account page; the rewrite flush also runs when the `referrals` rule is missing (a permalink save while Pro was off keeps the stamp); a page past the end shows the last page, and the empty state reads the total; a guest on the tab's URL gets WooCommerce's login, not a redirect. Answered: the slug stays `referrals` as UX-2 names it; a plugin registering the same My Account endpoint would collide, which no referral plugin in this store uses.
- Decided (T3): no new "users" SVG: the existing `join` icon (two people) serves the referral row. A guest sees the row without a link; a customer who can use the tab gets the link.
- Decided (T2): the tab shows only to a customer who can earn (`Launcher::viewer_can_earn()`: not opted out, not an excluded role), since their payouts would never pay; with the program off or for such a customer, `/my-account/referrals/` redirects to the account dashboard. The "worth" figures come from `LauncherPayload::worth()` with the live flexible discount, and are left out without one. The invitations pager is a short Previous/Next with the page number, since the history's pager builds My Points URLs. The offer and statuses are worded in `ReferralAccountText`, so the template stays markup.
- Decided (T1 review): `counts_for_advocate()` also returns `ordered` (every referral with a first order, so a refund never lowers it), and `next_release` is the earliest hold end still ahead, null when every held referral is past due; such a row reads "On the way · arriving soon". "On the way" and "Points earned" sum the snapshots, which is what `award_flat()` pays, since it skips the earning-limit filter.
- **The endpoint 404s until the rewrite rules flush:** the Pro stamp flushes once on `init`, and saving permalinks fixes any leftover. Rollback: delete `yayloyalty_pro_rewrite_version`.
- **A code collision under concurrency:** the unique index and a retry stop two customers sharing a code.
- **Friends' emails shown to the advocate:** masked (`p•••@example.com`), as UX-2 and its privacy note say.
