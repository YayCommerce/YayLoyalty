<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Rewards list. Read-only for now: create/edit/delete/redeem land with the
 * reward editor and the redemption engine. No service: a definition table,
 * same as CampaignRestController.
 */
final class RewardRestController extends AbstractRestController {

    /** @var RewardRepository */
    private $rewards;

    public function __construct( RewardRepository $rewards ) {
        $this->rewards = $rewards;
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

        return $this->respond_paginated( $result );
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_reward',
            'type'       => 'object',
            'properties' => [
                'id'          => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'name'        => [
                    'type'    => 'string',
                    'context' => [ 'view', 'edit' ],
                ],
                'slug'        => [
                    'description' => __( 'Stable machine key. Built-in is "cart-discount".', 'yayloyalty' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'type'        => [
                    'type'    => 'string',
                    'enum'    => RewardType::all(),
                    'context' => [ 'view', 'edit' ],
                ],
                'status'      => [
                    'description' => __( 'Merchant intent only. Liveness is derived from the dates.', 'yayloyalty' ),
                    'type'        => 'string',
                    'enum'        => Status::all(),
                    'context'     => [ 'view', 'edit' ],
                ],
                'sort_order'  => [
                    'description' => __( 'Display order in the launcher and catalog.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit' ],
                ],
                'cost_points' => [
                    'description' => __( 'Null for flexible_discount, the customer picks the amount; required for every other type.', 'yayloyalty' ),
                    'type'        => [ 'integer', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'config'      => [
                    'type'    => 'object',
                    'context' => [ 'view', 'edit' ],
                ],
                'conditions'  => [
                    'type'    => [ 'object', 'array' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'starts_at'   => [
                    'type'    => [ 'string', 'null' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'ends_at'     => [
                    'description' => __( 'Exclusive: a reward is not live at exactly this instant.', 'yayloyalty' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'is_builtin'  => [
                    'type'     => 'boolean',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_by'  => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at'  => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'updated_at'  => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
