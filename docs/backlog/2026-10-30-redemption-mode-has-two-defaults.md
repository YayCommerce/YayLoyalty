# The flexible discount's redemption mode has two defaults

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

`Models\Reward::default_config()` gives the flexible discount `redemption_mode` `REDEMPTION_MODE_AUTO_MAX`, and `Database\Installer::seed_builtin_reward()` stores that config. The admin can't create a second flexible discount (`Controllers\RewardRestController` refuses the type), so the seeded row is the only one, and it carries the key.

Every reader of a row without the key falls back to free input instead:

- `Engine\Rewards\Clamp::is_auto_max()`, which `Engine\Redemption\RedemptionService::quote()` and `Engine\Storefront\RedeemCard::view()` use.
- `REWARD_CONFIG_SCHEMAS.flexible_discount` in `apps/admin/src/lib/schema/reward.schema.ts`: `redemption_mode` defaults to `'free_input'`.
- `FlexibleDiscountFields` in `apps/admin/src/pages/rewards/FlexibleDiscountFields.tsx`: the radio group's value falls back to `'free_input'`.
- `flexibleDiscountSubtitle()` in `apps/admin/src/lib/helpers/reward.helper.ts`: anything but `auto_max` reads as free input.

The `Reward::REDEMPTION_MODE_*` docblock and the schema's comment say a missing key reads as free input on purpose, for rows saved before the key existed.

## Why it matters

Today the fallback only reaches a flexible discount row seeded before `redemption_mode` existed, which lives on development sites only. There it flips the checkout to a typed points field while a fresh install starts on "use the most the cart allows", and the admin shows free input as if the merchant had chosen it. Two answers to "what is the default" also invite the next reader to copy the wrong one.

## Suggested fix

Pick one default. Either make every fallback read `auto_max`, matching `default_config()`, or keep free input as the fallback and say in `default_config()` why a new row differs. The plugin has no live users yet, so making the fallbacks match needs no migration. Left out of the rename because it changes what a keyless row does at checkout.

## Done when

- [ ] `Clamp::is_auto_max()`'s fallback for a missing `redemption_mode` equals the mode `Reward::default_config()` gives the flexible discount, or `default_config()` says why they differ.
- [ ] The `redemption_mode` default in `REWARD_CONFIG_SCHEMAS.flexible_discount` equals `Clamp::is_auto_max()`'s fallback.
- [ ] The `redemption_mode` fallback in `FlexibleDiscountFields` equals `Clamp::is_auto_max()`'s fallback.
- [ ] `flexibleDiscountSubtitle()` treats a missing `redemption_mode` as `Clamp::is_auto_max()` does.
