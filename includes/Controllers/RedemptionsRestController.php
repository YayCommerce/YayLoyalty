<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/redemptions: paginated redemption rows.
 *
 * The `yayloyalty_redemptions` table is not built yet, so the endpoint always
 * answers an empty envelope, matching `counts.redemptions` from
 * CustomerItemFormatter::format(). When the table ships, only get_history() changes.
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
                        [ 'id' => $this->member_id_param() ],
                        $this->get_collection_params()
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_history( \WP_REST_Request $request ) {
        // No table yet: member_id_param() validates `id`, but nothing looks it up.
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
                    'description' => __( 'Display name only. The real table stores reward_id; resolving it is an open question, deliberately not solved here.', 'yayloyalty' ),
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
