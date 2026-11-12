---
area: conventions
updated: 2026-11-12
updated_ref: 3f6424ea
covers: [includes/Utils/, includes/Models/AbstractModel.php, includes/Helpers/SettingsHelper.php, includes/Controllers/BaseRestController.php, includes/Enums/, tests/, apps/e2e/, phpunit.xml.dist, phpunit-integration.xml.dist]
---

# Conventions

> Update when: a house rule for money, dates, Pro data, TypeScript copies, wire shapes, hooks or tests changes.

## Money and points

- Money is a **string** in PHP and bound as `%s`, never `%f` (`includes/Models/AbstractModel.php`). DB columns are `decimal(19,4)`.
- All points math goes through `Utils\PointsMath`: snap float error before comparing (`snap`), round **once per order, never per line** (`round_points`), floor discounts so they never exceed what pays for them (`floor_to_decimals`).
- Exchange rates come only from `Utils\CurrencyRate` (units of currency per base unit). It never rounds; callers round once.
- `apps/admin/src/lib/points-math/` copies `PointsMath` for the SPA. Change both together.

## Dates

- Every stored DATETIME is **UTC**. Use `Utils\Dates::now()` (`gmdate`), never `current_time()`.
- Convert to the site timezone only for display (`Dates::to_site_tz`, `to_site_date`). Site-day boundaries use `site_day_start`, `site_day_to_utc` (DST-safe).
- `'0000-00-00 00:00:00'` is compared by name (`Dates::ZERO`) because `strtotime` parses it.
- In the SPA, parse stored datetimes with `utcToDate` (`apps/admin/src/lib/helpers/format.helper.ts`); a bare `new Date()` reads local time.

## Pro data in Lite

- Pro values (trigger and reward type constants) stay in Lite enums, and Lite's `all_known()` lists stay unfiltered, so a downgraded site still accepts stored Pro rows. Pro widens lists through filters; never add a strip marker.
- Pro data lives in Lite tables and options; Lite ignores what it doesn't offer.
- SPA zod schemas accept Pro values too (`ALL_KNOWN_REWARD_TYPES`).

## Settings and stored JSON

- Read settings with `SettingsHelper::get_settings()`; save with `update_settings()`, which writes only the sent fields over what's stored. Unknown keys are dropped both ways; defaults are translated, so they're never stored.
- JSON columns are LONGTEXT stamped with `schema_version`, written with `Utils\Json::encode` / `encode_list` and read with `decode` / `decode_list`, which never throw.

## Wire shapes and TypeScript copies

- Items and settings are snake_case on the wire; the paginated envelope is camelCase. Nothing converts casing.
- A TypeScript copy of a PHP shape lives in `apps/admin/src/lib/schema/` or `src/types/` and starts with a "Mirrors `<PHP class/constant>`" docblock. Changing the PHP side means grepping for `Mirrors` and updating the copy.
- Text sent to the SPA is plain (`Utils\Text::plain`); the SPA never renders server HTML.

## Code shape

- PHP 7.4: typed properties are fine; no enums (`Enums/` are constant classes), `match`, `readonly`, promoted properties, nullsafe, named arguments or union types.
- New hook handlers on WooCommerce events run inside `ListenerGuard` (`includes/Engine/Earning/Listeners/ListenerGuard.php`).
- New ledger writes go through `LedgerService` with a key from `IdempotencyKey`, inside `Transaction::run`.
- Filters named in Engine are mostly documented as "Internal seam, not a public API": Pro attaches through them. Don't promise them to third parties.
- Hook names are `yayloyalty_*`; constants `YAYLOYALTY_*`; options, transients and meta keys `yayloyalty_*` (private meta `_yayloyalty_*`).
- Admin script and style handles are declared in `includes/Engine/Register/ScriptName.php`.
- Logging: `Utils\Log::error|warning|debug` (WooCommerce logger source `yayloyalty`); `debug` is silent unless `WP_DEBUG`.

## Tests

| Suite | Command | Setup |
|---|---|---|
| PHP unit, no WordPress | `composer test` (`--filter <Name>` for one) | `tests/bootstrap.php` shims WP functions; base class `tests/unit/EngineTestCase.php` |
| PHP integration | `composer test:integration` | Runs inside the WordPress site this plugin is installed in, plugin active. Tables move under scratch prefix `ylit_`; `IntegrationTestCase` truncates before each test instead of rolling back, because the code under test opens its own transactions |
| SPA | `pnpm --filter @yayloyalty/admin-page test` | Vitest, node environment |
| Browser | `pnpm --filter @yayloyalty/e2e env:start`, then `pnpm --filter @yayloyalty/e2e test` | wp-env on :8889 (`apps/e2e/.wp-env.json`), seeded by `apps/e2e/bin/setup.sh`; needs a built `pro` SPA; one worker because specs share store settings |
