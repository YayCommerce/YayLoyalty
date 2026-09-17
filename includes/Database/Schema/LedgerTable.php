<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_ledger — every balance change (1.0 columns only).
 *
 * Append-only. Nothing updates or deletes rows except GDPR erasure. This is the
 * truth; customers is a cache derived from it.
 *
 * THE UNIQUE KEY IS THE IDEMPOTENCY GUARANTEE. `uniq_ledger_event` on
 * (user_id, source, reference_type, reference_id) is what stops duplicate
 * hooks, order status bouncing and webhook retries from awarding twice.
 *
 * `source`, NOT `type`. Several sources share a `reference_type` — `signup`,
 * `milestone` and `renewal` can all point at `reference_type = 'user'` — so a
 * key on `type` would collide a second, genuinely different, `earn` event
 * against the first one recorded for that reference.
 *
 * `user_id` IS PART OF THE KEY, not just an extra column. One reference can
 * legitimately produce rows for two different users — a referral order earns
 * both the advocate and the friend — and without user_id in the key the
 * second row would be rejected as a duplicate of the first.
 *
 * It must be written as `UNIQUE KEY name (...)`. dbDelta parses CREATE TABLE
 * with regular expressions and only recognises the KEY forms; a bare
 * `UNIQUE (...)` succeeds on first install and then silently fails to normalise
 * on every upgrade, dropping the index on the one code path nobody tests.
 *
 * MySQL treats NULLs as DISTINCT in a unique index, so rows with a NULL
 * reference — manual adjustments — never collide. Manual adjustments are
 * therefore deliberately NOT idempotent: an admin can grant the same points
 * twice on purpose. Intended, and it reads like a bug, so it is stated here,
 * in LedgerService::adjust(), and in docs/data-model.md → ledger.
 *
 * `idx_user_history` ON (user_id, id) — not (user_id, created_at). Two rows
 * for the same user can share a created_at down to the second (a campaign
 * firing several earns in one request), which makes created_at ordering
 * ambiguous for "most recent row"; id is strictly monotonic and never ties.
 * It serves the same two queries a (user_id, created_at) index would: paged
 * ledger history for one user, and `ORDER BY id DESC LIMIT 1`
 * (LedgerRepository::latest_for_user(), uncalled today) for a future
 * reconciliation job. Replaces the older `user_created` key of the same shape.
 */
final class LedgerTable extends AbstractTableSchema {

    public function slug(): string {
        return 'ledger';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            points bigint NOT NULL,
            balance_after bigint NOT NULL,
            type varchar(20) NOT NULL,
            source varchar(20) NOT NULL,
            reference_type varchar(20) DEFAULT NULL,
            reference_id bigint unsigned DEFAULT NULL,
            campaign_id bigint unsigned DEFAULT NULL,
            redemption_id bigint unsigned DEFAULT NULL,
            order_id bigint unsigned DEFAULT NULL,
            note varchar(500) DEFAULT NULL,
            actor_id bigint unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_ledger_event (user_id,source,reference_type,reference_id),
            KEY idx_user_history (user_id,id),
            KEY campaign_id (campaign_id),
            KEY redemption_id (redemption_id),
            KEY order_id (order_id),
            KEY type_created (type,created_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'user_id',
            'points',
            'balance_after',
            'type',
            'source',
            'reference_type',
            'reference_id',
            'campaign_id',
            'redemption_id',
            'order_id',
            'note',
            'actor_id',
            'created_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            // If this one is ever missing, idempotency is gone and duplicate
            // awards become possible. Installer::verify() exists for this.
            'uniq_ledger_event',
            'idx_user_history',
            'campaign_id',
            'redemption_id',
            'order_id',
            'type_created',
        ];
    }
}
