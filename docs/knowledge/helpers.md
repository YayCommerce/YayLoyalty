---
area: helpers
updated: 2026-11-13
updated_ref: 6ec1e2d
covers: [includes/Utils/, includes/Helpers/, includes/Database/Transaction.php, includes/Access/, includes/Services/, includes/Controllers/AbstractRestController.php, includes/Engine/Earning/, includes/Engine/Expiry/ExpiryRunner.php, includes/Engine/Emails/Emails.php, includes/Engine/Storefront/, includes/Engine/Points/, apps/admin/src/lib/, apps/admin/src/hooks/, apps/admin/src/components/layout/]
---

# Helpers: reuse before you write

> Update when: a helper, service or hook meant for reuse is added, renamed or removed. Rebuild candidates with `yayh code index`.

## Data and SQL (PHP)

- `Utils\Table::name($slug)` (`includes/Utils/Table.php`): the prefixed table name. Never build it by hand or cache it.
- `Utils\Sql::in_placeholders($values, '%d')` (`includes/Utils/Sql.php`): placeholders for a prepared `IN (…)`.
- `Database\Transaction::run($fn)` / `after_commit($fn)` (`includes/Database/Transaction.php`): the only way to open a transaction; defer emails and actions until commit.
- `Utils\Json::encode|decode|encode_list|decode_list` (`includes/Utils/Json.php`): versioned JSON columns; never throws on read.
- `Utils\ConditionMatch::parse|decode|encode|assert_structure|fold` (`includes/Utils/ConditionMatch.php`): a campaign's or reward's `conditions` as `{ match, conditions }`; reads a flat list as `all`. `Campaign|Reward::has_conditions()` asks whether there is a condition.
- `Services\LedgerService` (`includes/Services/LedgerService.php`): every points change. `adjust()` / `record()` for manual rows; `earn()`, `refund_earn()`, `restore_refund()`, `expire()`, `redeem()` for keyed system rows. Get an instance from an existing composition root, don't construct a new one.
- `Engine\Earning\IdempotencyKey::compose|for_earn` (`includes/Engine/Earning/IdempotencyKey.php`): the key for any new ledger write.
- `Repositories\CustomerReader` (`includes/Repositories/CustomerReader.php`): depend on this when code may read a customer but must never write one.

## Money, points and dates (PHP)

- `Utils\PointsMath::round_points|snap|floor_to_decimals|points_to_currency|pct_of|pct_delta` (`includes/Utils/PointsMath.php`): all points and money math.
- `Utils\CurrencyRate::rate|from_base|to_base|base_decimals|current` (`includes/Utils/CurrencyRate.php`): every currency conversion.
- `Utils\Dates::now|offset|to_utc|to_timestamp|site_day_to_utc|site_day_start|site_month_start|to_site_tz|to_site_date|is_valid` (`includes/Utils/Dates.php`): UTC storage, site-zone display.
- `Helpers\SettingsHelper::points_label|points_label_plural|points_text|format_number` (`includes/Helpers/SettingsHelper.php`): shopper-facing "50 Points" copy in the store's number format.

## Settings and design (PHP)

- `Helpers\SettingsHelper::get_settings()` / `update_settings()` / `get_default_settings()`: the only way to read or save settings.
- `Helpers\DesignHelper::get_design|update_design|surface_enabled|with_unsaved|reset` (`includes/Helpers/DesignHelper.php`): storefront design; `surface_enabled()` decides whether a storefront surface shows.
- `Helpers\EmailsHelper::get_emails|update_emails|registered_emails` (`includes/Helpers/EmailsHelper.php`): loyalty email on/off state.
- `Utils\ConfigSpec::matches|is_limit|within_bounds` and `Utils\ConditionValueRules` (`includes/Utils/`): validate campaign and reward config against a typed spec.

## Eligibility and earning (PHP)

- `Engine\Earning\ProgramGate::program_enabled()` / `member_denial()` (`includes/Engine/Earning/ProgramGate.php`): is the program on, may this member earn or redeem.
- `Engine\Earning\EarningService::build|gate_reason|points_left_today|is_awardable_order|is_missing_points` (`includes/Engine/Earning/EarningService.php`): a wired earning service and eligibility questions without re-implementing them.
- `Engine\Points\ContextFactory::from_order|from_user|from_comment|from_product|from_cart|for_advert` (`includes/Engine/Points/ContextFactory.php`): always build scoring context here, never from "now".
- `Engine\Earning\PendingPoints::unreleased($order)` (`includes/Engine/Earning/PendingPoints.php`): points staged on an order.
- `Engine\Points\Context::is_loyalty_code|loyalty_codes` (`includes/Engine/Points/Context.php`): recognise our virtual coupon codes.
- `Engine\Earning\Listeners\ListenerGuard::run|for_order` (`includes/Engine/Earning/Listeners/ListenerGuard.php`): wrap every new WooCommerce hook handler.

## Jobs, emails, storefront (PHP)

- `Engine\Expiry\ExpiryRunner::register_daily|keep_daily|queue_now` (`includes/Engine/Expiry/ExpiryRunner.php`): add or trigger a daily Action Scheduler job.
- `Engine\Emails\Emails::relay_*` (`includes/Engine/Emails/Emails.php`) and subclassing `Engine\Emails\LoyaltyEmail` (override `cta()`, `extra_template_args()`): send or add a loyalty email.
- `Engine\Storefront\AccountMyPoints::url()` (`includes/Engine/Storefront/AccountMyPoints.php`): link to the My Points tab.
- `Engine\Storefront\Launcher::asset_version($path)` (`includes/Engine/Storefront/Launcher.php`): cache-busting version for a storefront asset.
- `Utils\WooOrders::is_hpos()` (`includes/Utils/WooOrders.php`): HPOS-aware admin order links only.

## Escaping, text, capabilities, REST (PHP)

- `Utils\Kses::svg|inline` (`includes/Utils/Kses.php`): allowlists for printed icons and number-wrapped sentences.
- `Utils\Text::plain|store_name` (`includes/Utils/Text.php`): plain text for the SPA and shopper copy.
- `Utils\IsoCodes::is_currency|is_country` (`includes/Utils/IsoCodes.php`).
- `Utils\Log::error|warning|debug` (`includes/Utils/Log.php`).
- `Access\Capabilities::can|payload` (`includes/Access/Capabilities.php`): plugin caps; `payload()` is what the SPA receives.
- `Controllers\AbstractRestController::requires|respond_paginated|handle_reorder|unique_slug` (`includes/Controllers/AbstractRestController.php`) with `exec_read` / `exec_write` from `BaseRestController`: every new route.
- `Utils\SingletonTrait::get_instance()` (`includes/Utils/SingletonTrait.php`): boot classes only.

## SPA (`apps/admin/src/`)

- `toConditionList`, `hasConditions`, `conditionListSchema` (`lib/schema/condition-match.schema.ts`): read either `conditions` wire shape; `ConditionListEditor` and `mergeConditionErrors` (`pro/conditions/`) edit and validate one in a Pro form.
- `api`, `toastApiError`, `getApiErrorMessage`, `isNotFoundError`, `isAbortError` (`lib/api/api.ts`): every REST call and failure toast.
- `parsePaginated`, `paginatedSchema`, `fetchAllPages`, `MAX_PER_PAGE` (`lib/api/paginated.ts`): list responses.
- `Can`, `can`, `useCapability`, `CAP` (`lib/access.tsx`): hide or disable by capability.
- `IS_PRO_BUILD` (`lib/is-pro-build.ts`) and `ProGate` (`components/pro/ProGate.tsx`): edition gating and the upsell.
- `useEditorSave` (`hooks/useEditorSave.ts`) and `useRouteLeaveGuard` (`hooks/useRouteLeaveGuard.tsx`): save, draft and leave-with-unsaved-changes flow.
- `resolveQueryLoad` (`components/layout/resolve-query-load.tsx`) and `PageLayout` (`components/layout/PageLayout.tsx`): page loading states and header.
- `useDebouncedSearchCommit` (`hooks/useDebouncedSearchCommit.ts`): search inputs.
- `formatWPDate`, `formatStoredDate`, `utcToDate`, `dateToStored`, `formatWPCurrency`, `formatStoreNumber` (`lib/helpers/format.helper.ts`): every date and money display.
- `lib/points-math/points-math.ts`: client copy of `PointsMath`.
- `settleInChunks` (`lib/helpers/settle-in-chunks.helper.ts`), `setPaginatedRow` (`lib/helpers/paginated-row.helper.ts`), `useCsvExport` (`hooks/`): bulk writes, cache patching, CSV.

## Hooks the plugin exposes

Public: `yayloyalty_points_awarded` (after commit), `yayloyalty_points_redeemed`, `yayloyalty_ledger_recorded`, `yayloyalty_pro_booted`, `yayloyalty_redeem_card` (renders the card), `yayloyalty_yaymail_register_emails`, `yayloyalty_yaymail_register_shortcodes`.

Seams Pro attaches through (documented "Internal seam, not a public API"): `yayloyalty_earning_gates`, `yayloyalty_redemption_gates`, `yayloyalty_register_trigger_handlers`, `yayloyalty_register_reward_handlers`, `yayloyalty_award_points` (can only lower), `yayloyalty_points_left_today`, `yayloyalty_order_award_triggers`, `yayloyalty_campaign_conditions_evaluator`, `yayloyalty_reward_conditions_match`, `yayloyalty_rest_controllers`, `yayloyalty_rest_item_schema`, `yayloyalty_campaign_config_schema`, `yayloyalty_reward_config_schema`, `yayloyalty_localize_admin_data`, `yayloyalty_plugin_name`. Others: `yayloyalty_table_schemas`, `yayloyalty_run_migrations`, `yayloyalty_currency_rate`, `yayloyalty_context_to_base_factor`.
