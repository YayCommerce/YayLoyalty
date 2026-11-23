<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\Tier;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_tiers. A live tier has no deleted_at. No cache: the catalogue holds at most
 * Tier::MAX_LIVE live rows, read once per request that needs it.
 */
final class TierRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'tiers';
    }

    protected function model_class(): string {
        return Tier::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'threshold', 'tier_rank', 'name' ];
    }

    /** The tier with this id, live or deleted. */
    public function find_tier( int $id ): ?Tier {
        $model = $this->find( $id );

        return $model instanceof Tier ? $model : null;
    }

    /** The tier with this id while it is live. */
    public function find_live( int $id ): ?Tier {
        $tier = $this->find_tier( $id );

        return null !== $tier && $tier->is_live() ? $tier : null;
    }

    /**
     * The tiers with these ids, deleted ones included, for name lookups (a ledger label, an activity row).
     *
     * @param int[] $ids
     * @return array<int, Tier> Keyed by id; an unknown id is absent.
     */
    public function find_many( array $ids ): array {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );

        if ( [] === $ids ) {
            return [];
        }

        $table = esc_sql( $this->table() );
        $in    = Sql::in_placeholders( $ids, '%d' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a placeholder list; every id is bound below, and the table name is escaped.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id IN ({$in})", $ids ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $tiers = [];

        foreach ( (array) $rows as $row ) {
            $tier = $this->hydrate( $row );

            if ( $tier instanceof Tier ) {
                $tiers[ (int) $tier->get_id() ] = $tier;
            }
        }

        return $tiers;
    }

    /**
     * find_many() reduced to names, for a label that names a tier, deleted or not.
     *
     * @param int[] $ids
     * @return array<int, string> Keyed by id; an unknown id is absent.
     */
    public function names_by_id( array $ids ): array {
        return array_map( static fn( Tier $tier ): string => $tier->get_name(), $this->find_many( $ids ) );
    }

    /**
     * Every live tier, lowest threshold first.
     *
     * @return Tier[]
     */
    public function live(): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE deleted_at IS NULL ORDER BY threshold ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no values; the table name is escaped.
            ARRAY_A
        );

        $tiers = [];

        foreach ( (array) $rows as $row ) {
            $tier = $this->hydrate( $row );

            if ( $tier instanceof Tier ) {
                $tiers[] = $tier;
            }
        }

        return $tiers;
    }

    /**
     * Locks the live rows until the caller's transaction ends, so two catalogue writes
     * can't both pass the count and uniqueness checks. Outside a transaction it locks nothing.
     *
     * @throws QueryException When the read fails.
     */
    public function lock_live(): void {
        global $wpdb;

        $result = $wpdb->query( 'SELECT id FROM `' . esc_sql( $this->table() ) . '` WHERE deleted_at IS NULL FOR UPDATE' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- a lock read with no values; the table name is escaped.

        if ( false === $result ) {
            throw new QueryException( 'select', esc_html( $wpdb->last_error ), esc_html( $this->table() . ' (lock_live)' ) );
        }
    }

    /**
     * Renumbers the live tiers 1..n by threshold. Deleted rows keep their last rank.
     *
     * @throws QueryException When the write fails.
     */
    public function rerank(): void {
        $ranks = [];
        $rank  = 1;

        foreach ( $this->live() as $tier ) {
            $ranks[ (int) $tier->get_id() ] = $rank;
            ++$rank;
        }

        $this->bulk_set_int_column( 'tier_rank', $ranks );
    }

    /** @throws QueryException When the write fails. */
    public function soft_delete( int $id, string $now ): void {
        $this->update(
            $id,
            [
                'deleted_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s' ]
        );
    }

    /**
     * Seeds Bronze, Silver and Gold only into a table that has never held a row, so a
     * merchant's deletions and edits survive every later install.
     *
     * @throws QueryException When a write fails.
     */
    public function seed_defaults( string $now ): void {
        if ( 0 !== $this->count_all() ) {
            return;
        }

        $defaults = [
            [ __( 'Bronze', 'yayloyalty' ), 0, '1.00', '#CD7F32' ],
            [ __( 'Silver', 'yayloyalty' ), 800, '1.25', '#C0C0C0' ],
            [ __( 'Gold', 'yayloyalty' ), 3000, '1.50', '#FFD700' ],
        ];

        foreach ( $defaults as $index => $tier ) {
            $this->insert( new Tier( null, $tier[0], $tier[1], $index + 1, $tier[2], 0, $tier[3], $now, $now, null ) );
        }
    }
}
