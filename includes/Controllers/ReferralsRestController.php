<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Fixtures\CustomerDrawer\CustomerDrawerFixture;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/referrals — paginated referral rows
 * (F09 Wave B, artboard 3f).
 *
 * No `referrals` table exists yet — `docs/data-model.md`'s
 * `yayloyalty_referrals` is designed but not implemented (1.1) — so the
 * real path always answers an EMPTY envelope, matching `counts.referrals`
 * on `CustomerRestController::format_customer()`. The WP_DEBUG-gated drawer
 * fixture (`DrawerReferrals`) is the only thing that answers this for now;
 * when the real table ships, only `get_history()` below changes.
 *
 * `referred_name`/`referred_email` are personal data of a THIRD PARTY (the
 * referred customer, not the one this drawer belongs to). The real endpoint
 * must apply the same `CAP::VIEW` gate as everywhere else in this
 * controller and must never expose them to a lower capability.
 *
 * A separate controller, the same shape `RedemptionsRestController` uses for
 * the same reason: keeps both this file and `CustomerRestController` under
 * the 200-line cap.
 */
final class ReferralsRestController extends AbstractRestController {

    public function get_rest_base(): string {
        return 'referrals';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)/referrals',
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
        $user_id  = (int) $request['id'];
        $page     = (int) $request['page'];
        $per_page = (int) $request['per_page'];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $request['scenario'] ) ) {
            $result        = CustomerDrawerFixture::referrals(
                (string) $request['scenario'],
                [
                    'page'     => $page,
                    'per_page' => $per_page,
                ]
            );
            $referral_link = $result['referral_link'];
            $campaign      = $result['campaign'];
            unset( $result['referral_link'], $result['campaign'] );

            return $this->respond_paginated( $result ) + [
                'referral_link' => $referral_link,
                'campaign'      => $campaign,
            ];
        }

        // Real path: no table, no rows, no error. $user_id stays validated
        // via user_id_param() even though nothing here looks it up yet.
        unset( $user_id );

        return $this->respond_paginated(
            [
                'items'    => [],
                'total'    => 0,
                'page'     => $page,
                'per_page' => $per_page,
            ]
        ) + [
            'referral_link' => '',
            'campaign'      => null,
        ];
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_referral_entry',
            'type'       => 'object',
            'properties' => [
                'id'                => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'referred_name'     => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'referred_email'    => [
                    'type'     => 'string',
                    'format'   => 'email',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'signed_up_at'      => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'first_order_id'    => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'first_order_total' => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'referrer_points'   => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'friend_coupon'     => [
                    'type'       => 'object',
                    'context'    => [ 'view' ],
                    'readonly'   => true,
                    'properties' => [
                        'label' => [ 'type' => 'string' ],
                        'used'  => [ 'type' => 'boolean' ],
                    ],
                ],
                'status'            => [
                    'type'     => 'string',
                    'enum'     => [ 'completed', 'pending', 'lapsed' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'window_closes_at'  => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
