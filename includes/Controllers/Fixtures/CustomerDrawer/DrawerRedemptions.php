<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Redemptions-tab rows (F09 Wave B, artboard 3e) — structured, not sentences.
 * No `yayloyalty_redemptions` table exists yet (`docs/data-model.md`, 1.0);
 * this row shape is the plan's own deliberate simplification of that
 * table's already-designed columns (`id`/`user_id`/`reward_id`/`order_id`/
 * `points`/`value`/`currency`/`status`/`created_at` — dropping
 * `base_value`/`rate_snapshot`/`coupon_code`/`coupon_id`/`restored_points`/
 * `expires_at`, which this tab never displays), plus one addition:
 * `reward_name`. The real table stores `reward_id` only — resolving that to
 * a display name is the same open question the ledger's `campaign_name`
 * has (SPA-side lookup vs. server join), deliberately NOT solved here.
 *
 * `points` is SIGNED here (negative = spent, positive = returned) for
 * display, unlike the real table's `points BIGINT UNSIGNED` (always
 * positive; direction comes from `status` alone) — a wire-only
 * simplification, matching how the ledger's own `points` is signed.
 */
final class DrawerRedemptions {

    /** The default identity's 6 rows — 3e's exact figures, verbatim. */
    private static function default_rows(): array {
        return [
            [
                'reward_id'   => 1,
                'reward_name' => 'Points for discount',
                'order_id'    => 4171,
                'points'      => -1200,
                'value'       => '12.00',
                'status'      => 'applied',
                'created_at'  => '2026-08-21 00:00:00',
            ],
            [
                'reward_id'   => 2,
                'reward_name' => 'Free shipping',
                'order_id'    => 4098,
                'points'      => -800,
                'value'       => '6.90',
                'status'      => 'applied',
                'created_at'  => '2026-08-04 00:00:00',
            ],
            [
                'reward_id'   => 3,
                'reward_name' => '$5 off',
                'order_id'    => 3977,
                'points'      => -500,
                'value'       => '5.00',
                'status'      => 'applied',
                'created_at'  => '2026-05-19 00:00:00',
            ],
            [
                'reward_id'   => 4,
                'reward_name' => '15% off',
                'order_id'    => 3901,
                'points'      => -1500,
                'value'       => '27.45',
                'status'      => 'applied',
                'created_at'  => '2026-04-02 00:00:00',
            ],
            [
                'reward_id'   => 1,
                'reward_name' => 'Points for discount',
                'order_id'    => 3862,
                'points'      => -450,
                'value'       => '4.50',
                'status'      => 'applied',
                'created_at'  => '2026-03-04 00:00:00',
            ],
            // The interesting row: a refund returns the points (positive,
            // distinct status), and is excluded from any total this tab
            // might compute (the totals themselves are cut this phase).
            [
                'reward_id'   => 2,
                'reward_name' => 'Free shipping',
                'order_id'    => 3844,
                'points'      => 800,
                'value'       => '6.90',
                'status'      => 'returned',
                'created_at'  => '2026-02-18 00:00:00',
            ],
        ];
    }

    /** Every other scenario: a deterministic cycle, same technique `DrawerActivity` uses. */
    private static function templates(): array {
        return [
            [
                'reward_id'   => 1,
                'reward_name' => 'Points for discount',
                'value'       => '10.00',
                'points'      => -1000,
            ],
            [
                'reward_id'   => 2,
                'reward_name' => 'Free shipping',
                'value'       => '6.90',
                'points'      => -800,
            ],
            [
                'reward_id'   => 3,
                'reward_name' => '$5 off',
                'value'       => '5.00',
                'points'      => -500,
            ],
        ];
    }

    /**
     * Paged envelope — what `RedemptionsRestController` answers from under
     * `?scenario=`. Same shape as `DrawerActivity::envelope()`.
     *
     * @param array{page:int,per_page:int} $args
     * @return array{items: array, total: int, page: int, per_page: int}
     */
    public static function envelope( string $scenario, array $args ): array {
        $rows     = self::rows( $scenario );
        $page     = max( 1, $args['page'] );
        $per_page = max( 1, $args['per_page'] );

        return [
            'items'    => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
            'total'    => count( $rows ),
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rows( string $scenario ): array {
        $count = DrawerProfiles::redemptions_count_for( $scenario );

        if ( 0 === $count ) {
            return [];
        }

        // `default_rows()` is already newest-first, as written.
        if ( '' === $scenario || 'expiring' === $scenario ) {
            return self::with_ids( self::default_rows() );
        }

        $templates = self::templates();
        $rows      = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $template = $templates[ $i % count( $templates ) ];

            $rows[] = $template + [
                'order_id'   => 3800 + $i,
                'created_at' => Dates::offset( -$i * DAY_IN_SECONDS ),
                'status'     => 'applied',
            ];
        }

        return self::with_ids( $rows );
    }

    /** @return array<int, array<string, mixed>> */
    private static function with_ids( array $rows ): array {
        $count = count( $rows );

        foreach ( $rows as $i => $row ) {
            // Every scenario checks out in the same currency — no
            // multi-currency scenario is in scope this wave.
            $rows[ $i ] = [ 'id' => $count - $i ] + $row + [ 'currency' => 'USD' ];
        }

        return $rows;
    }
}
