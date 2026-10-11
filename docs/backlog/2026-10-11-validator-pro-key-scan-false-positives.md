# The Lite validator's boot check flags Lite design keys as Pro-shaped

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** verification of the share-duplicated-logic branches, 2026-10-11; reproduced on `dev/edd` at 9b92459.

## Today

- **The scan:** `boot_script_source()` in `scripts/validate-build.php` walks `LocalizeHelper::get_meta()` and `LocalizeHelper::get_admin_data()`. It reports any key matching `/pro|premium/i` anywhere in its name.
- **The keys it flags:** three Lite design keys from `DesignSchema::get_default_design()` match because they contain "pro" in "prompt" and "product":
  - `design.storefront_text.signed_out_prompt`
  - `design.storefront_text.product_earn_line`
  - `design.visibility.product_message`
- **The result:** every Lite zip fails "Localized payload carries no Pro-shaped key" whenever the boot check runs (`YAYLOYALTY_WP_CLI` set, or `--require-boot`).
- **To reproduce:**
  1. Build a Lite zip the way `build_package` in `scripts/run.sh` does.
  2. Run `php scripts/validate-build.php <zip> lite --require-boot`, with `YAYLOYALTY_WP_CLI` pointing at a site and `YAYLOYALTY_WP_CLI_ARGS="--skip-plugins=yayloyalty"`.

## Why it matters

The check is meant to catch a Lite build that tells the SPA about Pro. As written, it fails on every Lite release run with the boot check enabled, so a real Pro-shaped key would be lost among the false ones. Anyone releasing with `--require-boot` either gets a rejected zip or learns to ignore the check.

## Suggested fix

Match "pro" or "premium" as a whole word within the key, splitting on `_` and camelCase. That would match `is_pro`, `proFeatures` or `premium`, and not `prompt` or `product`. A regex like `/(^|_)(pro|premium)(_|$)|(^|[a-z])(Pro|Premium)([A-Z]|$)/` would do it. It wasn't changed during the refactor because it is outside that work's scope.

## Done when

- [ ] `php scripts/validate-build.php <lite zip> lite --require-boot` with the boot check pointed at a site reports "Localized payload carries no Pro-shaped key" as PASS for a Lite zip built from `dev/edd`.
- [ ] The scan in `boot_script_source()` (`scripts/validate-build.php`) still flags a key named `is_pro` or `proFeatures`.
