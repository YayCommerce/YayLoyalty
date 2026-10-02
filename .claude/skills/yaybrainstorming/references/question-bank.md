# Question bank

Pick only the questions the codebase and the request leave open. Group them by topic so each round of two or three questions stays on one topic.

## Purpose and success

- Who triggers this and what do they see when it works? (merchant in admin, customer on the storefront, a scheduled job)
- What is the one observable result that proves it works?
- What exists today that this replaces, extends, or must not break?

## Scope

- What is the smallest version that is still useful? What is explicitly out for now?
- Which plugin owns this feature? Does it belong in shared code or in one plugin?
- If the plugin has free and pro tiers, which tier gets it, and what does the other tier show?

## Compatibility

- Must this work with WooCommerce HPOS, legacy post storage, or both?
- Block checkout and cart, classic shortcode checkout, or both?
- Multisite? Minimum PHP and WordPress versions?
- Page builders or themes that must be supported (Bricks, Elementor, FSE themes)?

## Ecosystem

- Which sibling plugins read or write the same data, hook the same actions, or render on the same screen?
- Does this change shared code? If so, every consumer is affected: list them and decide whether the change is backward compatible.
- Any WordPress.org release rules that apply (no remote calls without consent, GPL-compatible dependencies, no tracking by default)?

## Data

- Where does state live: option, post meta, order meta, user meta, custom table? Why that one?
- What happens to merchants already running the plugin: migration, default values, backfill?
- What is removed on uninstall?

## UI

- Where does it appear in admin: existing settings page, a new tab, per-item controls, a notice?
- Which existing UI stack does it use (React settings app, Gutenberg block, jQuery, plain PHP template)?
- Storefront output: template override, hook injection, block, shortcode?

## Performance and safety

- Does it run on every request, every cart recalculation, or only on an admin action? Cache or precompute?
- Capability checks and nonces for every admin action; sanitisation on input, escaping on output.
- Large stores: thousands of products, orders, or customers. What is the expensive path?

## Edge cases

- Empty states, missing data, deactivated sibling plugin, downgraded tier.
- Conflicts with other plugins that modify the same thing (pricing, checkout fields, emails).
- Currency, locale, RTL, and translation of any new strings.
