<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Referrals-tab rows (F09 Wave B, artboard 3f) — structured, not sentences.
 * No `yayloyalty_referrals` table exists yet (`docs/data-model.md`, 1.1).
 *
 * A referral has TWO conditions and both must be visible: the friend signed
 * up, and their first order completed. `status` carries which stage a row
 * is at (`completed`/`pending`/`lapsed`); `window_closes_at` is only
 * meaningful for `pending` — the copy module states what is missing and
 * when the window closes, the exact thing 3f says admins otherwise end up
 * asking support about.
 *
 * The referred customer's name/email are personal data of a THIRD PARTY
 * (not the customer this drawer belongs to) — fixture-only here, but the
 * real endpoint must apply the same `CAP::VIEW` gate and never expose them
 * to a lower capability.
 */
final class DrawerReferrals {

    /** 500 points to the referrer + a $5 coupon to the friend, 90-day window — same for every scenario. */
    private const CAMPAIGN = [
        'referrer_points'     => 500,
        'friend_coupon_label' => '$5 coupon',
        'window_days'         => 90,
    ];

    /** Each scenario's referral code — `''`/`expiring` match 3f's "daniel-4f2" verbatim. */
    private const REFERRAL_CODES = [
        ''         => 'daniel-4f2',
        'new'      => 'lena-9c1',
        'large'    => 'aisha-7e4',
        'deleted'  => 'erik-2a8',
        'expiring' => 'daniel-4f2',
        'negative' => 'tom-5d6',
    ];

    /** The default identity's 3 rows — 3f's exact figures, verbatim. */
    private static function default_rows(): array {
        return [
            [
                'referred_name'     => 'Priya Raghunathan',
                'referred_email'    => 'priya.raghunathan@example.com',
                'signed_up_at'      => '2026-08-14 00:00:00',
                'first_order_id'    => 4166,
                'first_order_total' => '148.00',
                'referrer_points'   => 500,
                'friend_coupon'     => [
                    'label' => '$5 coupon',
                    'used'  => true,
                ],
                'status'            => 'completed',
                'window_closes_at'  => null,
            ],
            [
                'referred_name'     => 'Ana Sousa',
                'referred_email'    => 'ana.sousa@example.com',
                'signed_up_at'      => '2026-09-06 00:00:00',
                'first_order_id'    => 4201,
                'first_order_total' => '62.00',
                'referrer_points'   => 500,
                'friend_coupon'     => [
                    'label' => '$5 coupon',
                    'used'  => false,
                ],
                'status'            => 'completed',
                'window_closes_at'  => null,
            ],
            [
                'referred_name'     => 'Yuki Tanaka',
                'referred_email'    => 'yuki.tanaka@example.com',
                'signed_up_at'      => '2026-09-02 00:00:00',
                'first_order_id'    => null,
                'first_order_total' => null,
                'referrer_points'   => 0,
                'friend_coupon'     => [
                    'label' => '$5 coupon',
                    'used'  => false,
                ],
                'status'            => 'pending',
                'window_closes_at'  => '2026-12-01 00:00:00',
            ],
        ];
    }

    /** Every other scenario: a deterministic cycle of completed referrals — same technique `DrawerActivity`/`DrawerRedemptions` use. */
    private static function templates(): array {
        return [
            [
                'referred_name'  => 'Referred customer',
                'referred_email' => 'referred%d@example.com',
            ],
        ];
    }

    /**
     * Paged envelope, carrying the referral link + campaign facts alongside
     * the rows — structured, never a sentence (the copy module composes
     * "500 points to Daniel and a $5 coupon..." from these fields).
     *
     * @param array{page:int,per_page:int} $args
     * @return array{items: array, total: int, page: int, per_page: int, referral_link: string, campaign: array}
     */
    public static function envelope( string $scenario, array $args ): array {
        $rows     = self::rows( $scenario );
        $page     = max( 1, $args['page'] );
        $per_page = max( 1, $args['per_page'] );
        $code     = self::REFERRAL_CODES[ $scenario ] ?? self::REFERRAL_CODES[''];

        return [
            'items'         => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
            'total'         => count( $rows ),
            'page'          => $page,
            'per_page'      => $per_page,
            'referral_link' => "nordvik.example/r/{$code}",
            'campaign'      => self::CAMPAIGN,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rows( string $scenario ): array {
        $count = DrawerProfiles::referrals_count_for( $scenario );

        if ( 0 === $count ) {
            return [];
        }

        if ( '' === $scenario || 'expiring' === $scenario ) {
            return self::with_ids( self::default_rows() );
        }

        $template = self::templates()[0];
        $rows     = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $rows[] = [
                'referred_name'     => $template['referred_name'],
                'referred_email'    => sprintf( $template['referred_email'], $i ),
                'signed_up_at'      => Dates::offset( -$i * DAY_IN_SECONDS ),
                'first_order_id'    => 4200 + $i,
                'first_order_total' => '50.00',
                'referrer_points'   => 500,
                'friend_coupon'     => [
                    'label' => '$5 coupon',
                    'used'  => 0 === $i % 2,
                ],
                'status'            => 'completed',
                'window_closes_at'  => null,
            ];
        }

        return self::with_ids( $rows );
    }

    /** @return array<int, array<string, mixed>> */
    private static function with_ids( array $rows ): array {
        $count = count( $rows );

        foreach ( $rows as $i => $row ) {
            $rows[ $i ] = [ 'id' => $count - $i ] + $row;
        }

        return $rows;
    }
}
