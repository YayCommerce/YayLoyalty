<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Rewards list, read, write and display order. Same shape as
 * CampaignRestController: no service, the invariants are validation, and a
 * write is validated in full before any setter runs. No redeem route yet.
 *
 * $redemptions is read-only here (is_deletable/redemption_summary
 * decoration), the same exception CampaignRestController makes for $ledger.
 */
final class RewardRestController extends AbstractRestController {

    /** Far above any real catalog; bounds the reorder request body. */
    private const MAX_REORDER_IDS = 500;

    /** @var RewardRepository */
    private $rewards;

    /** @var RedemptionRepository */
    private $redemptions;

    public function __construct( RewardRepository $rewards, RedemptionRepository $redemptions ) {
        $this->rewards     = $rewards;
        $this->redemptions = $redemptions;
    }

    public function get_rest_base(): string {
        return 'rewards';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/rewards',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_items' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_REWARDS ),
                    'args'                => array_merge(
                        $this->get_collection_params(),
                        [
                            // all_known(), not all(): a stored Pro-type row (e.g. after a downgrade) must stay filterable even where this build cannot offer creating one.
                            'type'   => [
                                'type' => 'string',
                                'enum' => RewardType::all_known(),
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => Status::all(),
                            ],
                            // Display order, not the collection base's newest-first default.
                            'order'  => [
                                'type'    => 'string',
                                'enum'    => [ 'asc', 'desc', 'ASC', 'DESC' ],
                                'default' => 'asc',
                            ],
                        ]
                    ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'create_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_REWARDS ),
                    'args'                => $this->get_write_args(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/rewards/reorder',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'reorder_items' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_REWARDS ),
                    'args'                => [
                        'ids' => [
                            'description' => __( 'Reward ids in display order; each gets its position as sort_order.', 'yayloyalty' ),
                            'type'        => 'array',
                            'items'       => [ 'type' => 'integer' ],
                            'required'    => true,
                            'minItems'    => 1,
                            'maxItems'    => self::MAX_REORDER_IDS,
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/rewards/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_REWARDS ),
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'update_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_REWARDS ),
                    // No `type`: immutable after create, and a stored Pro type must not fail this build's enum.
                    'args'                => array_diff_key( $this->get_write_args(), [ 'type' => true ] ),
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'delete_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_REWARDS ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_items( \WP_REST_Request $request ) {
        $result = $this->rewards->paginate_filtered(
            [
                'page'     => (int) $request['page'],
                'per_page' => (int) $request['per_page'],
                'orderby'  => $request['orderby'] ? (string) $request['orderby'] : 'sort_order',
                'order'    => $request['order'] ? (string) $request['order'] : 'asc',
                'type'     => $request['type'] ? (string) $request['type'] : '',
                'status'   => $request['status'] ? (string) $request['status'] : '',
                'search'   => $request['search'] ? (string) $request['search'] : '',
            ]
        );

        $result['items'] = $this->decorate( $result['items'] );

        return $this->respond_paginated( $result );
    }

    public function get_item( \WP_REST_Request $request ) {
        $reward = $this->rewards->find_reward( (int) $request['id'] );

        if ( null === $reward ) {
            return $this->error_not_found( __( 'Reward not found.', 'yayloyalty' ) );
        }

        return $this->decorate( [ $reward ] )[0];
    }

    /** Creates a catalog reward; flexible_discount is never created, only the seeded built-in exists. */
    public function create_item( \WP_REST_Request $request ) {
        $type = (string) $request['type'];

        if ( ! RewardType::is_valid( $type ) || RewardType::FLEXIBLE_DISCOUNT === $type ) {
            return $this->error_invalid_arguments( __( 'This reward type cannot be created.', 'yayloyalty' ) );
        }

        $name        = trim( (string) $request['name'] );
        $cost_points = self::nullable_int( $request['cost_points'] );
        $config      = (array) ( $request['config'] ?? [] );
        $conditions  = (array) ( $request['conditions'] ?? [] );
        $starts_at   = self::nullable_date( $request['starts_at'] );
        $ends_at     = self::nullable_date( $request['ends_at'] );

        $error = self::first_error(
            [
                $this->name_error( $name ),
                $this->cost_points_error( $type, $cost_points ),
                $this->config_error( $type, $config ),
                $this->conditions_error( $conditions ),
            ]
        );

        if ( null !== $error ) {
            return $error;
        }

        if ( ! self::dates_in_order( $starts_at, $ends_at ) ) {
            return $this->error_invalid_arguments( __( 'The reward must end after it starts.', 'yayloyalty' ) );
        }

        $status = $request['status'] ? (string) $request['status'] : Status::ACTIVE;

        if ( ! in_array( $status, [ Status::ACTIVE, Status::DRAFT ], true ) ) {
            return $this->error_invalid_arguments( __( 'A new reward starts active or as a draft.', 'yayloyalty' ) );
        }

        $slug = self::unique_slug(
            $request['slug'] ? (string) $request['slug'] : $name,
            'reward',
            fn( string $candidate ) => null !== $this->rewards->find_by_slug( $candidate )
        );

        $now    = Dates::now();
        $reward = new Reward(
            null,
            $name,
            $slug,
            $type,
            $status,
            $this->rewards->next_sort_order(),
            $cost_points,
            [],
            [],
            $starts_at,
            $ends_at,
            false, // is_builtin is never settable from a request.
            get_current_user_id(),
            get_current_user_id(),
            $now,
            $now
        );

        // Through the setters so config is whitelisted and conditions normalised.
        $reward->set_config( $config );
        $reward->set_conditions( $conditions );

        $id      = $this->rewards->insert( $reward );
        $created = $this->rewards->find_reward( $id );

        return $this->decorate( [ null === $created ? $reward : $created ] )[0];
    }

    /**
     * Saves a display order: the listed rewards come first in the given
     * order, every other row after them in its current order. A duplicate or
     * unknown id refuses the whole request.
     */
    public function reorder_items( \WP_REST_Request $request ) {
        $ids = array_map( 'intval', (array) $request['ids'] );

        if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
            return $this->error_invalid_arguments( __( 'A reward appears twice in the new order.', 'yayloyalty' ) );
        }

        if ( ! $this->rewards->reorder( $ids ) ) {
            return $this->error_invalid_arguments( __( 'The new order names a reward that no longer exists.', 'yayloyalty' ) );
        }

        return [ 'ids' => $ids ];
    }

    /** Applies only the fields the body carries; type and slug are immutable after create. */
    public function update_item( \WP_REST_Request $request ) {
        $reward = $this->rewards->find_reward( (int) $request['id'] );

        if ( null === $reward ) {
            return $this->error_not_found( __( 'Reward not found.', 'yayloyalty' ) );
        }

        $changes = self::requested_changes( $request );
        $error   = $this->update_error( $reward, $changes );

        if ( null !== $error ) {
            return $error;
        }

        // A body that touches neither date (pause, archive, rename) must still go through on a row
        // whose stored dates were never in order, or that row could never be repaired or retired.
        $moves_dates = $request->has_param( 'starts_at' ) || $request->has_param( 'ends_at' );
        $starts_at   = $request->has_param( 'starts_at' ) ? self::nullable_date( $request['starts_at'] ) : $reward->get_starts_at();
        $ends_at     = $request->has_param( 'ends_at' ) ? self::nullable_date( $request['ends_at'] ) : $reward->get_ends_at();

        if ( $moves_dates && ! self::dates_in_order( $starts_at, $ends_at ) ) {
            return $this->error_invalid_arguments( __( 'The reward must end after it starts.', 'yayloyalty' ) );
        }

        self::apply_update( $reward, $changes );
        $reward->set_starts_at( $starts_at );
        $reward->set_ends_at( $ends_at );

        $update = $reward->to_update_row();
        $this->rewards->update( (int) $reward->get_id(), $update['data'], $update['format'] );

        return $this->decorate( [ $reward ] )[0];
    }

    public function delete_item( \WP_REST_Request $request ) {
        $reward = $this->rewards->find_reward( (int) $request['id'] );

        if ( null === $reward ) {
            return $this->error_not_found( __( 'Reward not found.', 'yayloyalty' ) );
        }

        if ( $reward->is_builtin() ) {
            return $this->error_forbidden( __( 'Built-in rewards cannot be deleted.', 'yayloyalty' ) );
        }

        $id      = (int) $reward->get_id();
        $summary = $this->redemptions->summary_by_rewards( [ $id ] )[ $id ] ?? RedemptionRepository::empty_summary();

        if ( ! self::is_deletable( $reward, $summary['claimed'] ) ) {
            return $this->error_conflict( __( 'This reward has been claimed. Archive it instead of deleting it.', 'yayloyalty' ) );
        }

        // Unguarded gap: nothing locks between this check and the delete below, and
        // redemptions.reward_id is a plain KEY, not a FOREIGN KEY, so a claim written into that
        // gap would be orphaned rather than rejected. Not reachable today — nothing writes
        // redemptions yet (see Engine/Redemption/) — revisit once the claim path is registered.
        $this->rewards->delete( $id );

        return [
            'deleted' => true,
            'id'      => $id,
        ];
    }

    /**
     * The update fields the body carries, cast once so validation and the
     * write read the same values. cost_points is present even when null:
     * null is a real value for it.
     *
     * @return array{name?: string, status?: string, cost_points?: int|null, config?: array, conditions?: array}
     */
    private static function requested_changes( \WP_REST_Request $request ): array {
        $changes = [];

        if ( null !== $request['name'] ) {
            $changes['name'] = trim( (string) $request['name'] );
        }

        if ( null !== $request['status'] ) {
            $changes['status'] = (string) $request['status'];
        }

        if ( $request->has_param( 'cost_points' ) ) {
            $changes['cost_points'] = self::nullable_int( $request['cost_points'] );
        }

        if ( null !== $request['config'] ) {
            $changes['config'] = (array) $request['config'];
        }

        if ( null !== $request['conditions'] ) {
            $changes['conditions'] = (array) $request['conditions'];
        }

        return $changes;
    }

    /** Why an update must be refused before anything is written, or null when it may proceed. */
    private function update_error( Reward $reward, array $changes ): ?\WP_Error {
        // Checked here rather than left to set_status()'s exception: a rejected request must
        // answer 403, not fall into exec_write()'s 500 path.
        if ( Status::ARCHIVED === ( $changes['status'] ?? '' ) && ! $reward->can_archive() ) {
            return $this->error_forbidden( __( 'The default reward cannot be archived.', 'yayloyalty' ) );
        }

        $type   = $reward->get_type();
        $checks = [];

        if ( isset( $changes['name'] ) ) {
            $checks[] = $this->name_error( $changes['name'] );
        }

        if ( array_key_exists( 'cost_points', $changes ) ) {
            $checks[] = $this->cost_points_error( $type, $changes['cost_points'] );
        }

        if ( isset( $changes['config'] ) ) {
            $checks[] = $this->config_error( $type, $changes['config'] );
        }

        if ( isset( $changes['conditions'] ) ) {
            $checks[] = $this->conditions_error( $changes['conditions'] );
        }

        return self::first_error( $checks );
    }

    /**
     * @param array<\WP_Error|null> $checks
     */
    private static function first_error( array $checks ): ?\WP_Error {
        foreach ( $checks as $error ) {
            if ( null !== $error ) {
                return $error;
            }
        }

        return null;
    }

    private function name_error( string $name ): ?\WP_Error {
        return '' === $name ? $this->error_invalid_arguments( __( 'A reward needs a name.', 'yayloyalty' ) ) : null;
    }

    private function cost_points_error( string $type, ?int $cost_points ): ?\WP_Error {
        return Reward::cost_points_is_valid( $type, $cost_points )
            ? null
            : $this->error_invalid_arguments( __( 'The reward needs a cost of at least 1 point.', 'yayloyalty' ) );
    }

    private function config_error( string $type, array $config ): ?\WP_Error {
        return Reward::config_is_valid( $type, $config )
            ? null
            : $this->error_invalid_arguments( __( 'The reward config has an unknown key or a value of the wrong type.', 'yayloyalty' ) );
    }

    private function conditions_error( array $conditions ): ?\WP_Error {
        return Reward::conditions_are_valid( $conditions )
            ? null
            : $this->error_invalid_arguments( __( 'The reward conditions are malformed.', 'yayloyalty' ) );
    }

    private static function apply_update( Reward $reward, array $changes ): void {
        if ( isset( $changes['name'] ) ) {
            $reward->set_name( $changes['name'] );
        }

        if ( isset( $changes['status'] ) ) {
            $reward->set_status( $changes['status'] );
        }

        if ( array_key_exists( 'cost_points', $changes ) ) {
            $reward->set_cost_points( $changes['cost_points'] );
        }

        if ( isset( $changes['config'] ) ) {
            $reward->set_config( $changes['config'] );
        }

        if ( isset( $changes['conditions'] ) ) {
            $reward->set_conditions( $changes['conditions'] );
        }

        $reward->set_updated_at( Dates::now() );
        $reward->set_updated_by( get_current_user_id() );
    }

    /**
     * @param mixed $value
     */
    private static function nullable_int( $value ): ?int {
        return null === $value ? null : (int) $value;
    }

    /**
     * Adds the two redemption-derived fields to each reward's wire shape, in
     * one batched query rather than one per row. `is_deletable` is
     * delete_item()'s own guard restated for the client, so the two can never
     * disagree about what a delete would do.
     *
     * @param Reward[] $rewards
     * @return array<int, array<string, mixed>>
     */
    private function decorate( array $rewards ): array {
        $ids       = array_map( static fn( Reward $reward ) => (int) $reward->get_id(), $rewards );
        $summaries = $this->redemptions->summary_by_rewards( $ids );

        return array_map(
            static function ( Reward $reward ) use ( $summaries ) {
                $summary = $summaries[ (int) $reward->get_id() ] ?? RedemptionRepository::empty_summary();

                return $reward->to_array() + [
                    'is_deletable'       => self::is_deletable( $reward, $summary['claimed'] ),
                    'redemption_summary' => $summary,
                ];
            },
            $rewards
        );
    }

    /**
     * Whether $reward may be deleted: never the built-in (the Free UI binds to
     * it), and only with no redemption row naming it. Rows in any status, never
     * points: a refunded claim still names the reward in customer history.
     */
    private static function is_deletable( Reward $reward, int $redemption_rows ): bool {
        return ! $reward->is_builtin() && 0 === $redemption_rows;
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_reward',
            'type'       => 'object',
            'properties' => [
                'id'                 => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'name'               => [
                    'type'    => 'string',
                    'context' => [ 'view', 'edit' ],
                ],
                'slug'               => [
                    'description' => sprintf(
                        /* translators: %s: the built-in reward's slug. */
                        __( 'Stable machine key, generated on create and immutable afterwards. Built-in is "%s".', 'yayloyalty' ),
                        RewardRepository::CART_DISCOUNT_SLUG
                    ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'type'               => [
                    'type'    => 'string',
                    'enum'    => RewardType::all(),
                    'context' => [ 'view', 'edit' ],
                ],
                'status'             => [
                    'description' => __( 'Merchant intent only. Liveness is derived from the dates.', 'yayloyalty' ),
                    'type'        => 'string',
                    'enum'        => Status::all(),
                    'context'     => [ 'view', 'edit' ],
                ],
                'sort_order'         => [
                    'description' => __( 'Display order in the launcher and catalog. Written only by the reorder route.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'cost_points'        => [
                    'description' => __( 'Null for flexible_discount, the customer picks the amount; required for every other type.', 'yayloyalty' ),
                    'type'        => [ 'integer', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'config'             => [
                    'type'    => 'object',
                    'context' => [ 'view', 'edit' ],
                ],
                'conditions'         => [
                    'type'    => [ 'object', 'array' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'starts_at'          => [
                    'type'              => [ 'string', 'null' ],
                    'context'           => [ 'view', 'edit' ],
                    'validate_callback' => [ self::class, 'validate_nullable_date' ],
                ],
                'ends_at'            => [
                    'description'       => __( 'Exclusive: a reward is not live at exactly this instant.', 'yayloyalty' ),
                    'type'              => [ 'string', 'null' ],
                    'context'           => [ 'view', 'edit' ],
                    'validate_callback' => [ self::class, 'validate_nullable_date' ],
                ],
                'is_builtin'         => [
                    'type'     => 'boolean',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_by'         => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at'         => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'updated_at'         => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'is_deletable'       => [
                    'description' => __( 'Not built-in and never claimed. DELETE answers 409 otherwise.', 'yayloyalty' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'redemption_summary' => [
                    'description' => __( 'Claim figures from the redemptions table; zeros and nulls until it exists. Outstanding means an issued coupon that has not expired.', 'yayloyalty' ),
                    'type'        => 'object',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                    'properties'  => [
                        'claimed'               => [ 'type' => 'integer' ],
                        'outstanding'           => [ 'type' => 'integer' ],
                        'outstanding_customers' => [ 'type' => 'integer' ],
                        'outstanding_points'    => [ 'type' => 'integer' ],
                        'first_claimed_at'      => [ 'type' => [ 'string', 'null' ] ],
                        'last_expires_at'       => [ 'type' => [ 'string', 'null' ] ],
                    ],
                ],
            ],
        ];
    }
}
