---
area: testing
updated: 2026-11-12
updated_ref: cc2a3e71
covers: [tests/php/, tests/js/, tests/unit/, tests/integration/, tests/bootstrap.php, phpunit.xml.dist, phpunit-integration.xml.dist, tests/e2e/.wp-env.json]
---

# Testing

> Update when: a test layer, bootstrap, shared helper or the wp-env setup changes, or a unit in the Coverage table gets a test.

## Layers

| Layer | Run | Lives in | Setup |
|---|---|---|---|
| unit | `yayh test run unit` | `tests/php/unit/` (`tests/php/unit.xml.dist`) | PHPUnit 9 + Brain Monkey, no WordPress. Base `YayTests\Unit\TestCase` |
| integration | `yayh test run integration` | `tests/php/integration/` (`tests/php/integration.xml.dist`) | WordPress test suite + WooCommerce in wp-env's **tests** site (:9191). Start it with `yayh e2e env start` (Docker) |
| js | `yayh test run js` | `tests/js/` (`vitest.config.mjs`) | Vitest at the workspace root, node environment |
| all but e2e | `yayh test run all` | | Integration reports *skipped* when the tests site is down |

Run one test with `vendor/bin/phpunit -c tests/php/unit.xml.dist --filter <Name>`, or `yayh test run <layer> --id=<P-n>` for a plan's tests. Tag tests `@group P-n` / `@group B-n` (PHP) or `@P-n` in the `describe` name (JS); the baseline is tagged `@group baseline` / `@baseline`.

**Older suites, still in place:** `composer test` (`tests/unit/`, `tests/bootstrap.php`, hand-written WordPress shims, 70 tests) and `composer test:integration` (`tests/integration/`, inside the WordPress site this plugin is installed in, scratch table prefix `ylit_`; on Local set `YAYLOYALTY_TEST_SOCKET`). `commands.test` still runs `composer test`. Add new tests to `tests/php/` and `tests/js/`.

## Bootstraps and shared helpers

- `tests/php/unit/bootstrap.php`: Composer autoload, `ABSPATH`, and an autoloader for `YayLoyalty\` → `includes/` (Composer autoloads only `vendor-prefixed/`).
- `YayTests\Unit\TestCase`: Brain Monkey with translation and escaping stubs; `apply_filters()` returns its value, so a unit test runs as **Lite** (no Pro filters).
  - `given_settings( $saved )`: stubs the `yayloyalty_settings` option, `get_locale`, `rest_sanitize_boolean`, `sanitize_key`, `sanitize_text_field`, and clears `SettingsHelper`'s static cache. Anything that reads `SettingsHelper::get_settings()` or `ExpiryPolicy::current()` needs it.
  - `given_batch_mode_is_available()`: makes `batch` a runnable expiry mode, as Pro does through `yayloyalty_expiry_modes`.
- `tests/php/integration/bootstrap.php`: loads WooCommerce and `yayloyalty.php` on `muplugins_loaded`, runs `WC_Install::install()`. The plugin's own `Installer` creates its tables on `plugins_loaded`, before any test transaction.
- `tests/js/vitest.config.mjs`: aliases `@` to `apps/admin/src`, so tests import SPA modules as the app does. Stub `window.yayLoyaltyMeta` with `vi.stubGlobal` for anything reading localized data.

## Gates and how tests get past them

- **Edition:** unit tests run as Lite. Stub the Pro filter a test needs (`yayloyalty_expiry_modes`, `yayloyalty_campaign_triggers`…). Under Vitest `IS_PRO_BUILD` is false; use `vi.stubEnv( 'VITE_IS_PRO', 'true' )` and re-import for Pro paths. The integration site loads the full tree, so Pro boots.
- **Signup points:** creating a user with no current user is a self-registration, and the seeded signup campaign awards points. Integration tests that count a balance call `wp_set_current_user( 1 )` before `self::factory()->user->create()`.
- **Transactions:** `Database\Transaction` detects the test suite's open transaction and nests with savepoints, so plugin writes roll back with each test.

## Traps hit while setting up

- `yayh` probes `http://localhost:9191`; inside a network sandbox the probe fails and integration is reported as down although the site is up.
- `Log::warning()` writes through `wc_get_logger()` when it exists, else `error_log()`, which Brain Monkey can't redefine. Assert "no warning" with `Functions\expect( 'wc_get_logger' )->never()`.
- `SettingsHelper::sanitize_output()` returns keys in schema order; compare whole arrays in that order.
- The e2e package in `tests/e2e/` is installed with `pnpm install --ignore-workspace`; it isn't a workspace member. wp-env pins WordPress 7.1.3 and WooCommerce 11.2.1 and sets `YAYLOYALTY_IS_DEVELOPMENT` false.

## Coverage

| unit | layer | priority | tests | source |
|---|---|---|---|---|
| `ClawbackMath::owed` | unit | critical | `tests/php/unit/Engine/Earning/ClawbackMathTest.php` @baseline | `includes/Engine/Earning/ClawbackMath.php` |
| `IdempotencyKey::compose`, `for_earn`, `qualifier_of` | unit | critical | `tests/php/unit/Engine/Earning/IdempotencyKeyTest.php` @baseline | `includes/Engine/Earning/IdempotencyKey.php` |
| `ExpiryPolicy` date maths and `customer_expires_at` | unit | critical | `tests/php/unit/Engine/Expiry/ExpiryPolicyTest.php` @baseline | `includes/Engine/Expiry/ExpiryPolicy.php` |
| `LotService::record` open and take order | unit | critical | `tests/php/unit/Services/LotServiceTest.php` @baseline | `includes/Services/LotService.php` |
| `RewardCoupons::percent_for_cap`, `in_currency` | unit | critical | `tests/php/unit/Engine/Redemption/RewardCouponsTest.php` @baseline | `includes/Engine/Redemption/RewardCoupons.php` (fix `6342a296`) |
| `SettingsHelper::validate`, `sanitize_output`, `is_valid`, `update_settings` | unit | critical | `tests/php/unit/Helpers/SettingsHelperTest.php` @baseline | `includes/Helpers/SettingsHelper.php` |
| `LedgerService` lot invariant, idempotent earn, correction | integration | critical | `tests/php/integration/Services/LedgerServiceTest.php` @baseline | `includes/Services/LedgerService.php` |
| SPA `format.helper` money and UTC dates | js | important | `tests/js/lib/helpers/format.helper.test.ts` @baseline | `apps/admin/src/lib/helpers/format.helper.ts` |
| `Dates::site_day_to_utc` invalid day | unit | important | bug: B-1 | `includes/Utils/Dates.php` |
| `Dates` site-day and DST helpers | unit | critical | candidate | `includes/Utils/Dates.php` |
| `LotService::rebuild` / `backfill` | unit | critical | candidate | `includes/Services/LotService.php` |
| `LedgerService::refund_earn`, `expire`, `restore_refund` | integration | critical | candidate | `includes/Services/LedgerService.php` |
| `EarningService` award-points clamp, `points_left_today` | unit | critical | candidate | `includes/Engine/Earning/EarningService.php` |
| `AbstractRestController::get_write_args` (mass assignment) | unit | critical | candidate | `includes/Controllers/AbstractRestController.php` |
| Settings and design REST permissions | integration | critical | candidate | `includes/Controllers/SettingsRestController.php`, `DesignRestController.php` |
| `DiscountRewardHandler::quote`, `coupon_props` | unit | critical | candidate | `includes/Pro/Services/DiscountRewardHandler.php` |
| `BaseCalculator::compute` exclusions and tax | unit | important | candidate | `includes/Engine/Points/BaseCalculator.php` |
| `CapPolicy::apply` | unit | important | candidate | `includes/Engine/Points/CapPolicy.php` |
| `ProgramGate::check` order | unit | important | candidate | `includes/Engine/Earning/ProgramGate.php` |
| `DailyEarningLimit::clamp`, `left_today` | unit | important | candidate | `includes/Pro/Services/DailyEarningLimit.php` |
| `ConfigSpec`, `ConditionValueRules` | unit | important | candidate | `includes/Utils/` |
| `Json::decode`, `decode_list` | unit | important | candidate | `includes/Utils/Json.php` |
| `SessionIntent` | unit | important | candidate | `includes/Engine/Redemption/SessionIntent.php` |
| `DesignCoercion::coerce`, `DesignHelper::update_design` | unit | important | candidate | `includes/Helpers/` |
| `LauncherVisibility::is_visible` | unit | important | candidate | `includes/Engine/Storefront/LauncherVisibility.php` |
| `EmailThrottle` | unit | important | candidate | `includes/Engine/Emails/EmailThrottle.php` |
| `CustomerSegmentsHelper` validation | unit | important | candidate | `includes/Pro/Helpers/CustomerSegmentsHelper.php` |
| `Installer::maybe_upgrade` lock | integration | important | candidate | `includes/Database/Installer.php` |
| `Capabilities::migrate`, `defaults` | integration | important | candidate | `includes/Access/Capabilities.php` |
| SPA `api.ts` plain-permalink URLs, error messages | js | important | candidate | `apps/admin/src/lib/api/api.ts` |
| SPA `fetchAllPages` | js | important | candidate | `apps/admin/src/lib/api/paginated.ts` |
| SPA expiry settings form | js | important | candidate | `apps/admin/src/pages/settings/expiry-settings.form.ts` |
| SPA setup wizard form | js | important | candidate | `apps/admin/src/components/setup-wizard/setup-wizard.form.ts` |

## Open questions from the scouts

- `LotService::rebuild` with a positive balance and no positive ledger rows opens no lot, so SUM(open) ≠ balance; its docblock says the oldest lot absorbs the gap.
- `CustomerSegmentsHelper::sanitize_name` truncates with byte `substr` although names may be multibyte.
- `ConfigSpec` `int` / `number` accept `INF` when no max is set (`ConditionValueRules::is_int_like`).
- `Installer::acquire_lock` never reclaims a lock row whose value is 0 or non-numeric.
- `SettingsHelper::sanitize_output` reads a corrupt stored limit of 0 as "no limit".
- `formatWPCurrency` prints `NaN` for an unparsable string.
