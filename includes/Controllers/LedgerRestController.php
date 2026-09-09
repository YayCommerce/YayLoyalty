<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/ledger — paginated history.
 *
 * Read-only by design: the ledger is append-only, and the only way to add a row
 * is through LedgerService. There is no POST here on purpose.
 */
final class LedgerRestController extends AbstractRestController {

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( LedgerRepository $ledger ) {
        $this->ledger = $ledger;
    }

    public function get_rest_base(): string {
        return 'ledger';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)/ledger',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_history' ], $request ),
                    'permission_callback' => [ $this, 'permission_callback' ],
                    'args'                => array_merge(
                        [ 'id' => $this->user_id_param() ],
                        $this->get_collection_params()
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_history( \WP_REST_Request $request ) {
        $result = $this->ledger->paginate_for_user(
            (int) $request['id'],
            [
                'page'     => (int) $request['page'],
                'per_page' => (int) $request['per_page'],
                'orderby'  => $request['orderby'] ? (string) $request['orderby'] : 'id',
                'order'    => $request['order'] ? (string) $request['order'] : 'desc',
            ]
        );

        return $this->respond_paginated( $result );
    }

    public function get_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_ledger_entry',
            'type'       => 'object',
            // Every property is readonly: this table is append-only and the
            // only writer is LedgerService.
            'properties' => [
                'id'             => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'user_id'        => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'points'         => [
                    'description' => __( 'Signed delta. Negative for redemptions, clawbacks and expiry.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'balance_after'  => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'type'           => [
                    'type'     => 'string',
                    'enum'     => LedgerType::all(),
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'source'         => [
                    'type'     => 'string',
                    'enum'     => LedgerSource::all(),
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'reference_type' => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'reference_id'   => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'campaign_id'    => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'redemption_id'  => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'order_id'       => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'note'           => [
                    'description' => __( 'Admin reason. Present on manual adjustments only; other rows compose their description client-side.', 'yayloyalty' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'actor_id'       => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at'     => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
