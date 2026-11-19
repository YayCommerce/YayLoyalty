---
area: architecture
updated: 2026-11-19
updated_ref: 8ea5d2e
covers: [yayloyalty.php, YayLoyaltyPluginAdapter.php, includes/YayLoyalty.php, includes/Access/, includes/Database/, includes/Models/, includes/Repositories/, includes/Services/, includes/Controllers/, includes/Engine/, includes/Enums/, includes/Pro/YayLoyaltyPro.php, includes/Pro/Referrals/, includes/Pro/Repositories/ReferralRepository.php]
---

# Architecture

> Update when: the boot order, a table, option or meta key, a REST route, a writer of ledger or customer rows, or a Pro seam changes.

## Boot

`yayloyalty.php` defines the `YAYLOYALTY_*` constants, registers an autoloader for `YayLoyalty\` → `includes/`, loads `vendor/autoload.php` and the un-namespaced `YayLoyaltyPluginAdapter.php`. Then, on `plugins_loaded`:

| Priority | What | Where |
|---|---|---|
| 5 | Scoped `AdminShell::boot()` + `register_plugin(new YayLoyaltyPluginAdapter())`: the shared YayCommerce menu, licence screens, version election between Yay plugins | `yayloyalty.php` |
| 10 | `plugin_init`: bail with a notice if `WC()` is missing; hook `ActDeact::before_woocommerce_init` (HPOS declaration); `YayLoyalty::initialize()`; textdomain | `yayloyalty.php` |
| 20 | `Installer::maybe_upgrade` and `Capabilities::migrate`, outside the WC guard so REST, cron and WP-CLI upgrade too | `yayloyalty.php` |

A second copy of the plugin (both editions active) defines `plugin_init` first; the later one loads `includes/Fallback.php` and deactivates itself on `admin_init`.

`YayLoyalty::__construct` (`includes/YayLoyalty.php`) is the composition root, in this order:
1. `RestAPI`, `RegisterFacade`, `Admin\Settings`, `Emails`, the YayMail, YayCurrency and YayPricing integrations.
2. `if ( self::is_pro() ) YayLoyaltyPro::boot();` — the only place Lite names Pro. `is_pro()` is `class_exists`, not a licence check.
3. `SessionIntent`, `RedemptionService::build()`, the earning listeners, `ExpiryRunner`, storefront placements and My Account.
4. Admin only: `ReviewPointsNote`, `OrderCouponRemoval`, `OrderMissingPoints`. Front end only: `Launcher`.

**Order is load-bearing.** `RedemptionService::build()` applies `yayloyalty_redemption_gates` and `yayloyalty_register_reward_handlers` once, and `EarningService::build()` applies `yayloyalty_earning_gates` once. A Pro hook attached after them is never seen. The redemption adapters and `CouponProvider` are not behind `is_admin()` because Store API requests aren't admin requests.

## Layers and who writes what

| Layer | Rule | Example |
|---|---|---|
| `Models/` | Typed rows (`from_row`, `to_row`, `to_array` in snake_case). Never touch the DB. Money is a string. | `includes/Models/AbstractModel.php` |
| `Repositories/` | All SQL for exactly one table, via `$wpdb`; throw `QueryException`; never open a transaction. Ledger and customer meta repos are append-only. | `includes/Repositories/AbstractRepository.php` |
| `Services/` | Cross-table invariants. | `includes/Services/LedgerService.php` |
| `Controllers/` | REST, `yayloyalty/v1`. | `includes/Controllers/BaseRestController.php` |
| `Engine/` | WooCommerce-facing behaviour: earning, redemption, expiry, emails, storefront, admin order screens. | `includes/Engine/` |

- **`LedgerService` is the only writer of ledger rows, balances, lots and lot allocations** (lots through `LotService`, which only it constructs). It is constructed in exactly five places, listed in its docblock: `Engine/RestAPI.php`, `Engine/Earning/EarningService.php`, `Engine/Earning/Reversal.php`, `Engine/Expiry/ExpiryRunner.php`, `Engine/Redemption/RedemptionService.php`. It fires `yayloyalty_ledger_recorded` after each row.
- Other `customers` columns are written outside it on purpose: pending points (`PendingPoints` → `CustomerRepository::apply_pending_delta`), expiry clearing (`ExpiryRunner` → `clear_expiry`), birthdays (Pro `BirthdayFields` → `set_birthday`), and row creation on `user_register` (`CustomerActivityListener`). Never the balance.
- `CustomerReader` is the read-only interface handed to code that must not write (the redemption quote path).
- `Database\Transaction::run()` is the only place that opens, commits or rolls back a transaction; it nests with savepoints, detects a foreign (WooCommerce) transaction and offers `after_commit()`. Emails and `yayloyalty_points_awarded` fire after commit.
- Ledger idempotency: every system row carries an `idempotency_key` built by `Engine\Earning\IdempotencyKey` (`{type}:{user}:{reftype}:{refid}:{qualifier}`, max 96 chars) behind a UNIQUE index. A repeat is `Outcome::DUPLICATE`: nothing written, nothing thrown.

## Storage

**Tables** (`{$wpdb->prefix}yayloyalty_<slug>`, names from `Utils\Table::name()`; schemas in `includes/Database/Schema/`):

| Slug | Holds |
|---|---|
| `customers` | Per-user aggregates: balance, lifetime, pending, `expires_at`, birthday |
| `ledger` | Append-only points events, `balance_after` chain, unique `idempotency_key` |
| `lots`, `lot_allocations` | Expiry batches and what each spend took from them. Invariant: `SUM(open remaining) = max(0, balance)` |
| `campaigns`, `rewards` | Earn rules and redeemable rewards; unique `slug`, `is_builtin`. `conditions` is one list with a match, all or any (`Utils/ConditionMatch.php`): an `all` list, or none, is stored in the 1.0.0 `{"schema_version":1,"items":[…]}` list envelope, an `any` list in the same envelope plus `"match":"any"` at `schema_version` 2. The models and REST read either shape and always serve `{ match, conditions }` |
| `customer_meta` | Append-only activity timeline (`meta_key` is the event type) |
| `redemptions` | One row per applied reward; `value`/`base_value` decimal(19,4), `rate_snapshot` decimal(19,10). Only `Engine\Redemption\CommitWriter` inserts |
| `referrals` (Pro only) | One row per referred friend: advocate, friend (unique), the code used, `status` (`Pro/Enums/ReferralStatus.php`), a `terms` JSON snapshot of the settings at signup, `coupon_expires_at`, `first_order_id`, `hold_until`. Schema `Pro/Database/ReferralsTable.php`, SQL `Pro/Repositories/ReferralRepository.php` |

**Upgrades.** `Installer::maybe_upgrade()` runs `install()` whenever option `yayloyalty_db_version` !== `YAYLOYALTY_VERSION`, under an `add_option` mutex (`yayloyalty_upgrading`, reclaimed after 60 s). `install()` = `dbDelta` per schema → seed built-in campaigns and the "Points discount" reward → `Capabilities::install()` → `run_migrations()` → `verify()` (logs columns and indexes dbDelta skipped) → stamp the version last. There is no per-migration version, so every migration must be idempotent. `dbDelta` never drops, so dropped indexes are removed in `run_migrations()`.

**Options:** `yayloyalty_settings` (`SettingsHelper`), `yayloyalty_design` (`DesignHelper`), `yayloyalty_reviewed`, `yayloyalty_setup_completed`, `yayloyalty_db_version`, `yayloyalty_caps_version`, `yayloyalty_expiry_applied_signature`, `yayloyalty_rewrite_version`, and Pro's `yayloyalty_customer_segments`, `yayloyalty_referral_settings` (`Pro/Referrals/ReferralSettings.php`) and `yayloyalty_pro_db_version`.

**Pro tables.** Pro adds its schemas through `yayloyalty_table_schemas`, so every Lite version bump installs them too. Activation runs before Pro boots and `maybe_upgrade()` skips while `yayloyalty_db_version` is current, so `Pro/Database/ProInstaller.php` keeps its own stamp, `yayloyalty_pro_db_version`, and runs `Installer::install_locked()` (the same mutex) once per version; it stamps only once the table exists. A site downgraded to Lite keeps the table, unused.

**Order meta:** `_yayloyalty_points_pending`, `_yayloyalty_points_released_at`, `_yayloyalty_points_earned`, `_yayloyalty_campaign_id` (`Engine/Earning/PendingPoints.php`); `_yayloyalty_points_redeemed`, `_yayloyalty_redeem_value` (`CommitWriter`).

**User meta:** `_yayloyalty_opted_out`, `_yayloyalty_email_sends` (`EmailThrottle`), `yayloyalty_dashboard_dismissed`, Pro's `yayloyalty_birthday_month` / `_day`.

**Cookies:** Pro's `yayloyalty_ref` (`CODE.<visit unix time>` from a `?yl_ref=` link, so signup is checked against the current cookie window; HttpOnly, SameSite=Lax, cleared at signup) — `Pro/Referrals/ReferralAttribution.php`.

**Transients:** `yayloyalty_dashboard_v2_<range>` (5 min, only above 10,000 members; flushed on every ledger and campaign write; bump the key when the payload shape changes) — `Services/Dashboard/DashboardCache.php`.

**WC session:** `yayloyalty_redeem_points`, `yayloyalty_redeem_rewards`, `yayloyalty_redeem_dropped` (`Engine/Redemption/SessionIntent.php`).

## REST

Namespace `yayloyalty/v1`. Every route's `permission_callback` is `$this->requires( Capabilities::X )` (`Controllers/AbstractRestController.php`), which returns 403 `yayloyalty_forbidden`. Capabilities (`includes/Access/Capabilities.php`): `yayloyalty_view`, `_manage_points`, `_manage_campaigns`, `_manage_rewards`, `_manage_settings`, `_run_tools`.

| Cap | Routes |
|---|---|
| VIEW | `customers`, `customers/{id}` and its `ledger`, `activity`, `redemptions`; `lookups/*`; `dashboard`, `dashboard/notices/dismiss`; `GET settings`, `design`, `emails`; `mark-reviewed` |
| MANAGE_POINTS | `customers/{id}/adjust`, `customers/adjust/preview`, `orders/{id}/grant-points` |
| MANAGE_CAMPAIGNS | `campaigns`, `campaigns/reorder`, `campaigns/{id}` |
| MANAGE_REWARDS | `rewards`, `rewards/reorder`, `rewards/{id}` |
| MANAGE_SETTINGS | settings, design (`reset`, `preview`), emails writes; `setup`, `setup/dismiss` |
| signed-in shopper | `StorefrontActivityRestController` |

Pro adds controllers through `yayloyalty_rest_controllers` (campaign preview and performance, segments, Pro dashboard, store ledger, `referral/settings`: GET under VIEW, POST under MANAGE_CAMPAIGNS; `referrals` list with search, status and `counts` under VIEW, and `referrals/{id}/approve|reject` under MANAGE_CAMPAIGNS, 409 `yayloyalty_referral_changed` once the referral left `on_hold`; `customers/{id}/referrals` for the drawer under VIEW, which creates a member's missing referral code).

- Writes go through `exec_write()` (inside `Transaction::run`; a returned `WP_Error` rolls back). Any `Throwable` becomes a generic 500 with a 12-char correlation id; the real message goes only to `Log::error` (`BaseRestController.php`).
- Write args come from the item schema minus read-only props; `ALWAYS_READONLY` (id, timestamps, `is_builtin`, `balance_after`, `actor_id`) can't be mass-assigned, even through `yayloyalty_rest_item_schema`.
- Storefront AJAX uses `wc-ajax` endpoints, not `admin-ajax`: `yayloyalty_redeem`, `yayloyalty_points_history`. The only `wp_ajax_` hook is the interception of WooCommerce's `woocommerce_remove_order_coupon`.

## Earning

- `OrderListener` is the only class that reads WooCommerce order statuses. **Only `completed` awards** (`Enums/OrderStatus::AWARD`). `pending` and `on-hold` stage points as order meta; `cancelled`, `trash`, `refunded` reverse.
- Triggers in Lite: purchase, signup (`user_register`, self-registration only), review (`comment_post` priority 20, after WooCommerce writes `verified`). Pro adds birthday, first_order, order_count, spend_milestone via `yayloyalty_register_trigger_handlers`.
- Points = included line totals after all discounts (tax optional) × `to_base_factor` × `points_per_unit`, rounded once per order by `PointsMath::round_points`. Campaigns are tried in priority order; the first eligible one wins.
- Scoring uses the order's creation date (`Context::occurred_at`), never "now".
- Gates: `ProgramGate` (program paused, no customer, opted out, excluded role) first, then Pro gates. Guests (no user id) never earn at completion; a signed-in shopper can claim a guest order placed with their email from My Points (`Storefront/AccountClaimOrder.php`).
- Every listener body runs inside `ListenerGuard`, so a failure never breaks a WooCommerce save.

## Redemption

Points become a **virtual coupon**, never a `shop_coupon` post or a fee. `CouponProvider` fabricates coupon data on `woocommerce_get_shop_coupon_data` for codes `yayloyalty-xxxxxxxx`, applies them last on `woocommerce_before_calculate_totals` (`PHP_INT_MAX`) and re-derives the amount on every totals run. The only pre-order state is the WC session intent. At checkout `CommitWriter` locks the customer row, re-quotes, writes the `redemptions` row and the REDEEM ledger row (via `LedgerService`) and freezes order meta. A thrown exception aborts checkout before payment.

| Checkout | Adapter | Validate / commit hooks |
|---|---|---|
| Classic | `Redemption/Adapters/ClassicCheckoutAdapter.php` | `woocommerce_after_checkout_validation` / `woocommerce_checkout_order_processed` |
| Block (Store API) | `Redemption/Adapters/StoreApiAdapter.php` + `assets/storefront/redeem-blocks.js` | `woocommerce_store_api_cart_errors` / `woocommerce_store_api_checkout_order_processed` |

Lite has one reward handler, `FlexibleDiscountHandler`; `Rewards/Clamp.php` is the single source of every limit (balance, min, max, cart, step). Pro registers `DiscountRewardHandler` for fixed and percent coupons. A loyalty code can't be hand-applied in wp-admin or REST v3 (`CouponProvider::only_redeemed_on_order`).

## Expiry

Daily Action Scheduler action `yayloyalty_expiry_run` (group `yayloyalty`, batches of 100, async continuation when a batch is full): backfill lots, re-apply changed expiry settings, expire due customers through `LedgerService::expire`, send reminders (claim-then-send, so at most once). Modes: inactivity (`customers.expires_at`) or Pro's batch (per-lot dates). Scheduling is checked only on admin page loads and cron — `Engine/Expiry/ExpiryRunner.php`. Pro's `yayloyalty_birthday_run` and `yayloyalty_referral_run` reuse `ExpiryRunner::register_daily()` / `keep_daily()` and the same group.

## Emails

`WC_Email` subclasses of `Engine/Emails/LoyaltyEmail.php`: points earned, points adjusted, and (only while expiry is on) points expired and expiring soon. Pro adds birthday points and reward claimed. Triggers are custom actions whose only argument is the ledger row id, because deferred WooCommerce emails serialize their arguments. Templates are in `includes/Engine/Emails/templates/emails/` (theme overrides still work). `EmailThrottle` caps sends per customer per week.

## Storefront

- Launcher (floating widget, front end only): `Storefront/Launcher.php`, plain `assets/storefront/launcher.js`, built `assets/dist/storefront/launcher.css`.
- My Account endpoint `my-points` (`Storefront/AccountMyPoints.php`); rewrite rules flush once per `REWRITE_VERSION` bump. Pro's `referrals` endpoint (`Pro/Storefront/AccountReferrals.php`, template and assets under `Pro/Storefront/`) keeps its own stamp, `yayloyalty_pro_rewrite_version`; it shows only to a customer who can earn while the referral program is live, creates their referral code on first view if it's still missing, and redirects to the dashboard otherwise. Pro's `ReferralEarnRow` adds "Refer a friend" to Ways to earn through `yayloyalty_launcher_earn_rows`.
- Shortcodes `[yayloyalty_balance]`, `[yayloyalty_ways_to_earn]`, `[yayloyalty_ways_to_redeem]`, `[yayloyalty_redeem]`; blocks `yayloyalty/points-balance`, `ways-to-earn`, `ways-to-redeem` from `includes/Engine/Storefront/blocks/*/block.json`, editor script `assets/dist/blocks/editor.js`. The four shortcodes are also Elementor, Bricks and Divi 4 elements (`includes/Integrations/PageBuilders/`, see [integrations.md](integrations.md#partner-plugins)).
- Thank-you page and product page earn messages: `ThankYouPoints`, `ProductEarnMessage`.
- **What the storefront may show:** every surface asks `Earning\ProgramGate`: `storefront_enabled()` (the program is on and `storefront_allowed()`) for My Points, placements, the product and thank-you messages (`DesignHelper::surface_enabled()`), the history AJAX and the order claim; `storefront_allowed()` alone for the launcher and the redeem card (classic, blocks and their assets), which already handle a paused program themselves. `storefront_allowed()` is the `yayloyalty_storefront_allowed` filter, default true. Pro's `YayLoyaltyPro::storefront_allowed()` returns false until a license key is activated on the site and not revoked (AdminShell `LicenseState::allows_features('yayloyalty')`; an expired key keeps working), and Pro's own surfaces check the same gate: the Referrals tab, the friend's referral coupon (`ReferralCoupon::is_usable()`) and the birthday fields. Earning, emails, the admin and REST are not gated. Integration tests seed a placeholder key in `tests/php/integration/bootstrap.php`, e2e in `tests/e2e/bin/setup-env.sh`.

## Pro and Lite

Lite is the Pro tree with `includes/Pro/` removed at release (see [build-release.md](build-release.md)). `YayLoyaltyPro::boot()` (`includes/Pro/YayLoyaltyPro.php`) attaches everything Pro does through filters and actions Lite exposes: extra triggers, reward types, the `batch` expiry mode, REST controllers, emails, the campaign and reward conditions evaluators, `DailyEarningLimit`, the Rewards panel, the birthday runner and fields. Its only table is `referrals`, installed by `ProInstaller` (see Storage).

- **Referral attribution:** a `?yl_ref=CODE` link sets the `yayloyalty_ref` cookie on `template_redirect` at priority 0, before core's canonical and old-slug redirects drop the query string. A self-registration (`SignupListener::is_self_registration()`) on `user_register` at priority 20, after the member row exists, becomes a `pending` referral while the program is on and the code belongs to an existing user other than the new one. Pro's `ReferralCodeAssigner` gives a member their code when their row is created, on Lite's `yayloyalty_customer_created` (fired by `CustomerRepository::ensure_row()` only for a new row); rows from before that get one on first use. Codes are upper case; `CustomerRepository::find_user_by_referral_code()` joins `users`, because a deleted account's member row outlives it. A full-page cache that serves `?yl_ref=` URLs without running PHP never sets the cookie; exclude the argument from the cache.
- **Referral lifecycle** (`Pro/Referrals/`): `ReferralCoupon` is the friend's virtual coupon `yl-referral-{id}` (terms from the snapshot, in base currency), valid only for the friend while the referral is pending, unexpired and live with no order already paid or on hold; `ReferralCartSync` adds it at `woocommerce_before_calculate_totals` `PHP_INT_MAX - 1` (before `CouponProvider`) when the cart meets the minimum, takes it off silently otherwise, and relabels the block checkout line. On Lite's `yayloyalty_order_awarded`, `ReferralLifecycle` qualifies the friend's first order (`ReferralQualifier`: first order, minimum, other coupons, the advocate's billing email) into `on_hold` or `rejected`. `ReferralPayout::pay()` claims `on_hold → rewarded` and writes the advocate's row through `EarningService::award_flat()` in one transaction; the daily `yayloyalty_referral_run` (`ReferralRunner`) pays released holds and expires lapsed pending referrals, except one whose friend placed a live order in the window (`ReferralLifecycle::settle()` decides it if that order completed). A held referral its advocate can't be paid for (account deleted, or opted out / excluded role for `ReferralPayout::GRACE_DAYS` past the hold) is rejected as `advocate_cannot_earn` by the release phase; `ReferralPayout::rejects_at()` gives that date, which Approvals shows. Block checkout creates the account after the order, so `ReferralCartSync::apply_to_signup_order()` (`woocommerce_store_api_checkout_order_processed`) adds the code to the order of a friend whose referral this request created; classic checkout recalculates the cart for the new customer itself. Switching the program back on (`update_option_yayloyalty_referral_settings`) queues the run's `recheck` phase, which settles every pending referral, so a first order that completed while the program was off is still decided. `yayloyalty_order_reversed` rejects a held referral (`refunded_during_hold`), and a full clawback of a referral row (`yayloyalty_ledger_recorded`) marks it `reversed`. Every status write is conditional on the status it moves from (`ReferralRepository::transition()`).

- **Add a seam, never a strip marker.** Pro widens Lite's lists through filters; Lite's `all_known()` lists stay unfiltered so a site downgraded to Lite still recognises stored Pro values (`Enums/CampaignTrigger.php`, `Enums/RewardType.php`).
- Lite fails open without a conditions evaluator: a conditional campaign applies to everyone after a downgrade (`Engine/Points/PointsEngine.php`), and a conditional reward is redeemable by everyone (`yayloyalty_reward_conditions_match` defaults to true in `Engine/Redemption/RewardGate.php`; Pro answers it with `Pro/Services/RewardConditionsEvaluator.php`). A 1.0.0 reader reads a `schema_version` 2 tree as no conditions.
- The rewards Context carries a lazy customer-facts loader shared by its copies (`Engine/Rewards/Context.php`), so facts are read only when a `lifetime_points` condition runs. The Pro redeem card hides a reward the shopper's conditions exclude (`Pro/Storefront/RewardsPanel.php`).
- A downgraded site keeps a stored `batch` expiry mode but runs inactivity.

The admin SPA's side of the split is in [admin-spa.md](admin-spa.md#editions).
