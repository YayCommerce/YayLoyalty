---
paths:
  - "**/*.php"
---
<!-- Kit-managed by the yay plugin 0.8.0. Run /yay:harness-update instead of editing. -->
# WordPress and PHP rules (YayLoyalty — Points,  Rewards,  Referral & VIP for WooCommerce)

## PHP 7.4 floor
Product PHP must run on PHP 7.4. Dev tooling runs on PHP 8, so `php -l` and phpcs won't catch a violation. Only review does. When the floor is 7.4, these are out:
- enums, `match`, `readonly`, constructor property promotion, `?->`, named arguments
- union, intersection, `mixed`, `static` and `never` types
- `catch` without a variable, `throw` as an expression, a trailing comma in parameter lists
- `new` in default values, first-class callables (`foo(...)`), `$object::class`, attributes

Polyfilled by WordPress and allowed: `str_contains`, `str_starts_with`, `str_ends_with`, `array_is_list`.

## WordPress
- Every PHP file starts with `defined( 'ABSPATH' ) || exit;` (after the namespace and `use` lines).
- Sanitize input early (`sanitize_text_field`, `absint`, `wp_unslash` first), escape output late (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), and never echo raw request data.
- Every state-changing request checks a nonce and a capability. REST routes need a real `permission_callback`, never `__return_true` for writes.
- SQL goes through `$wpdb->prepare()`. Never interpolate.
- Translatable strings use the text domain `yayloyalty`. Don't concatenate translated fragments; use `sprintf` with placeholders and a translators comment.
- Prefix globals, options, hooks, transients and CSS handles with the plugin's prefix. Never use a bare name.
- WooCommerce code respects HPOS: use `wc_get_order()` and the order APIs, never `get_post_meta()` on orders.
