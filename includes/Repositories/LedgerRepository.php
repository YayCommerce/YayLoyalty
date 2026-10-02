<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_ledger. Append-only: nothing here calls update() or delete(). Not final: CustomerActivityServiceTest extends it as a repository double. */
class LedgerRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'ledger';
    }

    protected function model_class(): string {
        return LedgerEntry::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'created_at', 'points', 'type' ];
    }

    /**
     * The row an idempotency key already produced, or null.
     *
     * Read twice on the award path, for different reasons. Before the insert
     * it is only an optimisation — the unique index is the guarantee, and
     * this SELECT merely avoids burning an AUTO_INCREMENT value on a
     * duplicate the caller can already see under its row lock. After a
     * collision it is how the writer names the row that won, which
     * Outcome::duplicate carries.
     */
    public function find_by_idempotency_key( string $key ): ?LedgerEntry {
        return $this->select_entries( '`idempotency_key` = %s', [ $key ], 1 )[0] ?? null;
    }

    /**
     * The guarded insert: a UNIQUE `idempotency_key` collision is an answer
     * here, not a fault, so it comes back as null instead of an exception.
     * wpdb's error reporting is off for the attempt — the guard firing is
     * the system working, and it must not leave a line in the log that reads
     * like a broken write. Every other failure still throws.
     *
     * @return int|null Insert id, or null when the key was already present.
     * @throws QueryException When the insert failed for any other reason.
     */
    public function insert_unless_duplicate( LedgerEntry $entry ): ?int {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );

        try {
            return $this->insert( $entry );
        } catch ( QueryException $e ) {
            if ( ! $e->is_duplicate_key() ) {
                throw $e;
            }

            return null;
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
    }

    /**
     * SUM(points) of one source's earn rows written on the site-timezone
     * calendar day holding $at, for CapPolicy's daily_cap. Per source, so a
     * purchase never uses up a review cap. Rows count by created_at, their
     * write time, so $at should be the moment being awarded. 0 for a guest.
     *
     * @param string $at     UTC MySQL datetime.
     * @param string $source A LedgerSource constant.
     */
    public function points_earned_today( ?int $user_id, string $at, string $source ): int {
        $day_start = Dates::site_day_start( $at );

        if ( null === $user_id || $user_id < 1 || null === $day_start ) {
            return 0;
        }

        return $this->sum_points(
            'user_id = %d AND type = %s AND source = %s AND created_at >= %s AND created_at < %s',
            [ $user_id, LedgerType::EARN, $source, Dates::to_utc( $day_start ), Dates::to_utc( $day_start->modify( '+1 day' ) ) ]
        );
    }

    /**
     * An order's earn rows, one per trigger that paid, oldest first.
     *
     * @return LedgerEntry[]
     */
    public function earn_rows_for_order( int $order_id ): array {
        return $this->select_entries( 'type = %s AND order_id = %d', [ LedgerType::EARN, $order_id ] );
    }

    /** The earn row a non-order reference paid (a review's comment), or null. */
    public function find_earn_for_reference( string $reference_type, int $reference_id ): ?LedgerEntry {
        return $this->select_entries(
            'type = %s AND reference_type = %s AND reference_id = %d',
            [ LedgerType::EARN, $reference_type, $reference_id ],
            1
        )[0] ?? null;
    }

    /**
     * The clawback rows one refund wrote, one per earn row it reversed.
     *
     * @return LedgerEntry[]
     */
    public function refund_rows_for_refund( int $refund_id ): array {
        return $this->select_entries(
            'type = %s AND reference_type = %s AND reference_id = %d',
            [ LedgerType::REFUND_EARN, ReferenceType::REFUND, $refund_id ]
        );
    }

    /**
     * Points already clawed back from one earn row; never negative.
     *
     * An order earn is matched by order and campaign (one earn row per
     * trigger, each with its campaign), and nets out ADJUST rows referencing
     * a refund: without them a restored refund would under-claw the next one.
     * Any other earn is matched by its reference.
     */
    public function sum_refunded_for( LedgerEntry $earn ): int {
        if ( null !== $earn->get_order_id() ) {
            $campaign = null === $earn->get_campaign_id() ? 'campaign_id IS NULL' : 'campaign_id = %d';
            $scope    = "order_id = %d AND {$campaign} AND (type = %s OR (type = %s AND reference_type = %s))";
            $values   = array_merge(
                [ $earn->get_order_id() ],
                null === $earn->get_campaign_id() ? [] : [ (int) $earn->get_campaign_id() ],
                [ LedgerType::REFUND_EARN, LedgerType::ADJUST, ReferenceType::REFUND ]
            );
        } else {
            $scope  = 'reference_type = %s AND reference_id = %d AND type = %s';
            $values = [ (string) $earn->get_reference_type(), (int) $earn->get_reference_id(), LedgerType::REFUND_EARN ];
        }

        // A locking read: inside an outer transaction a plain SELECT reads its older snapshot and would over-claw.
        return max( 0, -$this->sum_points( 'user_id = %d AND ' . $scope, array_merge( [ $earn->get_user_id() ], $values ), true ) );
    }

    /**
     * One user's history, newest first.
     *
     * @return array{items: LedgerEntry[], total: int, page: int, per_page: int}
     */
    public function paginate_for_user( int $user_id, array $args = [] ): array {
        global $wpdb;

        $args['where'] = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];

        if ( ! isset( $args['orderby'] ) ) {
            $args['orderby'] = 'id';
        }

        return $this->paginate( $args );
    }

    /** COUNT(*) of a user's ledger rows — cheaper than paginate_for_user() when only the total is needed. */
    public function count_for_user( int $user_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d',
                $user_id
            )
        );
    }

    /** SUM(points) for a user, the ledger-side half of the balance invariant. Reserved for a rebuild tool. */
    public function sum_points_for_user( int $user_id ): int {
        return $this->sum_points( 'user_id = %d', [ $user_id ] );
    }

    /**
     * A user's lifetime_earned as it stood before $before: the signed SUM of
     * lifetime_earned_types() rows created strictly earlier. The same rule as
     * customers.lifetime_earned, frozen at a moment, so an event is scored
     * without the points it (or anything after it) produced.
     *
     * @param string $before UTC MySQL datetime; a row at this exact second is excluded.
     */
    public function lifetime_earned_before( int $user_id, string $before ): int {
        $types = LedgerType::lifetime_earned_types();

        return $this->sum_points(
            'user_id = %d AND created_at < %s AND type IN (' . Sql::in_placeholders( $types ) . ')',
            array_merge( [ $user_id, $before ], $types )
        );
    }

    /**
     * The one definition of an "issued" ledger row — a positive row of an earn
     * type — as a prepared-SQL fragment plus the values it binds. Every SUM or
     * COUNT of points issued (campaign list, performance page, dashboard tiles
     * and charts) interpolates this rather than spelling the predicate out, so
     * they cannot disagree about what counts as issued. `$prefix` qualifies the
     * columns inside a join (`'l.'`).
     *
     * @return array{0: string, 1: string[]} The fragment and the types it binds, in order.
     */
    public static function issued_row_sql( string $prefix = '' ): array {
        $types = LedgerType::lifetime_earned_types();

        return [ "{$prefix}points > 0 AND {$prefix}type IN (" . Sql::in_placeholders( $types ) . ')', $types ];
    }

    /**
     * Points issued and row count per campaign. `points` sums only issued rows
     * (issued_row_sql()), the measure the performance page and dashboard use, so
     * the list and the page cannot disagree. `count` is every row of any type:
     * the delete guard needs "has any row ever named this campaign", which
     * `points` cannot answer once a campaign's awards are refunded. Absent
     * from the result entirely for an id with no rows at all.
     *
     * @param int[] $campaign_ids
     * @return array<int, array{points: int, count: int}>
     */
    public function totals_by_campaign( array $campaign_ids ): array {
        if ( empty( $campaign_ids ) ) {
            return [];
        }

        global $wpdb;

        list( $issued, $types ) = self::issued_row_sql();

        $ids = array_map( 'intval', $campaign_ids );
        $in  = Sql::in_placeholders( $ids, '%d' );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in and $issued carry placeholder lists; every value is bound below.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT campaign_id, COALESCE(SUM(CASE WHEN {$issued} THEN points ELSE 0 END), 0) AS points, COUNT(*) AS row_count
                 FROM `" . esc_sql( $this->table() ) . "`
                 WHERE campaign_id IN ({$in})
                 GROUP BY campaign_id",
                array_merge( $types, $ids )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $totals = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $totals[ (int) $row['campaign_id'] ] = [
                'points' => (int) $row['points'],
                'count'  => (int) $row['row_count'],
            ];
        }

        return $totals;
    }

    /**
     * SUM(points) over the rows a prepared WHERE fragment matches; 0 when none.
     *
     * @param string  $where      Placeholders only; every value arrives in $values.
     * @param mixed[] $values
     * @param bool    $is_locking Read the latest committed rows and share-lock them (callers inside a transaction).
     */
    private function sum_points( string $where, array $values, bool $is_locking = false ): int {
        global $wpdb;

        $lock_sql = $is_locking ? ' LOCK IN SHARE MODE' : '';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where carries placeholders only; every value is bound here.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare( 'SELECT COALESCE(SUM(points), 0) FROM `' . esc_sql( $this->table() ) . "` WHERE {$where}{$lock_sql}", $values )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * Rows a prepared WHERE fragment matches, oldest first.
     *
     * @param string  $where  Placeholders only; every value arrives in $values.
     * @param mixed[] $values
     * @param int     $limit  0 for no limit.
     * @return LedgerEntry[]
     */
    private function select_entries( string $where, array $values, int $limit = 0 ): array {
        global $wpdb;

        $limit_sql = $limit > 0 ? ' LIMIT ' . $limit : '';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where carries placeholders only; every value is bound here.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $this->table() ) . "` WHERE {$where} ORDER BY id ASC{$limit_sql}", $values ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return array_map( [ $this, 'hydrate' ], is_array( $rows ) ? $rows : [] );
    }
}
