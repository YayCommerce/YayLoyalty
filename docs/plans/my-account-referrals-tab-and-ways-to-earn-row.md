---
id: P-6
title: My Account Referrals tab and Ways to earn row
status: in-progress
from: BS-3
roadmap: R-4
design: UX-2
trello:
type: feat
branch: feat/p-6-my-account-referrals-tab-and-ways-to-earn-row
pr:
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
- **Ways to earn row (Pro, T3):** `ReferralEarnRow` on `yayloyalty_launcher_earn_rows` shows "Refer a friend · When a friend you referred completes their first order." with the advocate's points and a link to `AccountReferrals::url()`, only while the program is enabled. The "users" icon is added to `AccountMyPointsIcon`.
- **Editions:** everything is in `includes/Pro/`, except the Lite repository method and the icon.

## Tasks
- [x] T1 The advocate's figures for the tab: `ReferralListRepository::counts_for_advocate()` gains the points on hold and the next release, and the invitations page is P-5's `ReferralListRepository::paginate()` with the advocate filter (no new query) — `includes/Pro/Repositories/ReferralListRepository.php`, `includes/Pro/Models/Referral.php` [risk: 3]
  - Reuse: `AbstractRepository` query helpers, `Utils\Table::name()`; `CustomerRepository::assign_referral_code()` and `ReferralListRepository::counts_for_advocate()` (P-5 T5)
  - Callers: `ReferralListRepository::paginate()`, `counts_for_advocate()`, `assign_referral_code()` → `AccountReferrals` (T2); `Referral::get_hold_until()` → `counts_for_advocate()`
- [ ] T2 My Account Referrals tab: endpoint, template, copy script and styles — `includes/Pro/Storefront/AccountReferrals.php` (new), `includes/Pro/Storefront/templates/account-referrals.php` (new), `includes/Pro/Storefront/assets/account-referrals.css` (new), `includes/Pro/Storefront/assets/referral-link.js` (new), `includes/Pro/YayLoyaltyPro.php`
  - Reuse: `AccountMyPoints` (endpoint, menu, title, flush pattern), `templates/points-history.php` and `points-card.css` (markup and look), the `.yayloyalty-account__section` classes in `assets/storefront/account/account-rewards.css`, `Launcher::asset_version()`, `CurrencyRate::from_base()`, `SettingsHelper::points_text()`, `Dates::to_site_date()`, `ReferralSettings::get()` (P-3), `ReferralStatus` (P-3); design UX-2 "My Account → Referrals"
  - Callers: `AccountReferrals::register()` → `YayLoyaltyPro::boot()`; `add_query_var()` → filter `woocommerce_get_query_vars`; `add_menu_item()` → filter `woocommerce_account_menu_items`; `render()` → action `woocommerce_account_referrals_endpoint`; `maybe_flush_rewrite_rules()` → action `init`; `enqueue()` → action `wp_enqueue_scripts`; `AccountReferrals::url()` → `ReferralEarnRow` (T3)
- [ ] T3 "Refer a friend" row in Ways to earn — `includes/Pro/Referrals/ReferralEarnRow.php` (new), `includes/Engine/Storefront/AccountMyPointsIcon.php`, `includes/Pro/YayLoyaltyPro.php` [risk: 1]
  - Reuse: `BirthdayEarnRow` (`includes/Pro/Birthday/BirthdayEarnRow.php`), `LauncherEarnRules` row shape, `rule-list-item.php`, `ReferralSettings::get()` (P-3); design UX-2 "Ways to earn row"
  - Callers: `ReferralEarnRow::register()` → `YayLoyaltyPro::boot()`; `add_row()` → filter `yayloyalty_launcher_earn_rows`; `AccountMyPointsIcon` "users" → `ReferralEarnRow`
- [ ] T4 Knowledge docs and readme feature line — `docs/knowledge/architecture.md`, `docs/knowledge/integrations.md`, `docs/knowledge/build-release.md`, `docs/knowledge/e2e.md`, `readme.txt` [risk: 1]
  - Reuse: n/a

**Tests:** [integration] AccountReferralsTest (`tests/php/integration/Pro/Storefront/AccountReferralsTest.php`) — with the program live, the tab renders the offer from the settings, a `?yl_ref=` link with the customer's lazily assigned code (stable across renders), the counts and the customer-worded statuses, and with it off, the menu item and the Ways to earn row are absent; [integration] extend `CustomerRepository` coverage in the same class — two customers never get the same code

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
- Decided (T1 review): `counts_for_advocate()` also returns `ordered` (every referral with a first order, so a refund never lowers it), and `next_release` is the earliest hold end still ahead, null when every held referral is past due; such a row reads "On the way · arriving soon". "On the way" and "Points earned" sum the snapshots, which is what `award_flat()` pays, since it skips the earning-limit filter.
- **The endpoint 404s until the rewrite rules flush:** the Pro stamp flushes once on `init`, and saving permalinks fixes any leftover. Rollback: delete `yayloyalty_pro_rewrite_version`.
- **A code collision under concurrency:** the unique index and a retry stop two customers sharing a code.
- **Friends' emails shown to the advocate:** masked (`p•••@example.com`), as UX-2 and its privacy note say.
