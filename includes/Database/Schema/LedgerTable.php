<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_ledger — every balance change (1.0 columns only).
 *
 * Append-only. Nothing updates or deletes rows except GDPR erasure. This is the
 * truth; customers is a cache derived from it.
 *
 * THE UNIQUE KEY IS THE IDEMPOTENCY GUARANTEE. `reference` on
 * (type, reference_type, reference_id) is what stops duplicate hooks, order
 * status bouncing and webhook retries from awarding twice.
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
 * in LedgerService::record(), and in docs/code-standards.md.
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
            UNIQUE KEY reference (type,reference_type,reference_id),
            KEY user_created (user_id,created_at),
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
            'reference',
            'user_created',
            'campaign_id',
            'redemption_id',
            'order_id',
            'type_created',
        ];
    }
}
