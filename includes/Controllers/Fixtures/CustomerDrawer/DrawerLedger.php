<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Controllers\Schemas\LedgerQueryArgs;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Envelope, chain and filtering for the drawer ledger tab's six scenarios.
 * Per-row type/points/source synthesis lives in `DrawerLedgerGenerator`
 * (split out to keep this file near the 200-line cap).
 *
 * `note`/`order_id`/`campaign_id` are the only fields the client renders text
 * from (`describeLedgerEntry()`, apps/admin) — there is no literal
 * "description" here to hand-author, so every scenario is generated rather
 * than transcribed from the design's example rows.
 *
 * Campaign ids 1/3/4 are `CampaignRestController::dummy_fixture()`'s real
 * ids for "Summit+ members 1.5×"/"Trail Shoes boost"/"Points for purchases"
 * — the three names the design quotes on earn rows. (The plan's own notes
 * named ids 1/7/9, borrowed from `DashboardFixture::pro_scenario()`'s
 * unrelated, separate fictional campaign set — corrected here.)
 */
final class DrawerLedger {

    private const CAMPAIGN_IDS = [ 1, 3, 4 ];

    /**
     * @param array{page:int,per_page:int,type?:string,search?:string,after?:string,before?:string} $args
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, type_counts: array<string, int>}
     */
    public static function envelope( string $scenario, int $user_id, array $args ): array {
        $rows = self::rows( $scenario, $user_id );

        return CustomerDrawerFixture::page( self::apply_filters( $rows, $args ), $args )
            + [ 'type_counts' => self::type_counts( $rows ) ];
    }

    /**
     * Newest-first. `balance_after` is derived walking BACKWARD from the
     * profile's ending balance, so the chain (`prev.balance_after + points
     * === this.balance_after`) is correct by construction — nothing here
     * has to solve for a total at the oldest row.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows( string $scenario, int $user_id ): array {
        // 5a (F09 Wave B) needs an exact, real story — "earned 1,000 on
        // #4102, spent it all on #4150, then #4102 was refunded" — not the
        // generic procedural rows every other scenario gets. Lives in
        // `DrawerNegativeBalance.php`, not here: it's the same narrative
        // `negative_balance.cause_ledger_id` describes, from the ledger
        // endpoint's side, and keeping both views together in one file beat
        // splitting them across two that would need to stay in sync anyway.
        if ( 'negative' === $scenario ) {
            return DrawerNegativeBalance::ledger_rows( $user_id );
        }

        $profile = DrawerProfiles::profile( $scenario );
        $count   = $profile['counts']['ledger'];
        $balance = $profile['balance'];
        $types   = DrawerLedgerGenerator::type_sequence( $scenario, $count );
        $step    = 'large' === $scenario ? 4 * HOUR_IN_SECONDS : DAY_IN_SECONDS;

        $rows = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $type          = $types[ $i ];
            $points        = DrawerLedgerGenerator::points_for( $type, $i );
            $balance_after = $balance;
            $balance      -= $points;

            $order_id = 4000 + $i;
            // Real adjust rows carry a NULL reference (LedgerService::adjust()) — only
            // earn/redeem are ever tied to an order.
            $has_ref = LedgerType::EARN === $type || LedgerType::REDEEM === $type;

            $rows[] = [
                'id'             => $count - $i,
                'user_id'        => $user_id,
                'points'         => $points,
                'balance_after'  => $balance_after,
                'type'           => $type,
                'source'         => DrawerLedgerGenerator::source_for( $type ),
                'reference_type' => $has_ref ? 'order' : null,
                'reference_id'   => $has_ref ? $order_id : null,
                'campaign_id'    => LedgerType::EARN === $type ? self::CAMPAIGN_IDS[ $i % 3 ] : null,
                'redemption_id'  => LedgerType::REDEEM === $type ? $order_id : null,
                'order_id'       => $has_ref ? $order_id : null,
                'note'           => LedgerType::ADJUST === $type ? 'Goodwill adjustment' : null,
                'actor_id'       => LedgerType::ADJUST === $type ? 1 : null,
                'created_at'     => Dates::offset( -$i * $step ),
            ];
        }

        return $rows;
    }

    /** Grouped over the UNFILTERED set — what the filter labels ("Earned 4,190") read from. */
    private static function type_counts( array $rows ): array {
        $counts        = array_fill_keys( LedgerQueryArgs::filter_types(), 0 );
        $counts['all'] = count( $rows );

        foreach ( $rows as $row ) {
            ++$counts[ LedgerQueryArgs::group_of( $row['type'] ) ];
        }

        return $counts;
    }

    private static function apply_filters( array $rows, array $args ): array {
        $type   = $args['type'] ?? 'all';
        $search = CustomerDrawerFixture::search_needle( $args );

        return array_values(
            array_filter(
                $rows,
                static function ( array $row ) use ( $type, $search, $args ): bool {
                    if ( 'all' !== $type && LedgerQueryArgs::group_of( $row['type'] ) !== $type ) {
                        return false;
                    }

                    if ( '' !== $search ) {
                        $haystack = strtolower( ( $row['note'] ?? '' ) . ' ' . ( $row['order_id'] ?? '' ) );

                        if ( false === strpos( $haystack, $search ) ) {
                            return false;
                        }
                    }

                    return CustomerDrawerFixture::in_date_window( $row['created_at'], $args );
                }
            )
        );
    }
}
