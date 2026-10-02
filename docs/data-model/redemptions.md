# YayLoyalty — Data Model: Redemptions

Part of the [data model reference](../data-model.md), which owns the conventions, relationships, invariants and write paths every table here follows.

## `yayloyalty_redemptions` — one row per reward claim (1.0, NOT IMPLEMENTED)

**Status:** Table not created. Schema designed, and since F08's scaffold the design is also in code: `Database/Schema/RedemptionsTable.php` declares `expected_columns()`/`expected_indexes()` matching the table below (its `definition()` is a `TODO(F08)` stub that throws), `Models\Redemption` is real and round-trips the row, `Repositories\RedemptionRepository` is stubs. Nothing installs it until `RedemptionsTable` joins `Installer::schemas()`, which is the step that creates the table on the next upgrade.

**Rewards archive/delete:** `RedemptionRepository::summary_by_rewards()` is the one real read today — a batched `GROUP BY reward_id` behind a `SHOW TABLES` check, so it answers `[]` until the table is installed. It counts rows in any status (the reward delete guard) and "outstanding" = `status = 'issued' AND (expires_at IS NULL OR expires_at > now)`. Uses the `reward_id` index.

**F09 Wave B (phase 05):** `GET /customers/{id}/redemptions` is a real, routed, paginated endpoint
today (`includes/Controllers/RedemptionsRestController.php`), always answering an empty envelope
on the real path. Its wire row is a **deliberate simplification** of the table below, not a preview
of it: `{ id, reward_id, reward_name, order_id, points (signed), value, currency, status, created_at }`
drops `base_value`/`rate_snapshot`/`coupon_code`/`coupon_id`/`restored_points`/`expires_at` (this
tab never displays them) and adds `reward_name`, a display string the real table does not store —
resolving `reward_id` to a name is an open question (client-side lookup vs. server join), the same
one the ledger's `campaign_name` already has, deliberately not solved here. `status` is a display
enum (`applied`/`returned`/`issued`), not the real table's full lifecycle vocabulary above.

When built, it will be created at claim time. It exists because a refund needs the value _and the rate at redemption time_, multi-currency stores need the currency of the discount as a fact, and catalog claims have no order yet. The ledger's `redeem`/`refund_redeem` rows reference this row uniformly for every reward type.

| Column                      | Type               | Null / default | Meaning                                                                                                                                                                                                                                   |
| --------------------------- | ------------------ | -------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                        | BIGINT UNSIGNED AI | PK             | Referenced by `ledger.redemption_id` and `ledger.reference_id` (with `reference_type = 'redemption'`).                                                                                                                                    |
| `user_id`                   | BIGINT UNSIGNED    | not null, idx  |                                                                                                                                                                                                                                           |
| `reward_id`                 | BIGINT UNSIGNED    | not null, idx  | Which reward.                                                                                                                                                                                                                             |
| `order_id`                  | BIGINT UNSIGNED    | null, idx      | The order the discount applied to. Null for a catalog coupon not yet used.                                                                                                                                                                |
| `points`                    | BIGINT UNSIGNED    | not null       | Points spent.                                                                                                                                                                                                                             |
| `value`                     | DECIMAL(19,4)      | not null       | Discount value in `currency`.                                                                                                                                                                                                             |
| `currency`                  | CHAR(3)            | not null       | Currency the customer checked out in (YayCurrency display currency).                                                                                                                                                                      |
| `base_value`                | DECIMAL(19,4)      | not null       | The same value in the store's base currency, for reports.                                                                                                                                                                                 |
| `rate_snapshot`             | DECIMAL(12,4)      | not null       | Points per currency unit at redemption time. Refunds use this, never today's rate.                                                                                                                                                        |
| `coupon_code`               | VARCHAR(64)        | null           | `yayloyalty-redeem` (virtual) for the flexible discount; the issued single-use code for catalog rewards.                                                                                                                                  |
| `coupon_id`                 | BIGINT UNSIGNED    | null           | `shop_coupon` post id for catalog rewards (Pro). Null for virtual coupons.                                                                                                                                                                |
| `status`                    | VARCHAR(12)        | not null       | Flexible: `applied` (order exists, points debited) → `completed` (order paid) \| `cancelled` (order cancelled/failed; points restored) \| `refunded` (fully restored via refund). Catalog: `issued` → `used` \| `expired` \| `cancelled`. |
| `restored_points`           | BIGINT UNSIGNED    | `0`            | Points already returned through refunds, so repeated partial refunds can never restore more than was spent.                                                                                                                               |
| `expires_at`                | DATETIME           | null           | Catalog coupon expiry (Pro).                                                                                                                                                                                                              |
| `created_at` / `updated_at` | DATETIME           | not null       |                                                                                                                                                                                                                                           |

Indexes: PK; UNIQUE `uk_order_reward (order_id, reward_id)`; `user_id`; `order_id`; `reward_id`; `idx_status_created (status, created_at)`; **`idx_reward_user (reward_id, user_id)`, REQUIRED** — the `redemption_count` reward condition (see [rewards' `conditions` section](./rewards.md#conditions--availability-rules)) runs `COUNT(*) WHERE reward_id = ? AND user_id = ?` on every evaluation; without this composite index that is a full table scan per claim. Do not create this table without it.

`uk_order_reward` is the double-debit guard, not an optimisation: an order may carry one claim of a given reward and no more, so a retried checkout, a doubled hook or two concurrent commits collide on the index instead of debiting twice. `Engine\Redemption\CommitWriter` reads through it first (`RedemptionRepository::find_by_order()`) under the customer's row lock and treats the insert's 1062 as the same duplicate answer.
