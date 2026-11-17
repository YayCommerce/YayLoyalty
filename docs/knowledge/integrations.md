---
area: integrations
updated: 2026-11-16
updated_ref: c3809e9
covers: [includes/Integrations/, includes/Pro/Integrations/, includes/Engine/ActDeact.php, includes/Engine/Redemption/Adapters/, includes/Utils/CurrencyRate.php, includes/Utils/WooOrders.php]
---

# Integrations

> Update when: a WooCommerce surface, a compatibility declaration or a partner-plugin integration changes.

## WooCommerce

| Surface | Status | File |
|---|---|---|
| HPOS | Declared compatible (`custom_order_tables`) on `before_woocommerce_init`. Orders go through `wc_get_order` and the order API. `WooOrders::is_hpos()` only picks admin link shapes. HPOS trashing doesn't fire `status_changed`, so reversal also hooks `woocommerce_trash_order` | `includes/Engine/ActDeact.php`, `includes/Utils/WooOrders.php` |
| Block checkout | Supported through the Store API (`StoreApiAdapter`: cart extension data under namespace `yayloyalty`, update callback `yayloyalty-redeem`, slot fill in `assets/storefront/redeem-blocks.js`). **Not declared** as `cart_checkout_blocks` compatible (see [known-issues.md](known-issues.md)) | `includes/Engine/Redemption/Adapters/StoreApiAdapter.php` |
| Classic checkout and cart | Card rendered as a `<tr>` before the order total; commit on `woocommerce_checkout_order_processed` | `includes/Engine/Redemption/Adapters/ClassicCheckoutAdapter.php` |
| Coupons | Virtual loyalty coupons through `woocommerce_get_shop_coupon_data`; applied after every real coupon | `includes/Engine/Redemption/CouponProvider.php` |
| Emails | `WC_Email` subclasses, `woocommerce_email_classes`, group `yayloyalty_points`, theme-overridable templates | `includes/Engine/Emails/` |
| My Account | Endpoint `my-points` | `includes/Engine/Storefront/AccountMyPoints.php` |
| Admin order screen | Missing-points column and filter (HPOS and legacy hooks); loyalty coupon removal with optional points restore | `includes/Engine/Admin/OrderMissingPoints.php`, `includes/Engine/Admin/OrderCouponRemoval.php` |
| Block themes | Product earn message also on `render_block_woocommerce/product-price` | `includes/Engine/Storefront/ProductEarnMessage.php` |
| Action Scheduler | Daily jobs `yayloyalty_expiry_run` and Pro's `yayloyalty_birthday_run`. Nothing expires without Action Scheduler | `includes/Engine/Expiry/ExpiryRunner.php` |

Meta saves under HPOS: `PendingPoints::save_meta` turns off `woocommerce_orders_table_datastore_should_save_after_meta_change` for the save, so a meta write doesn't re-run a full order save and re-fire status hooks.

## Partner plugins

Every integration is detected by a constant the partner defines before YayLoyalty boots, and names the partner's classes only inside the partner's own hooks. Autoloading an adapter that extends a partner class without the partner active is a fatal.

| Plugin | Detected by | What it does | File |
|---|---|---|---|
| YayMail | `YAYMAIL_VERSION` ≥ 4.4.7, `YaymailPluginAdapter` exists and is licensed | Registers designable templates and shortcodes for each loyalty email on `yaymail_register_emails` / `yaymail_register_shortcodes`. Pro adds its emails through `yayloyalty_yaymail_register_emails` | `includes/Integrations/YayMail/YayMailIntegration.php`, `includes/Pro/Integrations/YayMail/` |
| YayPricing | `YAYDP_VERSION` ≥ 3.5.8 | Six loyalty conditions on `yaydp_register_conditions`. `LoyaltyCouponRerun` re-runs `calculate_totals()` once when the loyalty coupon set changed, because YayPricing skips an unchanged cart signature | `includes/Integrations/YayPricing/` |
| YayCurrency | `YAY_CURRENCY_VERSION` | Answers `yayloyalty_currency_rate` (the order's saved rate, else the live rate); snapshots the rate on non-base orders; stops YayCurrency converting loyalty coupons twice; skips conversion on `yayloyalty/v1` REST so the SPA sees base currency | `includes/Integrations/YayCurrency/YayCurrencyIntegration.php` |
| YayCommerce AdminShell | Composer package, scoped | Shared menu, licence screens, version election between Yay plugins | `YayLoyaltyPluginAdapter.php`, see [build-release.md](build-release.md#the-admin-shell-and-vendor-prefixed) |
| Elementor | Its own hooks only (`elementor/widgets/register`, `elementor/elements/categories_registered`, `elementor/preview/enqueue_styles`) | Four classic widgets (Points balance, Ways to earn, Ways to redeem, Redeem card) in a YayLoyalty category | `includes/Integrations/PageBuilders/Elementor/` |
| Bricks (theme) | `init` at 11, after Bricks loads `\Bricks\Element` at 10; skipped without `\Bricks\Elements` | The same four as elements, registered with their class (Bricks otherwise takes the last declared class); category label through `bricks/builder/i18n` | `includes/Integrations/PageBuilders/Bricks/` |
| Divi 4 (theme) | `et_builder_ready` | The same four as modules with partial Visual Builder support (PHP render over `et_fb_ajax_render_shortcode`), named "YayLoyalty …" since Divi 4 has no module groups. Slugs are `yayloyalty_divi_*`: Divi registers each slug as a shortcode, so one equal to a loyalty shortcode tag would replace it and recurse | `includes/Integrations/PageBuilders/Divi/` |

The page builders differ from the plugins above: Bricks and Divi are themes, which load after YayLoyalty boots, so they are never detected by constant; only their hooks are added. Every element prints its shortcode through `PageBuilders/PlacementWidgets::output()`, which shows a placeholder (the account tab's state card, `templates/account-rewards-state.php`) only when the placement is empty in a builder preview.

## Multi-currency

Amounts stay in the order currency. `ContextFactory::to_base_factor` (filter `yayloyalty_context_to_base_factor`) converts to base for points math, defaulting to the inverse of `CurrencyRate::rate()`, which is 1.0 without an integration. `CurrencyRate::base_decimals()` reads `woocommerce_price_num_decimals` because YayCurrency filters `wc_get_price_decimals()`.
