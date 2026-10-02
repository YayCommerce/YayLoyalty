# YayLoyalty — Data Model: Customers and Ledger

Part of the [data model reference](../data-model.md), which owns the conventions, relationships, invariants and write paths every table here follows.

## `yayloyalty_customers` — one row per member (1.0)

A 1:1 extension of `wp_users`. Created eagerly at WP registration — `user_register` (fired by `wp_insert_user()`, including admin-created accounts) → `CustomerActivityListener::on_user_register()` → `CustomerRepository::ensure_row()`, balance 0, no ledger write; staff (a user with `edit_posts` or `manage_woocommerce`, WooCommerce's own back-office rule) are skipped. `ensure_row()` is also still called from inside `LedgerService::record()`, so a member who somehow reaches a ledger write without going through registration (legacy row, external insert) still self-heals. Holds cached aggregates for O(1) storefront reads and indexed columns for admin sorting/filtering, plus state that is not a ledger aggregate at all (expiry, tier, referral code). Updated inside the same transaction as the ledger row that justifies the change, always after `CustomerRepository::lock_and_get()` — that row lock is what serialises concurrent earns and redeems for one user. `balance`, like every other aggregate (`lifetime_earned`, `lifetime_redeemed`, `last_activity_at`), is written by `LedgerService`/`CustomerRepository::apply_delta()` in PHP — a relative `balance = balance + %d` update issued from inside the same row lock as the other aggregate writes, not a DB trigger; the others are conditional on `LedgerType`, not a plain running sum, which is why they cannot be triggers either. Tier/referral columns are updated by their own services.

The `(1.1)`/`(1.2)`/`(Pro)` tags below mark when a column's _feature_ ships, not when the _column_ does — `lifetime_spend`, `tier_*`, `referral_code` and `birthday_*` are all created by the 1.0 install so that a later release is a data migration (populate an existing NULL/0 column), not a schema change. See `CustomersTable::definition()`.

| Column | Type | Null / default | Meaning |
|---|---|---|---|
| `user_id` | BIGINT UNSIGNED | PK | `wp_users.ID`. Row existence = "is a member" — since the `user_register` hook, that now means every non-staff WP user, not only ones with loyalty activity. |
| `balance` | BIGINT | not null, `0` | Spendable points. Signed: a clawback after a refund can push it below zero, which the dashboard flags. Equals `SUM(ledger.points)` for the user. |
| `pending_points` | BIGINT UNSIGNED | not null, `0` | Points on orders that exist but have not reached the award status. Shown as "pending" in the launcher; never spendable. Equals the sum of `_yayloyalty_points_pending` across the user's unawarded orders. |
| `lifetime_earned` | BIGINT | not null, `0` | Net points ever earned: `earn` + `adjust` − `refund_earn`. A manual adjustment moves this, in either direction, only when the admin left "Adjust lifetime totals too" on — that writes an `adjust` row; off writes a `correction` row instead, which moves `balance` only. The type itself decides (`LedgerService::adjust()` picks it before the write), so this sum stays rebuildable from the ledger. Unaffected by `redeem`, `expire` and `correction`. This, not `balance`, is the tier basis in points mode — so spending points never demotes anyone; only an admin's deduction can. |
| `lifetime_redeemed` | BIGINT UNSIGNED | not null, `0` | `|redeem|` − `refund_redeem`. Dashboard and per-customer stats. |
| `lifetime_spend` | DECIMAL(19,4) | `0` (1.2) | Net paid order totals in base currency, maintained from order award/refund events. Tier basis in spend mode. The one aggregate not derivable from the ledger; rebuilt from orders. |
| `last_activity_at` | DATETIME | null | Timestamp of the last `earn` or `redeem`. Drives inactivity expiry. |
| `expires_at` | DATETIME | null, idx | `last_activity_at` + expiry days from settings; null when expiry is off. The daily expiry job selects on this index. |
| `reminder_sent_at` | DATETIME | null | When the "expiring soon" email last went out; cleared on activity so the next window gets one reminder, never two. |
| `tier_id` | BIGINT UNSIGNED | null, idx (1.2) | Current tier; null = base level. Latest row of `tier_history`. |
| `tier_evaluated_at` | DATETIME | null (1.2) | When the tier was last evaluated; the downgrade job uses it to find customers whose evaluation window has ended. |
| `tier_progress_value` | DECIMAL(19,4) | null (1.2) | Cached basis value for "$120 to Gold" in the launcher when the basis is rolling-12-month (which is expensive to compute per request). |
| `referral_code` | VARCHAR(32) | null, unique (1.1) | Generated the first time the customer opens the referral UI. Upper-case, ambiguity-free alphabet. Rotatable; old codes live on in `referrals.referral_code`. |
| `birthday_month` / `birthday_day` | TINYINT | null (Pro) | No year — only the annual trigger needs it, and it keeps the row out of sensitive-data territory. Idempotency for the yearly award is the ledger's `idempotency_key` `earn:{user_id}:birthday:{year}:birthday`. |
| `joined_at` | DATETIME | not null | When the row was created — the program join date for cohorts. |
| `updated_at` | DATETIME | not null | |

Indexes: PK; `expires_at`, `tier_id`, `balance`, `last_activity_at`, `lifetime_earned`, `lifetime_redeemed`, `joined_at` (each a single-column index for sorting/filtering); UNIQUE `referral_code`; `idx_tier_review (tier_id, tier_evaluated_at)` (1.2) — the downgrade job's query is "customers in tier X whose evaluation window has ended", and the single-column `tier_id` index alone would still force scanning every customer in the tier.

**`deleted_at` (F09, real — derived, never a column).** The customer drawer's `GET /customers/{id}`
response carries `deleted_at: string | null` (`CustomerItemSchema::properties()`), and there will
never be a `deleted_at` column on this table: this row and its `balance` are kept forever,
byte-identical, after the underlying WP user is deleted — that frozen balance IS the outstanding
liability the drawer must keep showing. `deleted_at` is instead DERIVED, at read time, from the
latest `account_deleted` row in `yayloyalty_customer_meta` (below), gated on `get_userdata()`
currently returning `false` too — not row existence alone, so a WP user id that is deleted then
reinstated (backup restore, migration tooling) automatically stops rendering as deleted, with no
"undelete" event needed. Identity (`name`/`email`) falls back to that row's `display_name`/`email`
snapshot once the live `WP_User` is gone. See `CustomerItemFormatter::format()`.

**`customers.user_id` is the PRIMARY KEY**, and it is what makes lazy row creation safe. `SELECT ... FOR UPDATE` on a row that does not yet exist takes a gap lock, not a row lock, so a check-then-create pattern lets two concurrent first-touch writes create two rows and permanently break `balance = SUM(ledger.points)`. Always materialise the row (insert-or-ignore on the primary key) **before** locking it.

---

## `yayloyalty_ledger` — every balance change (1.0)

Append-only journal. Nothing updates or deletes rows except GDPR erasure (which sets `user_id = 0`, `actor_id = NULL`, clears `note`) — a future erasure/export tool must also reach `yayloyalty_customer_meta` (below), the one other place PII survives a WP user deletion, AND every OTHER customer's rows too: `actor_name` snapshots the display name of whichever staff member performed an action, scattered across an unbounded number of other customers' activity logs, so a staff member's own erasure request has no defined scope without walking `customer_meta` broadly, not just the deleted customer's own row. One `earn` row per order **per trigger**: within a trigger stacking is highest-priority-only, across triggers an order may earn once each (`purchase`, then Pro's `first_order` …). The idempotency guarantee against duplicate hooks, status bouncing and webhook retries is one column under one unique index: `idempotency_key`, composed by `Engine\Earning\IdempotencyKey` as `{type}:{user_id}:{reference_type}:{reference_id}:{qualifier}` (`earn:7:order:1234:purchase`, `redeem:7:redemption:88:flexible_discount`). The qualifier is a `CampaignTrigger` on `earn`/`refund_earn` rows and a `RewardType` on `redeem`/`refund_redeem` rows — any build's, so a Pro value stays composable after a downgrade. The user is in the key because one reference can pay two people (a referral order pays advocate and friend); the qualifier because one reference may move points once per trigger when earning and once per reward type when redeeming. The former composite `uniq_ledger_event (user_id, source, reference_type, reference_id)` is no longer in the DDL; `Installer::run_migrations()` drops it on upgraded sites (stubbed until F01-4 — until then both indexes coexist there, harmlessly, since only NULL-keyed adjust rows exist).

| Column | Type | Null / default | Meaning |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK | Referenced by `lots.ledger_id`, `lot_allocations.ledger_id`, `referrals.advocate_ledger_id`. |
| `user_id` | BIGINT UNSIGNED | not null, idx | Whose balance changed. |
| `points` | BIGINT | not null | Signed delta. Positive for `earn`, `refund_redeem`, positive `adjust`/`correction`; negative for `redeem`, `refund_earn`, `expire`, negative `adjust`/`correction`. |
| `balance_after` | BIGINT | not null | Balance immediately after this row. Display in history, and a chain check for a future audit tool (`prev.balance_after + points = this.balance_after`). |
| `type` | VARCHAR(20) | not null, idx | What kind of movement: `earn`, `redeem`, `refund_earn` (clawback of earned points after a refund), `refund_redeem` (return of redeemed points), `expire`, `adjust` (manual, moves `lifetime_earned`), `correction` (manual, balance-only — the admin left "Adjust lifetime totals too" off). |
| `source` | VARCHAR(20) | not null | Why: values from `Enums\LedgerSource` — `order`, `signup`, `review`, `referral`, `birthday`, `milestone`, `social`, `renewal`, `manual`, `import`, `expiry`, `system`. Lets history and analytics group without decoding references. |
| `reference_type` | VARCHAR(20) | null | Namespace of `reference_id`: values from `Enums\ReferenceType` — `order`, `refund`, `comment`, `redemption`, `referral`, `user`, `birthday`, `import`. |
| `reference_id` | BIGINT UNSIGNED | null | The id in that namespace: order id for `earn`, refund id for both `refund_*` types (so each partial refund is its own row), comment id for review earn, redemption id for `redeem`, year for `birthday`. |
| `campaign_id` | BIGINT UNSIGNED | null, idx | The campaign that produced an `earn` row. Null for everything else. Per-campaign analytics is `GROUP BY campaign_id` via `CampaignPerformanceRepository`. |
| `redemption_id` | BIGINT UNSIGNED | null, idx | For `redeem` and `refund_redeem` rows: the claim this movement belongs to, so partial refunds can be tied back to one redemption. |
| `order_id` | BIGINT UNSIGNED | null, idx | Denormalised for every order-related row (`earn`, `refund_earn`, `redeem`, `refund_redeem`), so "all points activity for order #1234" is one indexed query for the order screen and support. |
| `note` | VARCHAR(500) | null | Admin reason on `adjust`/`correction` rows only. System rows have no stored text: their description is composed at render time from `type`/`source`/reference, so it is translatable and never goes stale. |
| `actor_id` | BIGINT UNSIGNED | null | Admin user for manual rows; null for the system. |
| `idempotency_key` | VARCHAR(96) | null, unique | `Engine\Earning\IdempotencyKey::compose()` for system rows; NULL for `adjust`/`correction`, which are deliberately not idempotent. The unique index is the guard; `LedgerService::earn()` and its redemption sibling `redeem()` attempt the insert and turn MySQL 1062 into `Outcome::duplicate`, a normal return. |
| `created_at` | DATETIME | not null | UTC. |

Indexes: PK; `idx_user_history (user_id, id)` for paged history and `ORDER BY id DESC LIMIT 1` balance lookups — `id`, not `created_at`, because same-second rows from one request would otherwise tie; UNIQUE `uniq_idempotency_key (idempotency_key)`; `campaign_id`; `redemption_id`; `order_id`; `(type, created_at)` for dashboard aggregates ("issued last 30 days").

Not on this table any more: `expires_at`. Per-earning expiry lives in `lots` ([future tables](./future-tables.md)) so the ledger stays immutable.

**NULLs are distinct in unique keys.** Because MySQL treats NULL as distinct, `adjust`/`correction` rows — which carry a NULL `idempotency_key` — never collide on the ledger unique key. Manual adjustments are therefore deliberately **not** idempotent: an admin can grant the same points twice on purpose. This is intended behaviour, not a defect.

---

## `yayloyalty_customer_meta` — non-points customer facts + activity log (1.0)

Generic per-customer key-value event log, added to capture account-deletion + a first slice of the
activity log. `meta_key` doubles as the activity log's `event_type`; `created_at` doubles as
`occurred_at`; `meta_value` is a JSON envelope carrying `data` plus the three activity display
fields. Append-only by convention (no caller ever updates or deletes a row) — same discipline as
the ledger, but no unique key: multiple rows per key are normal (repeated `points_adjusted`), and
`account_created`/`account_deleted` once-only-ness is a write-side `has()` check, not a DB
constraint (an accepted, cosmetic-only race under concurrency).

| Column       | Type               | Null / default | Meaning                                                                                                                              |
| ------------ | ------------------ | -------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `id`         | BIGINT UNSIGNED AI | PK             |                                                                                                                                      |
| `user_id`    | BIGINT UNSIGNED    | not null       | The member this fact/event belongs to.                                                                                               |
| `meta_key`   | VARCHAR(50)        | not null       | Also the activity log's `event_type`.                                                                                                |
| `meta_value` | LONGTEXT           | not null       | JSON envelope, see below. Validated in PHP (WooCommerce's minimum MySQL has no native JSON), same pattern as `campaigns.conditions`. |
| `created_at` | DATETIME           | not null       | UTC. Also the activity log's `occurred_at`.                                                                                          |

Indexes: PK; `idx_user_history (user_id, id)` (paged history, same rationale as the ledger's own index — `created_at` can tie within a second); `idx_user_key (user_id, meta_key)`, serving BOTH the group filter (`meta_key IN (...)`) and the `latest(user_id, 'account_deleted')` lookup — no standalone `meta_key` index, this table is never selective without `user_id`.

`meta_value` envelope (`schema_version = 1`):

```json
{
  "schema_version": 1,
  "data": {},
  "actor_kind": "staff|automatic|customer",
  "actor_name": "Jane|null",
  "staff_only": false
}
```

Eight `meta_key`/`event_type` values (`Enums\ActivityEvent::ALL`), only three of which have a writer today:

| `event_type`                                                                                        | Writer                                                                                                                                          | Status                                                                                                                                                                                                                                                  |
| --------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `account_created`                                                                                   | `CustomerActivityService::record_account_created()`, on `yayloyalty_ledger_recorded`                                                            | **Real.** Means "first loyalty activity", NOT "WP user registered" — unlike `yayloyalty_customers` (now created eagerly at registration, see above), this activity row still appears lazily, only on first ledger write. No backfill: a member created before this shipped has no row.                                   |
| `points_adjusted`                                                                                   | `CustomerActivityService::record_points_adjusted()`, on `yayloyalty_ledger_recorded`, filtered to `LedgerType::ADJUST`/`CORRECTION` + `LedgerSource::MANUAL` | **Real.**                                                                                                                                                                                                                                               |
| `account_deleted`                                                                                   | `CustomerActivityService::record_account_deleted()`, on `delete_user`/`wpmu_delete_user`                                                        | **Real.** One row: `email`/`display_name` snapshot, real `balance_at_deletion`/`kept_ledger_entries`, `void_coupons`/`credited_referrals`/`counted_in_members` always `null` (no redemptions/referrals/member-count feature exists — never fabricated). |
| `tier_changed`, `expiry_reset`, `reward_redeemed`, `balance_email_sent`, `expiring_soon_email_sent` | none                                                                                                                                            | **Unwritten** — tiers, the expiry job, redemptions and balance/expiry emails don't exist as features yet. A customer whose only history would be one of these legitimately has zero activity rows; that is the honest answer, not a bug.                |

**Lost-capture diagnostic (account-deletion capture is fire-and-forget by design — never blocks the real WP deletion, so a DB failure at the exact moment of `delete_user` loses that snapshot forever).** No tooling ships for this; it is a documented support/admin query only:

```sql
SELECT c.user_id
FROM yayloyalty_customers c
LEFT JOIN yayloyalty_customer_meta m
  ON m.user_id = c.user_id AND m.meta_key = 'account_deleted'
WHERE m.id IS NULL
```

Cross-check each returned `user_id` against `get_userdata()` returning `false` to confirm it is a real lost capture, not simply a live member who has never been deleted.

---

## Activity log — F09 Wave A/B, designed and partially implemented

The customer drawer's Activity log tab (F09) is a per-customer timeline distinct from the ledger:
tier changes, expiry resets, balance/expiring-soon reminder emails and admin actions — none of
which are balance changes, so none belong in `yayloyalty_ledger`. It is backed by
`yayloyalty_customer_meta` above. `GET /customers/{id}/activity` is a real, routed endpoint with
paging, a grouped filter and search, reading real rows for the three captured event types
(`includes/Controllers/ActivityRestController.php`); a customer whose only history would be one of
the five unwritten types gets an honest empty page, not an error.

The row shape: `{ id, occurred_at, event_type, data: {...}, actor_kind:
'staff'|'automatic'|'customer', actor_name: ?string, staff_only: bool }` — see `activity.schema.ts`
/ `ActivityRestController::define_item_schema()`. Same rule as the ledger's `note`/system-row
descriptions: no sentence is stored, `data` is structured and copy composes from `event_type` +
`data` at render time. `search` is a PHP-side match against `data`'s real content fields (+
`actor_name`), never the JSON envelope's structural keys — see
`CustomerMetaRepository::paginate_activity()`'s own docblock. `after`/`before` are site-timezone
calendar days converted by `Dates::site_day_to_utc()`, same as the ledger tab's.

---

## Customer drawer wire blocks not backed by a table — F09 Wave B

- **`loose_ends`** — non-null exactly when `deleted_at` is non-null (above). `kept_ledger_entries`
  is always real (`COUNT(*)` on the ledger); `void_coupons`/`credited_referrals`/`counted_in_members`
  are always `null` — no redemptions/referrals/member-count feature exists — and render as "Not
  tracked yet" in the SPA rather than being hidden or fabricated. See
  `CustomerItemFormatter::format()`.
- **`negative_balance`** (labelled F23 in code) — `{ cause_ledger_id, cause_reason, cause_amount,
reversal_status }`, explaining a negative balance in place (a refund reversed more than was
  left). `null` whenever `balance >= 0`; the real path would have to walk the ledger backwards to
  assemble it, which it doesn't do yet.
- **`expiry.batches`** — the Pro FIFO lot table on the expiring-points panel: an array of
  `{ earned_from, earned_to, points, expires_at }`, or `null` on the Free build or when the store's
  expiry model has no batches. This is a **display-only preview** of what
  `yayloyalty_lots`/`yayloyalty_lot_allocations` ([future tables](./future-tables.md)) will eventually back — those tables are
  still unimplemented (Pro 1.0), so nothing here reads or writes them.
- **`points_per_currency_unit`** — a single number (not the removed `worth` object), the rate 4b's/
  5a's money-equivalent figures multiply by. Restored narrowly after an earlier commit removed
  `worth` wholesale.

All four parse through the same Zod schema (`customer.schema.ts`), same discipline as everything
else in this drawer.

**Admin Customers list (`CustomerRepository::paginate_list()`).** `LEFT JOIN`s `wp_users` (was
`INNER JOIN`): a deleted member's `customers` row must still appear in the list, with the total
liability it represents, not vanish from the result set/pagination/every filter.
`CustomerListFormatter::format_row()` falls back to the `account_deleted` snapshot for
`name`/`email` when the joined `u.*` columns come back `NULL` — display-only: the query's own
sort/search still runs against the raw (possibly `NULL`) joined columns, no `COALESCE` added.
