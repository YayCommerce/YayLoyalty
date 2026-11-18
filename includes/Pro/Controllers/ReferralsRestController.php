<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Repositories\ReferralListRepository;

defined( 'ABSPATH' ) || exit;

/** Pro-only: GET /referrals, every referral with search, a status filter and the counts per status. */
final class ReferralsRestController extends AbstractRestController {

    private ReferralListRepository $referral_list;

    public function __construct( ReferralListRepository $referral_list ) {
        $this->referral_list = $referral_list;
    }

    public function get_rest_base(): string {
        return 'referrals';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/referrals',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_referrals' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => array_merge(
                        $this->get_collection_params(),
                        [
                            'status'  => [
                                'description' => __( 'Only referrals in this status.', 'yayloyalty' ),
                                'type'        => 'string',
                                'enum'        => ReferralStatus::all(),
                            ],
                            'orderby' => [
                                'description' => __( 'Newest first, or by hold end for the approvals queue.', 'yayloyalty' ),
                                'type'        => 'string',
                                'enum'        => [ 'id', 'hold_until' ],
                                'default'     => 'id',
                            ],
                        ]
                    ),
                ],
            ]
        );
    }

    /** The page, each row with its first order's number and total, and the store-wide `counts` per status. */
    public function get_referrals( \WP_REST_Request $request ): array {
        $result = $this->referral_list->paginate(
            [
                'page'     => (int) $request['page'],
                'per_page' => (int) $request['per_page'],
                'status'   => (string) $request['status'],
                'search'   => (string) $request['search'],
                'orderby'  => (string) $request['orderby'],
            ]
        );

        $result['items'] = array_map( [ self::class, 'with_order' ], $result['items'] );

        return $this->respond_paginated( $result ) + [ 'counts' => $this->referral_list->count_by_status() ];
    }

    /**
     * Adds the first order's number, total and currency, read per row so it works under HPOS and never joins orders.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function with_order( array $item ): array {
        $order = empty( $item['first_order_id'] ) ? null : wc_get_order( (int) $item['first_order_id'] );

        return $item + [
            'first_order'     => $order instanceof \WC_Order ? [
                'id'       => (int) $order->get_id(),
                'number'   => (string) $order->get_order_number(),
                'total'    => wc_format_decimal( $order->get_total() ),
                'currency' => (string) $order->get_currency(),
            ] : null,
            'advocate_points' => (int) ( $item['terms']['advocate_points'] ?? 0 ),
        ];
    }
}
