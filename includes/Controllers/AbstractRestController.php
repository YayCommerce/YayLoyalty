<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared REST resource behaviour. Routes register from register_routes(),
 * never a constructor. Write args are built by filtering the item schema
 * (get_write_args()): passing the schema verbatim would let a client set
 * is_builtin or actor_id.
 */
abstract class AbstractRestController extends BaseRestController {

    /**
     * Server-owned on every resource; always stripped from write args regardless of schema flags.
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
     * JSON Schema for one item; properties declare type, context and readonly.
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

    /**
     * @param array<string, mixed> $property
     */
    protected function is_readonly_property( string $name, array $property ): bool {
        if ( in_array( $name, static::ALWAYS_READONLY, true ) ) {
            return true;
        }

        return ! empty( $property['readonly'] );
    }

    /**
     * @return array<string, mixed>
     */
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
     * Route param that must name an existing user; without the validate_callback the route becomes an enumeration oracle.
     *
     * @return array<string, mixed>
     */
    protected function user_id_param(): array {
        return [
            'description'       => __( 'WordPress user id.', 'yayloyalty' ),
            'type'              => 'integer',
            'required'          => true,
            'sanitize_callback' => 'absint',
            'validate_callback' => static function ( $value ) {
                $user_id = absint( $value );

                if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
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
     * @param array{items: mixed[], total: int, page: int, per_page: int} $result
     * @return array<string, mixed>
     */
    protected function respond_paginated( array $result ): array {
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
     * Empty page envelope at the request's page/per_page — the truthful answer
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
     * Ceiling guard for manual adjustments; administrators bypass it, 0 means unlimited.
     *
     * @param int|float|string $amount
     * @return true|\WP_Error
     */
    protected function assert_ceiling( $amount ) {
        $ceiling = Capabilities::ceiling();

        if ( $ceiling > 0 && abs( (int) $amount ) > $ceiling && ! Capabilities::is_administrator() ) {
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
}
