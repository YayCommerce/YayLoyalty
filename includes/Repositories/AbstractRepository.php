<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for exactly one table. A repository never opens a transaction or
 * touches a second table: those are service concerns. Every write throws,
 * because Transaction::run() commits unless a Throwable escapes. Not a
 * singleton: get_instance() ignores constructor arguments after the first call.
 */
abstract class AbstractRepository {

    /** Ceiling on page size, whatever the caller asks for. */
    protected const MAX_PER_PAGE = 100;

    /** Table slug without prefix, e.g. 'ledger'. */
    abstract protected function table_slug(): string;

    /** @return class-string<AbstractModel> */
    abstract protected function model_class(): string;

    /**
     * Columns paginate() accepts in orderby. The column is interpolated into SQL, so this list is never caller-supplied.
     *
     * @return string[]
     */
    abstract protected function sortable_columns(): array;

    /** Primary key column. Override it for a table keyed otherwise, such as customers by user_id. */
    protected function primary_key(): string {
        return 'id';
    }

    /** Fully-qualified table name. Never memoized: see Utils\Table. */
    public function table(): string {
        return Table::name( $this->table_slug() );
    }

    /** @return AbstractModel|null */
    public function find( int $id ) {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE `' . esc_sql( $this->primary_key() ) . '` = %d',
                $id
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return $this->hydrate( $row );
    }

    /**
     * @return int Insert id, or the primary key value for tables that supply it.
     * @throws QueryException When the write fails.
     */
    public function insert( AbstractModel $model ): int {
        global $wpdb;

        $data   = $model->to_row();
        $format = $model->get_format();

        $result = $wpdb->insert( $this->table(), $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.

        if ( false === $result ) {
            throw new QueryException( 'insert', $wpdb->last_error, $this->table() );
        }

        // A natural key (customers.user_id) has no insert id.
        if ( 0 === (int) $wpdb->insert_id && isset( $data[ $this->primary_key() ] ) ) {
            return (int) $data[ $this->primary_key() ];
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     * @param string[]             $format Placeholders matching $data's order.
     * @throws QueryException When the write fails.
     */
    public function update( int $id, array $data, array $format = [] ): bool {
        global $wpdb;

        $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $this->table(),
            $data,
            [ $this->primary_key() => $id ],
            empty( $format ) ? null : $format,
            [ '%d' ]
        );

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() );
        }

        // 0 rows changed is not a failure.
        return true;
    }

    /** @throws QueryException When the write fails. */
    public function delete( int $id ): bool {
        global $wpdb;

        $result = $wpdb->delete( $this->table(), [ $this->primary_key() => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.

        if ( false === $result ) {
            throw new QueryException( 'delete', $wpdb->last_error, $this->table() );
        }

        return $result > 0;
    }

    /**
     * @param array $args {
     *     @type int      $page      1-based. Default 1.
     *     @type int      $per_page  Capped at MAX_PER_PAGE. Default 20.
     *     @type string   $orderby   Must appear in sortable_columns().
     *     @type string   $order     ASC or DESC.
     *     @type string[] $where     Already-prepared SQL fragments.
     * }
     * @return array{items: AbstractModel[], total: int, page: int, per_page: int}
     */
    public function paginate( array $args = [] ): array {
        global $wpdb;

        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = (int) ( $args['per_page'] ?? 20 );
        $per_page = max( 1, min( $per_page, static::MAX_PER_PAGE ) );

        $orderby = $this->sanitize_orderby( $args['orderby'] ?? null );
        $order   = $this->sanitize_order( $args['order'] ?? null );

        $where     = isset( $args['where'] ) && is_array( $args['where'] ) ? $args['where'] : [];
        $where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );

        $table = esc_sql( $this->table() );

        // A second ORDER BY column keeps a page stable when $orderby ties. Without it MySQL may
        // order ties differently per page, repeating or skipping rows. Ties go to the newest.
        // The tie-break is skipped when $orderby already is the key.
        $primary_key   = esc_sql( $this->primary_key() );
        $tie_break_sql = $orderby === $this->primary_key() ? '' : ', `' . $primary_key . '` DESC';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $table is our escaped slug, $orderby and $order are whitelisted, and the $where_sql fragments are already prepared.
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' . $where_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . $table . '`' . $where_sql
                . ' ORDER BY `' . esc_sql( $orderby ) . '` ' . $order . $tie_break_sql
                . ' LIMIT %d OFFSET %d',
                $per_page,
                ( $page - 1 ) * $per_page
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $items = [];

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $items[] = $this->hydrate( $row );
            }
        }

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /** COUNT(*) of one user's rows, for a table with a `user_id` column. */
    protected function count_where_user( int $user_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d',
                $user_id
            )
        );
    }

    /** Prepared LIKE fragment for a free-text search. esc_like() runs first, or % and _ would be wildcards. */
    protected function like_fragment( string $column, string $term ): string {
        global $wpdb;

        return $wpdb->prepare(
            '`' . esc_sql( $column ) . '` LIKE %s',
            '%' . $wpdb->esc_like( $term ) . '%'
        );
    }

    /**
     * Prepared `created_at` bounds for an inclusive range of site-calendar
     * `Y-m-d` days. An empty or invalid day adds no bound.
     *
     * @return string[]
     */
    protected function site_day_range_where( string $after, string $before ): array {
        global $wpdb;

        $where = [];
        $start = Dates::site_day_to_utc( trim( $after ) );
        $end   = Dates::site_day_to_utc( trim( $before ), '+1 day' );

        if ( null !== $start ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', $start );
        }

        if ( null !== $end ) {
            $where[] = $wpdb->prepare( 'created_at < %s', $end );
        }

        return $where;
    }

    /** @param array<string, mixed> $row */
    protected function hydrate( array $row ): AbstractModel {
        $class = $this->model_class();

        return $class::from_row( $row );
    }

    /** Falls back to the primary key for a column not in sortable_columns(). */
    private function sanitize_orderby( ?string $orderby ): string {
        $allowed = $this->sortable_columns();

        if ( null !== $orderby && in_array( $orderby, $allowed, true ) ) {
            return $orderby;
        }

        return $this->primary_key();
    }

    private function sanitize_order( ?string $order ): string {
        return ( null !== $order && 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';
    }
}
