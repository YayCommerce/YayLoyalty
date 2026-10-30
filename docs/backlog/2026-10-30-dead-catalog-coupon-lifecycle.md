# Redemptions still model a stored-coupon lifecycle that no code writes

**Purpose:** a follow-up left open by a review or a change, kept until every box under Done when is ticked.

**Update when:** a Done when condition is met (tick its box), the code it describes changes, or the suggested fix changes. Once every box is ticked, the item is done: remove the file.

**Source:** Misleading-names review, 2026-10-30, branch trace/refactor/misleading-names (left out of the rename because it changes behaviour).

## Today

Rewards reach the cart as virtual coupons, and both builders in `Engine\Redemption\CommitWriter` (`redemption_from()` and `reward_row()`) write `coupon_id` null, `expires_at` null and status `Redemption::STATUS_APPLIED`. No other code builds a `Models\Redemption` row. What was built for stored, claim-now-use-later coupons remains:

- `Database\Schema\RedemptionsTable::definition()` keeps the `coupon_id` column and its unique key `uk_coupon`, and the class docblock says the key "guards catalog coupons".
- `Models\Redemption` keeps `STATUS_ISSUED` and `STATUS_USED` in `STORED_STATUSES`, and `display_status()` derives `expired` from an issued row past `expires_at`.
- `Engine\Redemption\Reversal::settle_each()` skips rows with a `coupon_id`, and the class docblock describes them.
- `Repositories\RedemptionRepository::summary_by_rewards()` counts as outstanding only `issued` rows, so `outstanding`, `outstanding_customers` and `outstanding_points` are always 0 and `last_expires_at` always null. `Controllers\RewardRestController::decorate()` sends them, and `apps/admin/src/pro/rewards/archive/reward-archive.copy.ts` words the archive dialog from `outstanding`.
- The Pro reward types keep `coupon_expiry_days` (`Pro\YayLoyaltyPro`, `reward.schema.ts`, the editor's expiry field in `RewardEditorSections.tsx`), which `Pro\Services\DiscountRewardHandler`'s docblock says is not read.
- `rewardSubtitle()` in `apps/admin/src/pro/rewards/reward-labels.ts` describes a free product as "claim a coupon, then order the product" and a discount as "valid %d days after claiming".
- `customer-drawer.redemption-rows.ts` and `customer-drawer.copy.ts` label `issued` and `used` statuses.

## Why it matters

A merchant reads "valid 30 days after claiming" and sets an expiry that does nothing, and the archive dialog can never warn about unspent claims. A developer reading `Reversal` or the summary query assumes catalog coupons exist and reasons about cases that can't happen.

## Suggested fix

Remove `STATUS_ISSUED`, `STATUS_USED` and the derived expiry, the `coupon_id` skip in `Reversal`, the `outstanding*` and `last_expires_at` summary fields with their wire schema and copy, `coupon_expiry_days` with its editor field, and the stale subtitles. Drop `uk_coupon` and `coupon_id` last: dbDelta never drops a column or an index, so that needs `Database\Installer::run_migrations()` (`drop_index_if_exists()` and a column drop) as well as the change to `RedemptionsTable`. Left out of the rename because it changes the schema, the REST shape and the admin copy.

## Done when

- [ ] `Models\Redemption` has no `STATUS_ISSUED` or `STATUS_USED` constant.
- [ ] `Reversal::settle_each()` does not read `get_coupon_id()`.
- [ ] `RedemptionRepository::summary_by_rewards()` returns no `outstanding`, `outstanding_customers`, `outstanding_points` or `last_expires_at` key.
- [ ] `git grep -n coupon_expiry_days -- includes apps/admin/src` returns nothing.
- [ ] `rewardSubtitle()` in `apps/admin/src/pro/rewards/reward-labels.ts` mentions neither claiming a coupon nor days after claiming.
- [ ] `customer-drawer.redemption-rows.ts` has no label for `issued` or `used`.
- [ ] `RedemptionsTable::definition()` has no `coupon_id` column and no `uk_coupon` key.
- [ ] `Database\Installer::run_migrations()` drops `uk_coupon` and `coupon_id` from an existing redemptions table.
