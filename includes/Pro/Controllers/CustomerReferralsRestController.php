<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Referrals\ReferralAttribution;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralListRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\CurrencyRate;

defined( 'ABSPATH' ) || exit;

/** Pro-only: GET /customers/{id}/referrals, the customer drawer's Referrals tab: one customer as advocate. */
final class CustomerReferralsRestController extends AbstractRestController {

    private const PER_PAGE = 10;

    private ReferralListRepository $referral_list;

    private CustomerRepository $customers;

    public function __construct( ReferralListRepository $referral_list, CustomerRepository $customers ) {
        $this->referral_list = $referral_list;
        $this->customers     = $customers;
    }

    public function get_rest_base(): string {
        return 'customers';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)/referrals',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_referrals' ], $request ),
                'permission_callback' => $this->requires( Capabilities::VIEW ),
                'args'                => [
                    'id'   => $this->member_id_param(),
                    'page' => $this->get_collection_params()['page'],
                ],
            ]
        );
    }

    /**
     * The customer's link (creating a member's code on first view, so every customer has one), their totals, the
     * program's current terms for the summary line, and a page of the people they referred.
     *
     * @return array<string, mixed>
     */
    public function get_referrals( \WP_REST_Request $request ): array {
        $user_id = (int) $request['id'];
        $code    = get_userdata( $user_id ) && $this->customers->find_by_user_id( $user_id ) ? $this->customers->assign_referral_code( $user_id ) : null;
        $counts  = $this->referral_list->counts_for_advocate( $user_id );
        $page    = $this->referral_list->paginate(
            [
                'advocate' => $user_id,
                'page'     => (int) $request['page'],
                'per_page' => self::PER_PAGE,
            ]
        );

        $page['items'] = array_map(
            static function ( array $item ): array {
                $item = ReferralsRestController::with_order( $item );

                // An object even when empty, which PHP would otherwise encode as [].
                $item['terms'] = (object) ( $item['terms'] ?? [] );

                return $item;
            },
            $page['items']
        );
        $settings      = ReferralSettings::get();

        return $this->respond_paginated( $page ) + [
            'link'   => null === $code ? null : add_query_arg( ReferralAttribution::QUERY_ARG, rawurlencode( $code ), home_url( '/' ) ),
            'counts' => [
                'referred'         => $counts['referred'],
                'rewarded'         => $counts['rewarded'],
                'waiting'          => $counts['waiting'],
                'points_earned'    => $counts['points_earned'],
                'revenue_referred' => self::revenue( $counts['qualified_order_ids'] ),
            ],
            'terms'  => [
                'enabled'         => (bool) $settings['enabled'],
                'discount_type'   => (string) $settings['discount_type'],
                'discount_amount' => (string) $settings['discount_amount'],
                'advocate_points' => (int) $settings['advocate_points'],
                'coupon_days'     => (int) $settings['coupon_days'],
            ],
        ];
    }

    /**
     * The qualifying first orders' totals in base currency, so orders charged in other currencies add up.
     *
     * @param int[] $order_ids
     */
    private static function revenue( array $order_ids ): string {
        $total = 0.0;

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( $order instanceof \WC_Order ) {
                $total += CurrencyRate::to_base( (float) $order->get_total(), (string) $order->get_currency(), $order );
            }
        }

        return wc_format_decimal( $total, CurrencyRate::base_decimals() );
    }
}
