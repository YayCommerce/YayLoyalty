<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/redemptions: one customer's redemption rows from the
 * `yayloyalty_redemptions` table, newest first. Its total matches `counts.redemptions`
 * from CustomerItemFormatter::format(). Read-only.
 */
final class RedemptionsRestController extends AbstractRestController {

    /** @var RedemptionRepository Read-only here. */
    private $redemptions;

    /** @var RewardRepository Read-only: the reward's name. */
    private $rewards;

    public function __construct( RedemptionRepository $redemptions, RewardRepository $rewards ) {
        $this->redemptions = $redemptions;
        $this->rewards     = $rewards;
    }

    public function get_rest_base(): string {
        return 'redemptions';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)/redemptions',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_history' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => array_merge(
                        [ 'id' => $this->member_id_param() ],
                        $this->get_collection_params()
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_history( \WP_REST_Request $request ) {
        $result = $this->redemptions->paginate_for_user(
            (int) $request['id'],
            [
                'page'     => (int) $request['page'],
                'per_page' => (int) $request['per_page'],
                'order'    => 'DESC',
            ]
        );

        $now   = Dates::now();
        $names = [];
        $items = [];

        foreach ( $result['items'] as $redemption ) {
            if ( ! $redemption instanceof Redemption ) {
                continue;
            }

            $reward_id = $redemption->get_reward_id();

            // One lookup per distinct reward on the page.
            if ( ! isset( $names[ $reward_id ] ) ) {
                $reward              = $this->rewards->find_reward( $reward_id );
                $names[ $reward_id ] = null === $reward ? '' : $reward->get_name();
            }

            $items[] = [
                'id'          => (int) $redemption->get_id(),
                'reward_id'   => $reward_id,
                'reward_name' => $names[ $reward_id ],
                'order_id'    => $redemption->get_order_id(),
                'points'      => -$redemption->get_points(),
                'value'       => $redemption->get_value(),
                'currency'    => $redemption->get_currency(),
                'status'      => $redemption->display_status( $now ),
                'created_at'  => $redemption->get_created_at(),
            ];
        }

        $result['items'] = $items;

        return $this->respond_paginated( $result );
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_redemption_entry',
            'type'       => 'object',
            'properties' => [
                'id'          => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'reward_id'   => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'reward_name' => [
                    'description' => __( 'Resolved from reward_id. Empty when the reward was deleted.', 'yayloyalty' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'order_id'    => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'points'      => [
                    'description' => __( 'Negative: the points spent.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'value'       => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'currency'    => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'status'      => [
                    'type'     => 'string',
                    'enum'     => array_merge( Redemption::STORED_STATUSES, [ Redemption::STATUS_EXPIRED ] ),
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at'  => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
