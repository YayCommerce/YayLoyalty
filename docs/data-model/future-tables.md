# YayLoyalty — Data Model: Future Tables

Part of the [data model reference](../data-model.md), which owns the conventions, relationships, invariants and write paths every table here follows.

## `yayloyalty_referrals` — one row per referral relationship (1.1)

Tracks a friend from first click to payout. Hashes, not raw emails or IPs, so fraud checks work without storing PII.

**F09 Wave B (phase 06):** `GET /customers/{id}/referrals` is a real, routed, paginated endpoint
today (`includes/Controllers/ReferralsRestController.php`), always answering an empty envelope on
the real path. Its wire row is a **deliberate simplification** of the table below, not a preview of
it: `{ id, referred_name, referred_email, signed_up_at, first_order_id, first_order_total,
referrer_points, friend_coupon: { label, used }, status ('completed'|'pending'|'lapsed'),
window_closes_at }` — plain display fields (a name/email pair, not hashes; a `status` for what the
admin sees, not the real table's attribution/hold-window state machine). `referred_name`/
`referred_email` are personal data of a THIRD PARTY (the referred customer, not the drawer's own
subject) — the real endpoint must keep the same `CAP::VIEW` gate and never expose them to a lower
capability. The envelope also carries `referral_link` and a `campaign` block (`referrer_points`,
`friend_coupon_label`, `window_days`) — both derived from settings/user data, not new columns.

| Column                                         | Type               | Null / default | Meaning                                                                                                                                   |
| ---------------------------------------------- | ------------------ | -------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                                           | BIGINT UNSIGNED AI | PK             |                                                                                                                                           |
| `advocate_user_id`                             | BIGINT UNSIGNED    | not null, idx  | The referrer.                                                                                                                             |
| `referral_code`                                | VARCHAR(32)        | not null, idx  | Code used, snapshotted (codes can be rotated).                                                                                            |
| `friend_user_id`                               | BIGINT UNSIGNED    | null, idx      | Set when the friend has an account.                                                                                                       |
| `friend_email_hash`                            | CHAR(64)           | null, idx      | SHA-256 of lower-cased email + site salt. Duplicate and self-referral detection.                                                          |
| `friend_ip_hash`                               | CHAR(64)           | null           | Hashed IP at attribution. Velocity and same-IP checks.                                                                                    |
| `source`                                       | VARCHAR(20)        | not null       | How attribution happened: `link`, `code_at_checkout`, `share_email`, `share_whatsapp`, `share_x`, `share_facebook`.                       |
| `first_order_id`                               | BIGINT UNSIGNED    | null, idx      | The friend's qualifying order.                                                                                                            |
| `status`                                       | VARCHAR(12)        | not null, idx  | `pending` (attributed, no account) → `signed_up` → `ordered` (qualifying order placed, in hold) → `completed` \| `rejected` \| `expired`. |
| `attributed_at`                                | DATETIME           | not null       | Click or code capture. Start of the cookie window.                                                                                        |
| `signed_up_at` / `ordered_at` / `completed_at` | DATETIME           | null           | Milestones.                                                                                                                               |
| `hold_until`                                   | DATETIME           | null, idx      | `ordered_at` + hold days. The daily job completes referrals past this instant whose order was not refunded.                               |
| `advocate_ledger_id`                           | BIGINT UNSIGNED    | null           | The advocate's `earn` row (`source = referral`).                                                                                          |
| `friend_ledger_id`                             | BIGINT UNSIGNED    | null           | The friend's `earn` row when the friend reward is points.                                                                                 |
| `friend_coupon_id`                             | BIGINT UNSIGNED    | null           | The friend's issued coupon when the friend reward is a coupon.                                                                            |
| `rejection_reason`                             | VARCHAR(50)        | null           | `self_referral`, `same_email`, `same_ip`, `order_refunded`, `below_min_order`, `coupon_order`, `manual`.                                  |
| `created_at` / `updated_at`                    | DATETIME           | not null       |                                                                                                                                           |

Indexes: PK; `advocate_user_id`; `friend_user_id`; `friend_email_hash`; `first_order_id`; `(status, hold_until)`.

---

## `yayloyalty_referral_visits` — link clicks (1.1, prunable)

| Column          | Type               | Meaning                                   |
| --------------- | ------------------ | ----------------------------------------- |
| `id`            | BIGINT UNSIGNED AI |                                           |
| `referral_code` | VARCHAR(32), idx   | Code in the link.                         |
| `visitor_hash`  | CHAR(64)           | SHA-256 of IP + user agent + salt.        |
| `landing_path`  | VARCHAR(255)       | Where they landed.                        |
| `http_referrer` | VARCHAR(255)       | Where they came from (channel analytics). |
| `created_at`    | DATETIME, idx      |                                           |

Pruned daily to the cookie window plus 30 days. Exists only for the referral report's click → signup → order funnel.

---

## `yayloyalty_tiers` — VIP levels (1.2)

| Column                      | Type               | Null / default   | Meaning                                                                                                                                                                                                           |
| --------------------------- | ------------------ | ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                        | BIGINT UNSIGNED AI | PK               | Referenced by `customers.tier_id`, `tier_history`, and inside `campaigns.conditions` / `rewards.conditions`.                                                                                                      |
| `name`                      | VARCHAR(191)       | not null         | "Gold".                                                                                                                                                                                                           |
| `slug`                      | VARCHAR(64)        | unique           | `gold`.                                                                                                                                                                                                           |
| `rank`                      | INT                | not null, unique | Order of levels, 1 = lowest. Thresholds must increase with rank (validated on save).                                                                                                                              |
| `threshold`                 | DECIMAL(19,4)      | not null         | Entry value in the program's basis unit — points if the basis is lifetime or rolling points, currency if it is lifetime spend. The basis itself is a program-wide setting so every tier is measured the same way. |
| `perks`                     | LONGTEXT (JSON)    | not null         | `points_multiplier`, `free_shipping`, `birthday_bonus_points`, `reward_ids[]` (tier-only rewards), `badge_color`, `badge_icon`, `description`.                                                                    |
| `status`                    | VARCHAR(10)        | not null         | `active`, `paused`.                                                                                                                                                                                               |
| `created_at` / `updated_at` | DATETIME           | not null         |                                                                                                                                                                                                                   |

Program-level tier settings live in `yayloyalty_settings`: `tier_basis` (`lifetime_points` \| `rolling_points` \| `lifetime_spend`), `evaluation_window_months`, `downgrade_policy` (`never` \| `window_end`).

---

## `yayloyalty_tier_history` — every tier change (1.2)

| Column         | Type                 | Meaning                                                                          |
| -------------- | -------------------- | -------------------------------------------------------------------------------- |
| `id`           | BIGINT UNSIGNED AI   |                                                                                  |
| `user_id`      | BIGINT UNSIGNED, idx |                                                                                  |
| `from_tier_id` | BIGINT UNSIGNED NULL | Null = base level.                                                               |
| `to_tier_id`   | BIGINT UNSIGNED NULL | Null = demoted to base.                                                          |
| `reason`       | VARCHAR(20)          | `threshold` (crossed on earn), `window_end` (downgrade job), `manual`, `import`. |
| `basis_value`  | DECIMAL(19,4)        | The basis value at evaluation — the evidence for the change.                     |
| `actor_id`     | BIGINT UNSIGNED NULL | Admin for `manual`.                                                              |
| `created_at`   | DATETIME, idx        |                                                                                  |

Tier emails, the customer's timeline and tier-movement analytics all read this table; `customers.tier_id` is only the latest row.

---

## `yayloyalty_lots` and `yayloyalty_lot_allocations` — per-earning expiry (1.0 Pro)

Lot-based expiry needs a mutable "remaining" counter. Keeping it here, not on the ledger, preserves the ledger's immutability.

`yayloyalty_lots` — one per `earn` row when lot expiry is on:

| Column       | Type                    | Meaning                                                        |
| ------------ | ----------------------- | -------------------------------------------------------------- |
| `id`         | BIGINT UNSIGNED AI      |                                                                |
| `ledger_id`  | BIGINT UNSIGNED, unique | The `earn` row this lot came from.                             |
| `user_id`    | BIGINT UNSIGNED, idx    |                                                                |
| `points`     | BIGINT UNSIGNED         | Original size.                                                 |
| `remaining`  | BIGINT UNSIGNED         | Not yet consumed. The only mutable counter in the fact tables. |
| `expires_at` | DATETIME, idx           | When the remainder expires.                                    |
| `status`     | VARCHAR(10)             | `open`, `consumed`, `expired`.                                 |
| `created_at` | DATETIME                |                                                                |

`yayloyalty_lot_allocations` — how each redemption or expiry consumed lots, FIFO by `expires_at`:

| Column       | Type                 | Meaning                                                  |
| ------------ | -------------------- | -------------------------------------------------------- |
| `id`         | BIGINT UNSIGNED AI   |                                                          |
| `lot_id`     | BIGINT UNSIGNED, idx |                                                          |
| `ledger_id`  | BIGINT UNSIGNED, idx | The `redeem` or `expire` row that consumed from the lot. |
| `points`     | BIGINT UNSIGNED      | Amount taken from this lot.                              |
| `created_at` | DATETIME             |                                                          |

A `refund_redeem` re-opens allocations in reverse order (latest lot first) so returned points keep their original expiry.

---

## `yayloyalty_events` — outbox (2.0)

| Column         | Type                 | Meaning                                                                                       |
| -------------- | -------------------- | --------------------------------------------------------------------------------------------- |
| `id`           | BIGINT UNSIGNED AI   |                                                                                               |
| `event`        | VARCHAR(40), idx     | `points.earned`, `points.redeemed`, `points.expired`, `tier.changed`, `referral.completed`, … |
| `user_id`      | BIGINT UNSIGNED, idx |                                                                                               |
| `payload`      | LONGTEXT (JSON)      | Snapshot delivered to webhooks and ESP integrations.                                          |
| `created_at`   | DATETIME, idx        |                                                                                               |
| `delivered_at` | DATETIME NULL        | Null until every subscriber has acknowledged; the delivery job selects on it.                 |

Written in the same transaction as the fact that caused it, so a webhook can never fire for a change that rolled back.
