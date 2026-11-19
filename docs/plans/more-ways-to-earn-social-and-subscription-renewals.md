---
id: P-7
title: More ways to earn: social and subscription renewals
status: in-progress
from: BS-4
roadmap: R-5
design:
trello:
type: feat
branch: feat/p-7-more-ways-to-earn-social-and-subscription-rene
pr:
editions: [pro, lite]
created: 2026-11-19
---
# P-7 More ways to earn: social and subscription renewals

## Goal
A Pro merchant can create two new campaign kinds. **Social** is "Follow us on Instagram" or "Share on Facebook", one network action per campaign. **Subscription renewal** pays flat points for each WooCommerce Subscriptions renewal order. The renewal kind is offered only while WooCommerce Subscriptions is active. Customers see each live social campaign in Ways to earn (My Points, launcher, shortcode/block). Clicking it pays the points once, ever, and sends them to the network. Done means the R-5 acceptance:
- a merchant turns on social and renewal campaigns
- each customer earns once per action: once per social campaign, once per renewal order
- a refunded renewal takes its points back, in full for a full refund and pro rata for a partial one

## Non-goals
- Verifying that a follow or share really happened (honor system, as BS-4 decided).
- Awarding on `processing`: renewals earn on `completed`, like every order today (BS-4 open question; a separate roadmap item if wanted).
- A "Renew your subscription" row in Ways to earn, per-network icons, opening the network in a new tab.
- Other actions from the roadmap note (newsletter, anniversary, custom links).
- BL-6 (first_order / order_count / spend_milestone handlers): untouched.

## Findings
1. **One campaign = one social action.** The engine picks the first eligible campaign per trigger (`includes/Engine/Points/PointsEngine.php:45-70`), and an earn key's qualifier is the trigger (`includes/Engine/Earning/IdempotencyKey.php:77`). A list of actions inside one campaign would need a new key shape. So each social campaign holds one `network` + `action` + `url`, and its key is `earn:<user>:social:<campaign id>:social`. BS-4's "list of actions" shape is replaced by this, with the same customer-visible behaviour.
2. **`award_flat()` skips the daily earning limit** ("the award filter and pending points do not", `includes/Engine/Earning/EarningService.php:318-322`). BS-4 S-A named it, but social points go through `award()` with a TriggerHandler instead, so `DailyEarningLimit`, campaign conditions and campaign performance all apply.
3. **`ReferenceType` has no `social` value** (`includes/Enums/ReferenceType.php:41-51`). `IdempotencyKey::compose()` rejects unknown types (`:41`). T1 adds it to Lite's list (hard rule 6: a downgraded site still reads the rows).
4. **No config spec type checks a URL** (`includes/Utils/ConfigSpec.php:14-60`: bool, string, enum, int, number, int_list, …). T1 adds `url` (http/https only), so a `javascript:` link can't be stored and printed in Ways to earn.
5. **Renewal refunds need no new code.** A renewal row written by `award()` carries the order id (`EarningService::draft_from()`, `:409-433`), and `Reversal::refund()` / `full_order()` claw back every earn row of the order (`includes/Engine/Earning/Reversal.php:49-80`). `BaseCalculator::includes_line()` reads only purchase exclusions (`includes/Engine/Points/BaseCalculator.php:44-52`), so a renewal row's partial refund is pro rata over all lines.
6. **Renewal orders already earn purchase points**: `OrderListener::on_awardable()` loops `yayloyalty_order_award_triggers`, default `[purchase]` (`includes/Engine/Earning/Listeners/OrderListener.php:160-178`). Pro appends `renewal` there. A campaign's "Instead of order points" switch removes `purchase` for that order, decided by evaluating the renewal campaign that would pay, so a campaign whose conditions don't match never takes order points away.
7. **Ways to earn has one renderer for every surface**: `LauncherEarnRules::member_rows()` → `yayloyalty_launcher_earn_rows` → `templates/rule-list-item.php` (My Points, launcher, shortcode, block, page builders). A row's `url` is a plain link. So the social row links to a claim URL (`?yl_social=<id>&_wpnonce=…`) handled on `template_redirect`, the way `AccountHistoryCsv::maybe_send()` works (`includes/Engine/Storefront/AccountHistoryCsv.php:26`). It awards, then redirects to the network. No storefront JS. WordPress core's speculative loading excludes URLs with a query string, so the link is never prefetched.
8. **The SPA lists Pro triggers by hand**: `PRO_CAMPAIGN_TRIGGERS`, `CREATABLE_CAMPAIGN_TRIGGERS` (`apps/admin/src/lib/schema/campaign.schema.ts:18,27`), the template cards (`apps/admin/src/pro/campaigns/add/campaign-templates.ts`), and the editor's field switch (`apps/admin/src/pro/campaigns/editor/CampaignEditorEarn.tsx:21-36`, already 236 lines). New editors go in their own files, like `CampaignEditorEarnPurchase.tsx`.
- differs: `docs/knowledge/architecture.md` (Earning: "Pro adds birthday, first_order…") and `docs/knowledge/integrations.md` (no WooCommerce Subscriptions entry) → T7 updates both.

## Approach
- **Lite (seams only):** `ReferenceType::SOCIAL` and a `url` config spec type. Lite already recognises `social`/`renewal` triggers and sources (`CampaignTrigger::all_known()`, `LedgerSource`).
- **Renewal (Pro, `includes/Pro/Renewals/`):**
  - `RenewalHandler` is a TriggerHandler: flat `points`, reference `order:<id>`, source `renewal`. It's eligible only when the context's order is a renewal (`wcs_order_contains_renewal()`).
  - `RenewalTriggers` filters `yayloyalty_order_award_triggers`: for a renewal order it appends `renewal`, and drops `purchase` when the campaign that would pay has `replace_purchase: true`.
  - The trigger, config and handler are registered only when `function_exists( 'wcs_order_contains_renewal' )`. With WCS deactivated, stored renewal campaigns sit inert, like Pro values on Lite.
  - Config: `points` (int ≥ 0), `replace_purchase` (bool, default false: on top of order points).
- **Social (Pro, `includes/Pro/Social/`):**
  - `SocialHandler` is a TriggerHandler: flat `points`, reference `social:<campaign id>`. It's eligible only for the campaign named in the context extra `social_campaign_id`.
  - `SocialEarnRow` adds one row per live social campaign:
    - signed-in customer who hasn't earned yet: the claim link
    - customer who already earned it (the ledger already has that row): "Earned", with a plain link
    - guest: a plain link, "Sign in to earn"
  - `SocialClaim` on `template_redirect` checks, in order: signed-in, nonce `yayloyalty_social_<id>`, live social campaign, `ProgramGate::storefront_enabled()`. Then it calls `EarningService::build()->award( social, from_user(…) )` and `wp_redirect()`s to the campaign's stored URL. A bad nonce or a refused award still redirects, without points. An unknown campaign goes to the home page.
  - Config: `network` (enum facebook, instagram, x, tiktok, youtube, linkedin, pinterest), `action` (enum follow, share), `url` (url), `points` (int ≥ 0). Row label: "Follow us on {Network}" / "Share on {Network}".
- **Admin SPA (Pro, `src/pro/`):** two new template cards and editors in the existing campaign editor. The renewal card shows only when localized `has_subscriptions` is true, set by Pro through `yayloyalty_localize_admin_data`. The social editor has a "Use a share link for my store" button that fills the URL for facebook, x, linkedin and pinterest. No new screen, so no design file: both are new kinds in the existing editor and rows in the existing list.
- **Compatibility:** PHP 7.4. HPOS-safe (orders through `wc_get_order`). No storage change. Lite builds ship no Pro strings (templates stay behind `getCampaignTemplates()`, Rollup note there).

## Tasks
- [x] T1 Lite seams: `ReferenceType::SOCIAL` and the `url` config spec type — `includes/Enums/ReferenceType.php`, `includes/Utils/ConfigSpec.php`, `tests/php/unit/Utils/ConfigSpecTest.php` (new)
  - Reuse: `StringEnumMethods` list in `ReferenceType::all()`; `ConfigSpec::matches()` switch; `wp_http_validate_url()`-free check (`filter_var` + `^https?://`) since ConfigSpec runs in unit tests without WordPress
  - Callers: `ReferenceType::SOCIAL` → `SocialHandler::reference_type()` (T4); `url` spec → `YayLoyaltyPro::add_campaign_config_schema()` (T5)
- [x] T2 Renewal handler and award triggers — `includes/Pro/Renewals/RenewalHandler.php` (new), `includes/Pro/Renewals/RenewalTriggers.php` (new)
  - Reuse: `TriggerHandler` (`includes/Engine/Points/Triggers/TriggerHandler.php`), `Breakdown::flat()` and `Campaign::flat_points()` as in `Pro/Birthday/BirthdayHandler.php`; `PointsEngine::build()->evaluate()` as `BirthdayEarnRow` does; `ContextFactory::from_order()`; filter `yayloyalty_order_award_triggers`
  - Callers: `RenewalHandler` → `YayLoyaltyPro::register_trigger_handlers()` (T5); `RenewalTriggers::filter()` → filter `yayloyalty_order_award_triggers` via `RenewalTriggers::register()` (T5) [risk: 3]
- [ ] T3 Renewal tests: earns once per renewal order, on top of or instead of purchase, full and partial refund claw back, non-renewal order untouched — `tests/php/integration/Pro/Renewals/RenewalEarningTest.php` (new)
  - Reuse: order and campaign setup as in `tests/php/integration/Engine/Earning/AwardFlatTest.php`; `CampaignRepository`, `LedgerRepository::earn_rows_for_order()`; `wc_create_refund()`
  - Callers: n/a (test)
- [x] T4 Social handler, claim link and Ways to earn row — `includes/Pro/Social/SocialHandler.php` (new), `includes/Pro/Social/SocialClaim.php` (new), `includes/Pro/Social/SocialEarnRow.php` (new), `tests/php/integration/Pro/Social/SocialClaimTest.php` (new)
  - Reuse: `TriggerHandler`; `EarningService::build()->award()`; `ContextFactory::from_user()`; `IdempotencyKey::for_earn()` + `LedgerRepository::find_by_idempotency_key()` for the claimed state (as `BirthdayRunner::award()`); `CampaignRepository::live_for_trigger()`; `ProgramGate::storefront_enabled()`; `LauncherCopy::points_gain()`; filter `yayloyalty_launcher_earn_rows` as `Pro/Referrals/ReferralEarnRow.php`; `template_redirect` + nonce pattern of `AccountHistoryCsv::maybe_send()`
  - Callers: `SocialHandler` → `register_trigger_handlers()` (T5); `SocialClaim::maybe_claim()` → action `template_redirect`, and `SocialClaim::url()` → `SocialEarnRow::add_row()`; `SocialEarnRow::add_row()` → filter `yayloyalty_launcher_earn_rows`; all registered in T5 [risk: 3]
- [x] T5 Pro wiring: offer `social` always and `renewal` only with WCS, config keys and typed schema, handlers, the localized `has_subscriptions` flag — `includes/Pro/YayLoyaltyPro.php`
  - Reuse: `add_triggers()`, `add_config_keys()`, `add_campaign_config_schema()`, `register_trigger_handlers()`, the `yayloyalty_localize_admin_data` callback beside `add_segments()`; Pro keeps hooking before `EarningService::build()` (hard rule 4)
  - Callers: `YayLoyaltyPro::has_subscriptions()` → `add_triggers()`, `register_trigger_handlers()`, the localize callback
- [ ] T6 Admin SPA: triggers, template cards, labels, and the Social and Renewal editors with the share-link helper — `apps/admin/src/lib/schema/campaign.schema.ts`, `apps/admin/src/types/localize.type.ts`, `apps/admin/src/pro/campaigns/add/campaign-templates.ts`, `apps/admin/src/pro/campaigns/campaign-labels.ts`, `apps/admin/src/pro/campaigns/editor/CampaignEditorEarn.tsx`, `apps/admin/src/pro/campaigns/editor/CampaignEditorEarnSocial.tsx` (new), `apps/admin/src/pro/campaigns/editor/CampaignEditorEarnRenewal.tsx` (new), `apps/admin/src/pro/campaigns/editor/social-share-url.ts` (new), `tests/js/pro/campaigns/social-share-url.test.ts` (new)
  - Reuse: `EarnFieldsBirthday` field pattern (`NumberInputRoot`, `Switch`, `Field*`), `emptySeed()`, `givesLabel()` flat-points branch, `triggerGroup()` 'other'; the `window.yayLoyaltyAdmin?.segments` optional-Pro-field pattern
  - Callers: `CampaignEditorEarnSocial`, `CampaignEditorEarnRenewal` → `CampaignEditorEarn` switch; `socialShareUrl()` → `CampaignEditorEarnSocial`; `has_subscriptions` → `getCampaignTemplates()`
- [ ] T7 Knowledge: Pro triggers and the social claim link in architecture, WooCommerce Subscriptions in integrations — `docs/knowledge/architecture.md`, `docs/knowledge/integrations.md` [risk: 1]
  - Reuse: n/a
- [ ] T8 E2E: create a social campaign, save, reload, the fields persist — `tests/e2e/specs/campaign-social.spec.ts` (new), `docs/knowledge/e2e.md` (Flows row)
  - Reuse: admin login and SPA navigation helpers of `tests/e2e/specs/referral-settings.spec.ts`
  - Callers: n/a (test)

**Tests:** [unit] ConfigSpecTest::url_accepts_http_and_https_only (tests/php/unit/Utils/ConfigSpecTest.php) — a `javascript:` or relative link can't be stored; [integration] RenewalEarningTest (tests/php/integration/Pro/Renewals/RenewalEarningTest.php) — once per renewal order, replace_purchase drops order points only when that campaign pays, full and partial refunds claw back, non-renewal orders earn as before; [integration] SocialClaimTest (tests/php/integration/Pro/Social/SocialClaimTest.php) — claim pays once (second click DUPLICATE), bad nonce, guest, opted-out and paused campaign pay nothing, redirect goes to the stored URL, the daily limit clamps it; [js] socialShareUrl (tests/js/pro/campaigns/social-share-url.test.ts) — share links per network, null for follow-only networks

**E2E:** Campaign create, edit, pause: a Social campaign saves and reloads with its network, action, URL and points

## Verify
- `yayh test run unit --id=P-7` → exit 0 (unit)
- `yayh test run integration --id=P-7` → exit 0 (integration)
- `yayh test run js --id=P-7` → exit 0 (js)
- `pnpm --filter @yayloyalty/admin-page exec tsc --noEmit` → exit 0
- `pnpm --filter @yayloyalty/admin-page lint` → exit 0
- `./scripts/run.sh build lite` → exit 0
- `./scripts/run.sh build pro` → exit 0
- After the lite build, `grep -rl "share link for my store" assets/dist/admin/js` → exit 1 (no Pro copy in Lite)
- `wp eval 'echo in_array("social", \YayLoyalty\Enums\CampaignTrigger::all(), true) ? "yes" : "no";'` → yes
- With WooCommerce Subscriptions inactive: `wp eval 'echo in_array("renewal", \YayLoyalty\Enums\CampaignTrigger::all(), true) ? "yes" : "no";'` → no
- With WooCommerce Subscriptions active: YayLoyalty → Campaigns → Add shows "Subscription renewal". Create one (50 points, on top of order points), complete a renewal order, and the customer's ledger shows a `renewal` row of 50 next to the order's purchase row (`wp db query "SELECT type,source,points FROM wp_yayloyalty_ledger WHERE order_id=<id>"`). Refund the order in full, and a `refund_earn` row of -50 appears (integration).
- Pro: create a Social campaign (Instagram, follow, `https://instagram.com/<shop>`, 20 points). My Points → Ways to earn shows "Follow us on Instagram +20". Click it, and the browser lands on Instagram. Back in My Points the row says "Earned" and the balance is +20. Clicking again adds nothing (integration).
- Pro: as a guest the same row links straight to Instagram and says to sign in. With the program paused the row isn't shown.
- Lite (built zip): the Campaigns list still renders a stored social campaign (created in Pro, then downgraded) without an error, and offers no Social or Renewal card.
- `yayh code unused` → reports none of the symbols this plan adds

## Risks
- **Honor-system farming:** bounded by one payment per campaign per customer, and by the daily limit. Rollback: pause the campaign.
- **Removing order points by mistake** (`replace_purchase`): decided per order from the campaign that actually pays (Finding 6). Rollback: switch it off, and later renewals earn order points again. Past orders aren't re-awarded.
- **Redirect to a stored URL:** only http/https (T1), only for a live social campaign by id, never a URL from the request, so there's no open redirect.
- **WCS deactivated later:** stored renewal campaigns stop being offered and fire nothing. Rows already earned keep their refund clawback through the order id.
- Open: renewals that stay `processing` never earn (Non-goal; BS-4 open question).
