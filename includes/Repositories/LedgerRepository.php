<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_ledger. Append-only: nothing here calls update() or delete(). */
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
     * The award path reads it twice. Before the insert it only saves an
     * AUTO_INCREMENT value on a duplicate the caller can already see under
     * its row lock: the unique index is the guarantee. After a collision it
     * names the row that won, which Outcome::duplicate carries.
     */
    public function find_by_idempotency_key( string $key ): ?LedgerEntry {
        return $this->select_entries( '`idempotency_key` = %s', [ $key ], 1 )[0] ?? null;
    }

    /**
     * The guarded insert. A UNIQUE `idempotency_key` collision is an answer
     * here, not a fault, so it comes back as null instead of an exception.
     * wpdb's error reporting is off for the attempt, so the guard firing
     * leaves no log line that reads like a broken write. Every other failure
     * still throws.
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
     * calendar day holding $at; a null source sums every source, for the
     * per-customer daily limit. Gross EARN only: adjustments and
     * refunds neither count nor free up room. Rows count by created_at, their
     * write time, so $at should be the moment being awarded. 0 for a guest.
     *
     * @param string      $at     UTC MySQL datetime.
     * @param string|null $source A LedgerSource constant, or null for all sources.
     */
    public function points_earned_today( ?int $user_id, string $at, ?string $source ): int {
        $day_start = Dates::site_day_start( $at );

        if ( null === $user_id || $user_id < 1 || null === $day_start ) {
            return 0;
        }

        $where  = 'user_id = %d AND type = %s';
        $values = [ $user_id, LedgerType::EARN ];

        if ( null !== $source ) {
            $where   .= ' AND source = %s';
            $values[] = $source;
        }

        return $this->sum_points(
            $where . ' AND created_at >= %s AND created_at < %s',
            array_merge( $values, [ Dates::to_utc( $day_start ), Dates::to_utc( $day_start->modify( '+1 day' ) ) ] )
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
     * Points already clawed back from one earn row. Never negative.
     *
     * An order earn is matched by order and campaign (one earn row per
     * trigger, each with its campaign), and nets out ADJUST rows referencing
     * a refund: without them a restored refund would under-claw the next one.
     * Any other earn is matched by its reference.
     *
     * @param bool $is_locking False for a read outside a transaction, such as an email check.
     */
    public function sum_refunded_for( LedgerEntry $earn, bool $is_locking = true ): int {
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

        // Locking by default: inside an outer transaction a plain SELECT reads its older snapshot and would over-claw.
        return max( 0, -$this->sum_points( 'user_id = %d AND ' . $scope, array_merge( [ $earn->get_user_id() ], $values ), $is_locking ) );
    }

    /**
     * One user's history, newest first, narrowed by the drawer's filters.
     *
     * @param array $args AbstractRepository::paginate() args plus history_where()'s
     *                    `types`/`search`/`after`/`before`.
     * @return array{items: LedgerEntry[], total: int, page: int, per_page: int}
     */
    public function paginate_for_user( int $user_id, array $args = [] ): array {
        global $wpdb;

        $args['where'] = array_merge(
            [ $wpdb->prepare( 'user_id = %d', $user_id ) ],
            $this->history_where( $args )
        );

        if ( ! isset( $args['orderby'] ) ) {
            $args['orderby'] = 'id';
        }

        return $this->paginate( $args );
    }

    /**
     * A user's newest rows, without paginate()'s COUNT(*): for a caller that never shows a total.
     *
     * @return LedgerEntry[]
     */
    public function latest_for_user( int $user_id, int $limit ): array {
        return $this->select_entries( 'user_id = %d', [ $user_id ], max( 1, $limit ), true );
    }

    /** COUNT(*) of a user's ledger rows, cheaper than paginate_for_user() when only the total is needed. */
    public function count_for_user( int $user_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d',
                $user_id
            )
        );
    }

    /**
     * Row count per raw `LedgerType` over a user's whole history. Types with
     * no rows are absent. Grouping into filter groups is the caller's.
     *
     * @return array<string, int>
     */
    public function count_by_type_for_user( int $user_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT type, COUNT(*) AS row_count FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d GROUP BY type',
                $user_id
            ),
            ARRAY_A
        );

        $counts = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $counts[ (string) $row['type'] ] = (int) $row['row_count'];
        }

        return $counts;
    }

    /**
     * A user's positive rows, newest first, read in pages until their points cover $cover: what
     * Services\LotBook needs to rebuild that balance's lots. Ordered by id, which orders history.
     *
     * @return array<int, array{id: int, points: int, created_at: string}>
     */
    public function positive_rows_newest_first( int $user_id, int $cover ): array {
        global $wpdb;

        $rows    = [];
        $sum     = 0;
        $before  = PHP_INT_MAX;
        $page_sz = 500;

        while ( $sum < $cover ) {
            $page = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
                $wpdb->prepare(
                    'SELECT id, points, created_at FROM `' . esc_sql( $this->table() ) . '`
                     WHERE user_id = %d AND points > 0 AND id < %d ORDER BY id DESC LIMIT %d',
                    $user_id,
                    $before,
                    $page_sz
                ),
                ARRAY_A
            );

            foreach ( is_array( $page ) ? $page : [] as $row ) {
                $rows[] = [
                    'id'         => (int) $row['id'],
                    'points'     => (int) $row['points'],
                    'created_at' => (string) $row['created_at'],
                ];
                $sum   += (int) $row['points'];
                $before = (int) $row['id'];

                if ( $sum >= $cover ) {
                    break;
                }
            }

            if ( ! is_array( $page ) || count( $page ) < $page_sz ) {
                break;
            }
        }

        return $rows;
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
     * @param string $before UTC MySQL datetime. A row at this exact second is excluded.
     */
    public function lifetime_earned_before( int $user_id, string $before ): int {
        $types = LedgerType::lifetime_earned_types();

        return $this->sum_points(
            'user_id = %d AND created_at < %s AND type IN (' . Sql::in_placeholders( $types ) . ')',
            array_merge( [ $user_id, $before ], $types )
        );
    }

    /**
     * The one definition of an "issued" ledger row, a positive row of an earn
     * type, as a prepared-SQL fragment plus the values it binds. Every SUM or
     * COUNT of points issued interpolates this, so the campaign list, the
     * performance page and the dashboard cannot disagree about what counts as
     * issued. `$prefix` qualifies the columns inside a join (`'l.'`).
     *
     * @return array{0: string, 1: string[]} The fragment and the types it binds, in order.
     */
    public static function issued_row_sql( string $prefix = '' ): array {
        $types = LedgerType::lifetime_earned_types();

        return [ "{$prefix}points > 0 AND {$prefix}type IN (" . Sql::in_placeholders( $types ) . ')', $types ];
    }

    /**
     * Points issued and row count per campaign. `points` sums only issued rows
     * (issued_row_sql()). `count` is every row of any type: the delete guard
     * needs "has any row ever named this campaign", which `points` cannot
     * answer once a campaign's awards are refunded. An id with no rows is absent.
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
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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
     * Rows of any type naming the campaign, as a locking read: inside delete_item()'s transaction a
     * plain read would see its snapshot and miss a row committed since.
     *
     * @throws QueryException When the read fails, e.g. a lock wait timeout.
     */
    public function count_for_campaign_locked( int $campaign_id ): int {
        global $wpdb;

        $count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a locking read must reach the database, never a cache.
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '` WHERE campaign_id = %d LOCK IN SHARE MODE',
                $campaign_id
            )
        );

        if ( '' !== $wpdb->last_error ) {
            throw new QueryException( 'select', $wpdb->last_error, $this->table() . ' (count_for_campaign_locked)' );
        }

        return (int) $count;
    }

    /**
     * Prepared WHERE fragments for the ledger filters: the drawer's, and any store-wide list.
     * Columns are unqualified, so a caller that joins another table must not join one that shares them.
     *
     * Row descriptions ("Earned on order #6730") are composed client-side, so
     * search can only reach what is stored: a bare or `#`-prefixed number is
     * also an exact `order_id`, and any text matches the admin's `note`.
     *
     * @param array{types?: string[], search?: string, after?: string, before?: string} $args
     *        `after`/`before` are site-calendar `Y-m-d` days, both inclusive.
     * @return string[] Already-prepared fragments, for AbstractRepository::paginate()'s `where`.
     */
    public function history_where( array $args ): array {
        global $wpdb;

        $where = [];
        $types = $args['types'] ?? [];

        if ( ! empty( $types ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder list only; values bound here.
            $where[] = $wpdb->prepare( 'type IN (' . Sql::in_placeholders( $types ) . ')', $types );
        }

        $where = array_merge( $where, $this->site_day_range_where( $args['after'] ?? '', $args['before'] ?? '' ) );

        $search = trim( $args['search'] ?? '' );

        if ( '' !== $search ) {
            $note_like_sql = $this->like_fragment( 'note', $search );
            $order_number  = ltrim( $search, '#' );

            $where[] = ctype_digit( $order_number )
                ? '(' . $wpdb->prepare( 'order_id = %d', (int) $order_number ) . ' OR ' . $note_like_sql . ')'
                : $note_like_sql;
        }

        return $where;
    }

    /**
     * SUM(points) over the rows a prepared WHERE fragment matches, or 0 when none.
     *
     * @param string $where      Placeholders only. Every value arrives in $values.
     * @param bool   $is_locking Read the latest committed rows and share-lock them, for callers inside a transaction.
     */
    private function sum_points( string $where, array $values, bool $is_locking = false ): int {
        global $wpdb;

        $lock_sql = $is_locking ? ' LOCK IN SHARE MODE' : '';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where carries placeholders only; every value is bound here.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare( 'SELECT COALESCE(SUM(points), 0) FROM `' . esc_sql( $this->table() ) . "` WHERE {$where}{$lock_sql}", $values )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * Rows a prepared WHERE fragment matches, oldest first.
     *
     * @param string $where Placeholders only. Every value arrives in $values.
     * @param int    $limit 0 for no limit.
     * @return LedgerEntry[]
     */
    private function select_entries( string $where, array $values, int $limit = 0, bool $is_newest_first = false ): array {
        global $wpdb;

        $limit_sql = $limit > 0 ? ' LIMIT ' . $limit : '';
        $order_sql = $is_newest_first ? 'DESC' : 'ASC';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where carries placeholders only; every value is bound here.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $this->table() ) . "` WHERE {$where} ORDER BY id {$order_sql}{$limit_sql}", $values ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return array_map( [ $this, 'hydrate' ], is_array( $rows ) ? $rows : [] );
    }
}
