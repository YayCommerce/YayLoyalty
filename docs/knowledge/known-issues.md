---
area: known-issues
updated: 2026-11-13
updated_ref: 6ec1e2d
covers: [phpcs.xml.dist, YayLoyaltyPluginAdapter.php, tests/integration/bootstrap.php, includes/Pro/YayLoyaltyPro.php, includes/Engine/ActDeact.php, includes/Engine/Emails/EmailThrottle.php, includes/Engine/Storefront/, includes/Engine/Earning/Listeners/, includes/Services/CustomerActivityService.php, includes/Helpers/DesignSchema.php]
---

# Known issues

> Update when: a check starts or stops failing on `dev`, a gotcha is found or fixed, or an open question is answered.

## Checks that fail on `dev`

| Check | Result on `dev` (2026-11-12) | Cause |
|---|---|---|
| `composer lint` | Fails: 23 errors, 8 warnings in 5 files | All 23 errors are `Generic.WhiteSpace.DisallowTabIndent` in `YayLoyaltyPluginAdapter.php`. The warnings are parameters named `list` / `default` (`Universal.NamingConventions.NoReservedKeywordParameterNames`) in `CustomerRowFilters`, `CustomerItemSchema`, `CampaignConditionSet`, `RewardConditionSet` |
| `composer test` | Passes: 70 tests, 185 assertions | — |

Judge a failing check against these numbers: if it fails the same way on `dev`, it was already failing.

phpcs runs `PHPCompatibilityWP` with `testVersion 7.4-`, but it doesn't flag PHP 8-only syntax, and the dev tooling runs on PHP 8. CI only runs `php -l` on 7.4 (`.github/workflows/php.yml`). Review is the gate for the PHP 7.4 floor.

## Unfinished or missing

- **Pro `free_shipping` and `free_product` rewards can't redeem.** `YayLoyaltyPro` offers both types and their config schemas (`includes/Pro/YayLoyaltyPro.php`), but only `fixed_coupon` and `percent_coupon` get a handler (`DiscountRewardHandler`). Don't build on them until they have handlers.
- **No `cart_checkout_blocks` compatibility declaration.** `ActDeact::before_woocommerce_init` declares only `custom_order_tables`, although `StoreApiAdapter` supports the block checkout. WooCommerce may show the plugin as incompatible with the block checkout. An oversight, not a choice.
- **No privacy exporter or eraser** for the `customer_meta` activity snapshot: `TODO(privacy-eraser)` in `includes/Services/CustomerActivityService.php`.
- **A custom CSS design field must not render yet**: `TODO(custom-css)` in `includes/Helpers/DesignSchema.php` (CSS exfiltration risk).
- `CustomerFactsReader` loads every paid order of a customer to compute facts: `TODO(customer-facts-query)` in `includes/Engine/Points/CustomerFactsReader.php`.

## Gotchas

- **Only `completed` orders award points.** A store that leaves orders in `processing` shows pending points but never earns (`includes/Enums/OrderStatus.php`). Intentional.
- **`woocommerce_new_order` fires before line items are saved.** `OrderListener` scores the hook's own `$order` object; a fresh read would have no lines.
- **Signup points are for self-registration only.** Users created by an admin, an import, WP-CLI, cron or Action Scheduler earn nothing (`Engine/Earning/Listeners/SignupListener.php`).
- **An order reversed and then re-completed is not re-awarded.** `OrderListener` adds an order note asking for a manual adjustment.
- **`EmailThrottle` isn't atomic**: racing sends can exceed the weekly cap by one (`includes/Engine/Emails/EmailThrottle.php`).
- **Full-page caches serve stale balances and nonces** on the redeem card and launcher (`includes/Engine/Storefront/RedeemCard.php`).
- **Never memoize `Table::name()`**: it reads `$wpdb->prefix` each call so multisite `switch_to_blog()` and the integration suite's scratch prefix work.
- **Every version bump re-runs `Installer::install()`** (`maybe_upgrade` compares with `===`), so migrations must be idempotent.
- **A Pro hook attached after `RedemptionService::build()` or `EarningService::build()` is never applied** (see [architecture.md § Boot](architecture.md#boot)).
- **`./scripts/run.sh release` stamps the version in the working tree**, so a release leaves `yayloyalty.php` and `readme.txt` modified.
- **The integration suite needs the plugin active on the site it runs in** and bails with exit 1 otherwise; on a Local site set `YAYLOYALTY_TEST_SOCKET` to the site's mysqld socket (header of `tests/integration/bootstrap.php`).

## Open questions

- Older docs said an integration run can exit 0 without proving it ran. The bootstrap bails with exit 1 on every misconfiguration it checks, so the mechanism is unconfirmed.
- Whether `settings.api.ts` should move to zod parsing like the other API modules.
- Nothing enforces that a route's cap in `apps/admin/src/router.tsx` matches its `HeaderMenu` item; only a comment says so.
