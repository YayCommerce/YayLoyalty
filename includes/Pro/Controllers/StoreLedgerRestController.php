<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\LedgerRestController;
use YayLoyalty\Controllers\Schemas\LedgerQueryArgs;
use YayLoyalty\Pro\Repositories\StoreLedgerRepository;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/** Pro-only: GET /ledger, every customer's ledger rows. No POST: only LedgerService adds rows. */
final class StoreLedgerRestController extends AbstractRestController {

    /** @var StoreLedgerRepository */
    private $store_ledger;

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( StoreLedgerRepository $store_ledger, LedgerRepository $ledger ) {
        $this->store_ledger = $store_ledger;
        $this->ledger       = $ledger;
    }

    public function get_rest_base(): string {
        return 'ledger';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/ledger',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_ledger' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => array_merge(
                        $this->get_collection_params(),
                        LedgerQueryArgs::all(),
                        [
                            'campaign_id' => [
                                'description'       => __( 'Only rows this campaign issued.', 'yayloyalty' ),
                                'type'              => 'integer',
                                'minimum'           => 1,
                                'sanitize_callback' => 'absint',
                            ],
                        ]
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    /** `type_counts` covers the whole store, unfiltered, as the drawer's do for one customer. */
    public function get_ledger( \WP_REST_Request $request ) {
        $result = $this->store_ledger->paginate(
            [
                'page'        => (int) $request['page'],
                'per_page'    => (int) $request['per_page'],
                'order'       => $request['order'] ? (string) $request['order'] : 'desc',
                'types'       => LedgerQueryArgs::types_of( $request['type'] ),
                'search'      => (string) $request['search'],
                'after'       => (string) $request['after'],
                'before'      => (string) $request['before'],
                'campaign_id' => (int) $request['campaign_id'],
            ]
        );

        $counts = LedgerQueryArgs::group_counts( $this->store_ledger->count_by_type() );

        return $this->respond_paginated( $result ) + [ 'type_counts' => $counts ];
    }

    /** The per-customer ledger entry, plus who it belongs to. */
    protected function define_item_schema(): array {
        $schema = ( new LedgerRestController( $this->ledger ) )->get_item_schema();

        $schema['title']                        = 'yayloyalty_store_ledger_entry';
        $schema['properties']['customer_name']  = [
            'type'     => 'string',
            'context'  => [ 'view' ],
            'readonly' => true,
        ];
        $schema['properties']['customer_email'] = [
            'type'     => 'string',
            'context'  => [ 'view' ],
            'readonly' => true,
        ];

        return $schema;
    }
}
