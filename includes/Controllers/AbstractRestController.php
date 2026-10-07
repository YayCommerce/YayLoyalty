<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Shared REST resource behaviour. Routes register from register_routes(),
 * never a constructor. Write args are built by filtering the item schema
 * (get_write_args()): passing the schema verbatim would let a client set
 * is_builtin or actor_id.
 */
abstract class AbstractRestController extends BaseRestController {

    /**
     * Server-owned on every resource. Always stripped from write args, whatever the schema flags say.
     *
     * @var string[]
     */
    protected const ALWAYS_READONLY = [
        'id',
        'created_at',
        'updated_at',
        'is_builtin',
        'balance_after',
        'actor_id',
    ];

    /** URL segment, e.g. 'campaigns'. */
    abstract public function get_rest_base(): string;

    /** Registers this resource's routes. Called from Engine\RestAPI. */
    abstract public function register_routes(): void;

    /**
     * JSON Schema for one item. Properties declare type, context and readonly.
     *
     * @return array<string, mixed>
     */
    final public function get_item_schema(): array {
        $schema = $this->define_item_schema();

        /**
         * JSON Schema for one item of a resource. Internal Pro seam. Cannot
         * reopen mass assignment: ALWAYS_READONLY is checked before the schema.
         *
         * @param array<string, mixed> $schema
         * @param string               $rest_base
         */
        return apply_filters( 'yayloyalty_rest_item_schema', $schema, $this->get_rest_base() );
    }

    /**
     * The resource's own schema, before filtering. Override this, not get_item_schema().
     *
     * @return array<string, mixed>
     */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => $this->get_rest_base(),
            'type'       => 'object',
            'properties' => [],
        ];
    }

    /**
     * Writable `args` for POST/PUT routes: the schema with readonly stripped.
     *
     * @return array<string, mixed>
     */
    public function get_write_args(): array {
        $schema     = $this->get_item_schema();
        $properties = isset( $schema['properties'] ) && is_array( $schema['properties'] )
            ? $schema['properties']
            : [];

        $args = [];

        foreach ( $properties as $name => $property ) {
            if ( $this->is_readonly_property( $name, $property ) ) {
                continue;
            }

            unset( $property['readonly'], $property['context'] );

            $args[ $name ] = $property;
        }

        return $args;
    }

    /** @param array<string, mixed> $property */
    protected function is_readonly_property( string $name, array $property ): bool {
        if ( in_array( $name, static::ALWAYS_READONLY, true ) ) {
            return true;
        }

        return ! empty( $property['readonly'] );
    }

    /** @return array<string, mixed> */
    public function get_collection_params(): array {
        return [
            'page'     => [
                'description'       => __( 'Current page of the collection.', 'yayloyalty' ),
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'description'       => __( 'Maximum number of items to return.', 'yayloyalty' ),
                'type'              => 'integer',
                'default'           => 20,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
            'orderby'  => [
                'description' => __( 'Column to sort by.', 'yayloyalty' ),
                'type'        => 'string',
            ],
            'order'    => [
                'description' => __( 'Sort direction.', 'yayloyalty' ),
                'type'        => 'string',
                'enum'        => [ 'asc', 'desc', 'ASC', 'DESC' ],
                'default'     => 'desc',
            ],
            'search'   => [
                'description'       => __( 'Free-text search term.', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Route param that must name a live WP user. Without the validate_callback the route becomes an enumeration oracle.
     *
     * @return array<string, mixed>
     */
    protected function user_id_param(): array {
        return $this->id_param( false );
    }

    /**
     * Route param that must name a loyalty member, live or deleted: the customers row outlives its WP user, and the
     * drawer exists to show that row. Only for member reads: writes and unrelated user ids take user_id_param().
     *
     * @return array<string, mixed>
     */
    protected function member_id_param(): array {
        return $this->id_param( true );
    }

    /**
     * The shared id arg behind user_id_param() and member_id_param().
     *
     * @return array<string, mixed>
     */
    private function id_param( bool $allow_deleted_member ): array {
        return [
            'description'       => __( 'WordPress user id.', 'yayloyalty' ),
            'type'              => 'integer',
            'required'          => true,
            'sanitize_callback' => 'absint',
            'validate_callback' => static function ( $value ) use ( $allow_deleted_member ) {
                $user_id = absint( $value );
                $exists  = $user_id >= 1
                    && ( get_userdata( $user_id )
                        || ( $allow_deleted_member && null !== ( new CustomerRepository() )->find_by_user_id( $user_id ) ) );

                if ( ! $exists ) {
                    return new \WP_Error(
                        'not_found',
                        __( 'No such user.', 'yayloyalty' ),
                        [ 'status' => 404 ]
                    );
                }

                return true;
            },
        ];
    }

    /**
     * Shapes a paginate() result as PaginatedResponse<T>: camelCase envelope, snake_case items.
     *
     * Public because CustomerListFormatter::respond_list() calls it on the
     * controller it is handed.
     *
     * @param array{items: mixed[], total: int, page: int, per_page: int} $result
     * @return array<string, mixed>
     */
    public function respond_paginated( array $result ): array {
        $per_page = max( 1, (int) $result['per_page'] );
        $total    = (int) $result['total'];

        $data = [];

        foreach ( $result['items'] as $item ) {
            $data[] = is_object( $item ) && method_exists( $item, 'to_array' )
                ? $item->to_array()
                : $item;
        }

        return [
            'currentPage' => (int) $result['page'],
            'totalPage'   => (int) ceil( $total / $per_page ),
            'totalItems'  => $total,
            'perPage'     => $per_page,
            'data'        => $data,
        ];
    }

    /**
     * Empty page envelope at the request's page and per_page: the truthful answer
     * for a collection whose table does not exist yet, not an error.
     *
     * @return array<string, mixed>
     */
    protected function respond_empty_page( \WP_REST_Request $request ): array {
        return $this->respond_paginated(
            [
                'items'    => [],
                'total'    => 0,
                'page'     => (int) $request['page'],
                'per_page' => (int) $request['per_page'],
            ]
        );
    }

    /**
     * Permission callback requiring $cap. Every admin route uses it; never '__return_true'.
     *
     * @return callable(): (true|\WP_Error)
     */
    protected function requires( string $cap ): callable {
        return function () use ( $cap ) {
            return current_user_can( $cap ) ? true : $this->forbidden( $cap );
        };
    }

    /** 403 naming the missing capability, so support can see which cap a role lacks. */
    protected function forbidden( string $cap ): \WP_Error {
        return new \WP_Error(
            'yayloyalty_forbidden',
            __( 'You do not have permission to do that.', 'yayloyalty' ),
            [
                'status'     => 403,
                'capability' => $cap,
            ]
        );
    }

    /**
     * Ceiling guard for manual adjustments. Administrators bypass it, and null means unlimited.
     *
     * @param int|float|string $amount
     * @return true|\WP_Error
     */
    protected function assert_ceiling( $amount ) {
        $ceiling = Capabilities::ceiling();

        if ( null !== $ceiling && abs( (int) $amount ) > $ceiling && ! Capabilities::is_administrator() ) {
            return new \WP_Error(
                'yayloyalty_adjustment_ceiling',
                sprintf(
                    /* translators: %s: maximum number of points */
                    __( 'Adjustments are limited to %s points.', 'yayloyalty' ),
                    number_format_i18n( $ceiling )
                ),
                [
                    'status'  => 403,
                    'ceiling' => $ceiling,
                ]
            );
        }

        return true;
    }

    /**
     * Route-arg check for a nullable date: empty clears it, anything else must
     * be a full UTC `Y-m-d H:i:s`, the shape the column stores and the SPA
     * sends. Without it a string strtotime() cannot read passes
     * dates_in_order() (it reads as 0) and lands in the DATETIME column as garbage.
     *
     * @param mixed $value
     */
    public static function validate_nullable_date( $value ): bool {
        if ( null === $value || '' === $value ) {
            return true;
        }

        $date = is_string( $value ) ? \DateTimeImmutable::createFromFormat( Dates::MYSQL_FORMAT, $value, new \DateTimeZone( 'UTC' ) ) : false;

        return false !== $date && $date->format( Dates::MYSQL_FORMAT ) === $value;
    }

    /** Whether the dates are in order: an unset side passes, and two set dates must run forward. ends_at is exclusive, so equal dates are empty. */
    protected static function dates_in_order( ?string $starts_at, ?string $ends_at ): bool {
        return null === $starts_at || null === $ends_at || Dates::to_timestamp( $starts_at ) < Dates::to_timestamp( $ends_at );
    }

    /**
     * The window an update leaves: each date the request sends, else the stored one. Null when
     * a moved date leaves it out of order. A body that touches neither date (pause, archive,
     * rename) passes as stored, or a row whose dates were never in order could never be
     * repaired or retired.
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    protected static function requested_window( \WP_REST_Request $request, ?string $stored_start, ?string $stored_end ): ?array {
        $starts_at = $request->has_param( 'starts_at' ) ? self::nullable_date( $request['starts_at'] ) : $stored_start;
        $ends_at   = $request->has_param( 'ends_at' ) ? self::nullable_date( $request['ends_at'] ) : $stored_end;
        $moves     = $request->has_param( 'starts_at' ) || $request->has_param( 'ends_at' );

        return $moves && ! self::dates_in_order( $starts_at, $ends_at ) ? null : [ $starts_at, $ends_at ];
    }

    /**
     * A request's date param as stored: empty means no bound.
     *
     * @param mixed $value
     */
    protected static function nullable_date( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return (string) $value;
    }

    /**
     * A slug derived from $source that $is_taken rejects for no row, numbered
     * on collision and capped to fit VARCHAR(64) with its suffix.
     *
     * @param callable(string): bool $is_taken
     */
    protected static function unique_slug( string $source, string $fallback, callable $is_taken ): string {
        $base = sanitize_title( $source );

        if ( '' === $base ) {
            $base = $fallback;
        }

        $base = substr( $base, 0, 60 );
        $slug = $base;
        $n    = 2;

        while ( $is_taken( $slug ) ) {
            $slug = substr( $base, 0, 60 - strlen( (string) $n ) - 1 ) . '-' . $n;
            ++$n;
        }

        return $slug;
    }
}
