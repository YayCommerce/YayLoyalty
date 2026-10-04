<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for exactly one table. Never opens a transaction or touches a
 * second table (service concerns); every write throws, because
 * Transaction::run() commits unless a Throwable escapes; never memoizes the
 * table name (multisite switches blogs). Not a singleton: get_instance()
 * ignores constructor arguments after the first call.
 */
abstract class AbstractRepository {

    /** Ceiling on page size, whatever the caller asks for. */
    protected const MAX_PER_PAGE = 100;

    /** Table slug without prefix, e.g. 'ledger'. */
    abstract protected function table_slug(): string;

    /**
     * @return class-string<AbstractModel>
     */
    abstract protected function model_class(): string;

    /**
     * Columns paginate() accepts in orderby; it is interpolated into SQL, so never caller-supplied.
     *
     * @return string[]
     */
    abstract protected function sortable_columns(): array;

    /** Primary key column; override for tables keyed otherwise (customers: user_id). */
    protected function primary_key(): string {
        return 'id';
    }

    /** Fully-qualified table name. Never memoized: see Utils\Table. */
    public function table(): string {
        return Table::name( $this->table_slug() );
    }

    /**
     * @return AbstractModel|null
     */
    public function find( int $id ) {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

        $result = $wpdb->insert( $this->table(), $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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

        $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

    /**
     * @throws QueryException When the write fails.
     */
    public function delete( int $id ): bool {
        global $wpdb;

        $result = $wpdb->delete( $this->table(), [ $this->primary_key() => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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

        // A second ORDER BY column, so a page is stable when $orderby ties (equal priority,
        // equal status, …): without it MySQL may return ties in a different order per page,
        // repeating or skipping rows across pages. Ties go to the newest, as the repository
        // docblocks that promise this already say. Skipped when $orderby already is the key.
        $primary_key   = esc_sql( $this->primary_key() );
        $tie_break_sql = $orderby === $this->primary_key() ? '' : ', `' . $primary_key . '` DESC';

        // Interpolated identifiers are each constrained: $table is our own slug esc_sql()'d,
        // $orderby/$order are whitelisted literals, $where_sql fragments are already prepared.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- identifiers are whitelisted/escaped above; values are bound.
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' . $where_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

    /** Prepared LIKE fragment for a free-text search; esc_like() first or % and _ become wildcards. */
    protected function like_fragment( string $column, string $term ): string {
        global $wpdb;

        return $wpdb->prepare(
            '`' . esc_sql( $column ) . '` LIKE %s',
            '%' . $wpdb->esc_like( $term ) . '%'
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return AbstractModel
     */
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
