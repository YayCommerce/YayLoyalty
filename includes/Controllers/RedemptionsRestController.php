<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Fixtures\CustomerDrawer\CustomerDrawerFixture;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/redemptions — paginated redemption rows
 * (F09 Wave B, artboard 3e).
 *
 * No `redemptions` table exists yet — `docs/data-model.md`'s
 * `yayloyalty_redemptions` is designed but not implemented (1.0) — so the
 * real path always answers an EMPTY envelope, matching `counts.redemptions`
 * on `CustomerRestController::format_customer()`. The WP_DEBUG-gated drawer
 * fixture (`DrawerRedemptions`) is the only thing that answers this for now;
 * when the real table ships, only `get_history()` below changes.
 *
 * A separate controller, the same shape `ActivityRestController` uses for
 * the same reason: keeps both this file and `CustomerRestController` under
 * the 200-line cap.
 */
final class RedemptionsRestController extends AbstractRestController {

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
                        [ 'id' => $this->user_id_param() ],
                        $this->get_collection_params(),
                        CustomerDrawerFixture::scenario_arg()
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_history( \WP_REST_Request $request ) {
        $page     = (int) $request['page'];
        $per_page = (int) $request['per_page'];

        $scenario = CustomerDrawerFixture::requested( $request );

        if ( null !== $scenario ) {
            $result = CustomerDrawerFixture::redemptions(
                $scenario,
                [
                    'page'     => $page,
                    'per_page' => $per_page,
                ]
            );

            return $this->respond_paginated( $result );
        }

        // No table yet — `id` is validated by user_id_param() but nothing looks it up.
        return $this->respond_empty_page( $request );
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
                    'description' => __( 'Display name only. The real table stores reward_id; resolving it is an open question, deliberately not solved here (see DrawerRedemptions.php).', 'yayloyalty' ),
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
                    'description' => __( 'Signed for display: negative when spent, positive when returned by a refund.', 'yayloyalty' ),
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
                    'enum'     => [ 'applied', 'returned', 'issued' ],
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
