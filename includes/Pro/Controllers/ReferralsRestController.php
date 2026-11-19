<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Referrals\ReferralCoupon;
use YayLoyalty\Pro\Referrals\ReferralPayout;
use YayLoyalty\Pro\Repositories\ReferralListRepository;
use YayLoyalty\Pro\Repositories\ReferralRepository;

defined( 'ABSPATH' ) || exit;

/** Pro-only: GET /referrals with search, a status filter and the counts per status; POST approve and reject for a held referral. */
final class ReferralsRestController extends AbstractRestController {

    private ReferralListRepository $referral_list;

    private ReferralRepository $referrals;

    private ReferralPayout $payout;

    public function __construct( ReferralListRepository $referral_list, ReferralRepository $referrals, ReferralPayout $payout ) {
        $this->referral_list = $referral_list;
        $this->referrals     = $referrals;
        $this->payout        = $payout;
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

        $id = [ 'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ] ]; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- one short arg.

        register_rest_route(
            self::REST_NAMESPACE,
            '/referrals/(?P<id>\\d+)/approve',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'approve' ], $request ),
                'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                'args'                => $id,
            ]
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/referrals/(?P<id>\\d+)/reject',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'reject' ], $request ),
                'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                'args'                => $id + [
                    'reason' => [
                        'description' => __( 'Why the referral is rejected.', 'yayloyalty' ),
                        'type'        => 'string',
                        'required'    => true,
                        'enum'        => ReferralRejectReason::admin_choices(),
                    ],
                ],
            ]
        );
    }

    /**
     * Pays a held referral now.
     *
     * @return array{id: int, status: string}|\WP_Error 409 when it is no longer on hold, or the advocate can't be paid now.
     */
    public function approve( \WP_REST_Request $request ) {
        $referral = $this->held( (int) $request['id'] );

        if ( ! $referral instanceof Referral ) {
            return $referral;
        }

        $status = $this->payout->pay( $referral );

        if ( ReferralStatus::REWARDED !== $status ) {
            return ReferralStatus::ON_HOLD === $status
                ? new \WP_Error( 'yayloyalty_referral_not_payable', __( 'The advocate can\'t receive points right now: the program is paused, their account is excluded or gone, or the order is no longer completed.', 'yayloyalty' ), [ 'status' => 409 ] )
                : self::changed();
        }

        return [
            'id'     => (int) $referral->get_id(),
            'status' => $status,
        ];
    }

    /**
     * Rejects a held referral with the admin's reason; nothing is paid.
     *
     * @return array{id: int, status: string}|\WP_Error 409 when it is no longer on hold.
     */
    public function reject( \WP_REST_Request $request ) {
        $referral = $this->held( (int) $request['id'] );

        if ( ! $referral instanceof Referral ) {
            return $referral;
        }

        if ( ! $this->referrals->reject( (int) $referral->get_id(), (string) $request['reason'], ReferralStatus::ON_HOLD ) ) {
            return self::changed();
        }

        return [
            'id'     => (int) $referral->get_id(),
            'status' => ReferralStatus::REJECTED,
        ];
    }

    /**
     * The referral while it is on hold, else 404 or 409.
     *
     * @return Referral|\WP_Error
     */
    private function held( int $id ) {
        $referral = $this->referrals->find_for_update( $id );

        if ( ! $referral instanceof Referral ) {
            return $this->error_not_found( __( 'No such referral.', 'yayloyalty' ) );
        }

        return ReferralStatus::ON_HOLD === $referral->get_status() ? $referral : self::changed();
    }

    private static function changed(): \WP_Error {
        return new \WP_Error( 'yayloyalty_referral_changed', __( 'This referral was already paid or rejected.', 'yayloyalty' ), [ 'status' => 409 ] );
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

        return $this->respond_paginated( $result ) + [ 'counts' => (object) $this->referral_list->count_by_status() ]; // An object even when empty, which PHP would otherwise encode as [].
    }

    /**
     * Adds the first order's number, total, currency and admin link, read per row so it works under HPOS and never joins orders.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function with_order( array $item ): array {
        $order = empty( $item['first_order_id'] ) ? null : wc_get_order( (int) $item['first_order_id'] );

        return $item + [
            'first_order'     => $order instanceof \WC_Order ? [
                'id'        => (int) $order->get_id(),
                'number'    => (string) $order->get_order_number(),
                'total'     => wc_format_decimal( $order->get_total() ),
                'currency'  => (string) $order->get_currency(),
                'edit_url'  => $order->get_edit_order_url(),
                'used_code' => in_array( ReferralCoupon::code_for( (int) $item['id'] ), $order->get_coupon_codes(), true ),
            ] : null,
            'advocate_points' => (int) ( $item['terms']['advocate_points'] ?? 0 ),
            'rejects_at'      => ReferralStatus::ON_HOLD === ( $item['status'] ?? '' ) && ! empty( $item['hold_until'] ) ? ReferralPayout::rejects_at( (int) ( $item['advocate']['id'] ?? 0 ), (string) $item['hold_until'] ) : null,
        ];
    }
}
