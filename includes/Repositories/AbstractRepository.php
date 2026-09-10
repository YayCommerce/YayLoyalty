<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for exactly one table.
 *
 * Hard constraints, and each one exists for a reason:
 *
 * - NEVER OPENS A TRANSACTION. Atomicity spans tables, so it belongs to a
 *   service. A repository that opened one would nest unpredictably.
 * - NEVER TOUCHES A SECOND TABLE. Cross-table work is a service concern.
 * - EVERY WRITE THROWS ON FAILURE. $wpdb->insert() returns false rather than
 *   throwing, and Transaction::run() commits unless a Throwable escapes — so a
 *   repository that returned false would let a failed write commit and report
 *   success. See QueryException.
 * - NEVER MEMOIZES the table name. Multisite activation switches blogs, and a
 *   cached prefix would write to the wrong blog.
 *
 * This class is NOT a singleton, and neither are its subclasses. See
 * docs/code-standards.md section 8: SingletonTrait::get_instance(...$args)
 * silently ignores arguments after the first call, so constructor injection and
 * that trait are incompatible, and its public static accessor would hand global
 * write access to anything that wanted it.
 */
abstract class AbstractRepository {

    /** Hard ceiling on page size, whatever the caller asks for. */
    protected const MAX_PER_PAGE = 100;

    /**
     * Table slug without prefix, e.g. 'ledger'.
     */
    abstract protected function table_slug(): string;

    /**
     * Fully-qualified model class for hydration.
     *
     * @return class-string<AbstractModel>
     */
    abstract protected function model_class(): string;

    /**
     * Columns this repository will accept in paginate()'s orderby.
     *
     * Whitelisted per repository: an orderby is interpolated into SQL and can
     * never be a caller-supplied string.
     *
     * @return string[]
     */
    abstract protected function sortable_columns(): array;

    /**
     * Primary key column. Override for tables keyed on something else, such as
     * customers, which is keyed on user_id.
     */
    protected function primary_key(): string {
        return 'id';
    }

    /**
     * Fully-qualified table name.
     *
     * Same source as the schema class that created the table — see Utils\Table,
     * including why the result must never be memoized.
     */
    public function table(): string {
        return Table::name( $this->table_slug() );
    }

    /**
     * Fetch one row by primary key.
     *
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
     * Insert a model.
     *
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

        // Tables with a natural key (customers.user_id) have no insert id.
        if ( 0 === (int) $wpdb->insert_id && isset( $data[ $this->primary_key() ] ) ) {
            return (int) $data[ $this->primary_key() ];
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update columns on one row.
     *
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

        // 0 means "no rows changed", which is not a failure.
        return true;
    }

    /**
     * Delete one row.
     *
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
     * Paginated query.
     *
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

        /*
         * Three identifiers are interpolated rather than bound, because $wpdb
         * placeholders cannot carry identifiers or SQL fragments. Each is
         * constrained so a caller cannot inject through it:
         *
         *   $table     — built from $wpdb->prefix and this class's own slug,
         *                then esc_sql()'d. Never caller-supplied.
         *   $orderby   — sanitize_orderby() returns a value from
         *                sortable_columns() or the primary key. A column name
         *                that is not whitelisted is discarded, not passed on.
         *   $order     — sanitize_order() returns the literal 'ASC' or 'DESC'.
         *   $where_sql — joined from fragments the caller already ran through
         *                $wpdb->prepare() (see like_fragment()). Repositories
         *                must never put a raw value in a where fragment.
         *
         * LIMIT and OFFSET are bound normally.
         */
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- identifiers are whitelisted/escaped above; values are bound.
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' . $where_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . $table . '`' . $where_sql
                . ' ORDER BY `' . esc_sql( $orderby ) . '` ' . $order
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

    /**
     * Build a prepared LIKE fragment for a free-text search.
     *
     * esc_like() first, or a search term containing % or _ becomes a wildcard
     * the caller never intended.
     */
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

    /**
     * Fall back to the primary key when the caller asks for a column that is not
     * whitelisted, rather than interpolating it.
     */
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
