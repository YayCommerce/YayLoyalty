<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * Shared REST resource behaviour.
 *
 * Routes are registered from register_routes(), never from a constructor, so
 * controllers can be constructed without side effects and remain testable.
 *
 * MASS ASSIGNMENT IS THE HAZARD HERE. get_item_schema() describes every column,
 * including server-owned ones. Handing that schema to register_rest_route() as
 * `args` verbatim would let a client set them: a PUT carrying is_builtin would
 * defeat the built-in delete guard, and one carrying actor_id would forge the
 * ledger audit trail. So writable args are BUILT BY FILTERING the schema
 * (get_write_args()), and readonly properties are removed rather than ignored.
 */
abstract class AbstractRestController extends BaseRestController {

    /**
     * Properties that are server-owned on every resource.
     *
     * Subclasses add their own via get_item_schema()'s readonly flags; these are
     * always stripped regardless.
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

    /**
     * URL segment for this resource, e.g. 'campaigns'.
     */
    abstract public function get_rest_base(): string;

    /**
     * Register this resource's routes. Called from Engine\RestAPI.
     */
    abstract public function register_routes(): void;

    /**
     * JSON Schema for one item.
     *
     * Every property should declare a `type`, a `context` (view and/or edit)
     * and `readonly` where the server owns the value.
     *
     * @return array<string, mixed>
     */
    final public function get_item_schema(): array {
        $schema = $this->define_item_schema();

        /**
         * JSON Schema for one item of a YayLoyalty resource.
         *
         * Premium's attach point for extending a resource it does not own — for
         * example describing Premium-only config keys on a campaign.
         *
         * FINAL and applied centrally so no subclass can bypass it by
         * overriding the accessor. Subclasses override define_item_schema().
         *
         * SECURITY: this filter cannot reopen mass assignment.
         * is_readonly_property() checks ALWAYS_READONLY first, so id,
         * created_at, updated_at, is_builtin, balance_after and actor_id stay
         * stripped from write args no matter what a subscriber returns.
         *
         * Internal seam for first-party Premium code, not a documented
         * third-party API.
         *
         * @param array<string, mixed> $schema
         * @param string               $rest_base
         */
        return apply_filters( 'yayloyalty_rest_item_schema', $schema, $this->get_rest_base() );
    }

    /**
     * The resource's own schema, before filtering.
     *
     * Override this, not get_item_schema().
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
     * Writable arguments, derived from the schema with readonly stripped.
     *
     * Use this for `args` on POST/PUT routes. Never pass get_item_schema()
     * straight through.
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

            // Strip presentation-only keys; keep validation-relevant ones.
            unset( $property['readonly'], $property['context'] );

            $args[ $name ] = $property;
        }

        return $args;
    }

    /**
     * Is this property server-owned?
     *
     * @param array<string, mixed> $property
     */
    protected function is_readonly_property( string $name, array $property ): bool {
        if ( in_array( $name, static::ALWAYS_READONLY, true ) ) {
            return true;
        }

        return ! empty( $property['readonly'] );
    }

    /**
     * Standard collection parameters.
     *
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
     * A numeric route parameter that must identify an existing WordPress user.
     *
     * Without the validate_callback the route accepts user 0 and becomes an
     * enumeration oracle.
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
     * Shape a repository paginate() result for the response.
     *
     * Matches PaginatedResponse<T> in apps/admin/src/lib/api/api.type.ts. The
     * envelope is camelCase while item fields are snake_case: deliberate, so the
     * admin app keeps one pagination type and PHP/TS field names stay aligned.
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
     * Capability required for this resource.
     *
     * Single method so the capability model can change in one place. Whether
     * minting points should require a dedicated capability rather than
     * manage_woocommerce is an open product question; see the plan.
     */
    public function capability(): string {
        /**
         * Capability required for YayLoyalty admin REST routes.
         *
         * @param string $capability
         * @param string $rest_base
         */
        return (string) apply_filters( 'yayloyalty_rest_capability', 'manage_woocommerce', $this->get_rest_base() );
    }

    public function permission_callback(): bool {
        return current_user_can( $this->capability() );
    }
}
