<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * 5a's structured story — F23, not F09 ("Negative balance, explained in the
 * drawer · Depends on F09 · FREE + PRO"), a separate feature renting space
 * on this canvas. Structured fields only: `shape` lets the SPA tell a real
 * three-step story when one can be assembled and fall back to a generic
 * explanation otherwise — 5a's own claim that a negative balance "always"
 * has the earned→spent→reversed shape is true for a refund reversal, not for
 * a manual adjustment or an expiry clawback pushed below zero. Never
 * optimise the `shape` discriminator away because this fixture only emits
 * one value.
 *
 * The story and `ledger_rows()` describe the same three rows from two
 * endpoints, so the story is read off the rows rather than typed twice.
 */
final class DrawerNegativeBalance {

    /**
     * @return array{shape:string, earned:?array, spent:?array, reversed:?array, cause_ledger_id:?int, deficit:int}|null
     */
    public static function for_scenario( string $scenario ): ?array {
        if ( 'negative' !== $scenario ) {
            return null;
        }

        $rows     = array_column( self::ledger_rows( 0 ), null, 'type' );
        $earned   = $rows[ LedgerType::EARN ];
        $spent    = $rows[ LedgerType::REDEEM ];
        $reversed = $rows[ LedgerType::REFUND_EARN ];

        return [
            'shape'           => 'refund_reversal',
            'earned'          => [
                'order_id'    => $earned['order_id'],
                'points'      => $earned['points'],
                'occurred_at' => $earned['created_at'],
            ],
            'spent'           => [
                'order_id' => $spent['order_id'],
                'points'   => -$spent['points'],
                // The discount the points bought — not a ledger column.
                'value'    => '10.00',
            ],
            'reversed'        => [
                'order_id'    => $reversed['order_id'],
                'points'      => -$reversed['points'],
                'occurred_at' => $reversed['created_at'],
            ],
            'cause_ledger_id' => $reversed['id'],
            'deficit'         => abs( $reversed['balance_after'] ),
        ];
    }

    /**
     * The same story's three ledger rows, for `DrawerLedger::rows()` — earn
     * 1,000 on #4102 → redeem all 1,000 on #4150 → #4102 refunded, reversing
     * the 1,000 it issued. Sums to the `negative` scenario's −1,000 balance
     * by construction: +1000 − 1000 − 1000 = −1000. Newest first, like every
     * other scenario's rows; one row per type, which `for_scenario()` relies on.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ledger_rows( int $user_id ): array {
        $base = [
            'user_id'       => $user_id,
            'campaign_id'   => null,
            'redemption_id' => null,
            'note'          => null,
            'actor_id'      => null,
        ];

        return [
            // Row-specific fields go FIRST: PHP's `+` keeps the LEFT
            // operand's value on a key collision (the opposite of
            // `array_merge()`) — `$base + [...]` would have silently kept
            // `$base`'s `null` defaults instead of these overrides.
            [
                'id'             => 3,
                'points'         => -1000,
                'balance_after'  => -1000,
                'type'           => LedgerType::REFUND_EARN,
                'source'         => LedgerSource::ORDER,
                'reference_type' => 'order',
                'reference_id'   => 4102,
                'order_id'       => 4102,
                'created_at'     => Dates::offset( -DAY_IN_SECONDS ),
            ] + $base,
            [
                'id'             => 2,
                'points'         => -1000,
                'balance_after'  => 0,
                'type'           => LedgerType::REDEEM,
                'source'         => LedgerSource::ORDER,
                'reference_type' => 'order',
                'reference_id'   => 4150,
                'redemption_id'  => 4150,
                'order_id'       => 4150,
                'created_at'     => Dates::offset( -2 * DAY_IN_SECONDS ),
            ] + $base,
            [
                'id'             => 1,
                'points'         => 1000,
                'balance_after'  => 1000,
                'type'           => LedgerType::EARN,
                'source'         => LedgerSource::ORDER,
                'reference_type' => 'order',
                'reference_id'   => 4102,
                // A `DrawerLedger::CAMPAIGN_IDS[0]` id — literal, not a
                // cross-file reference, to keep the two files independent.
                'campaign_id'    => 1,
                'order_id'       => 4102,
                'created_at'     => Dates::offset( -3 * DAY_IN_SECONDS ),
            ] + $base,
        ];
    }
}
