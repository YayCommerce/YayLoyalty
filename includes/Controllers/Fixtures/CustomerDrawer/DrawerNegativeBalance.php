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
 * `earned`/`reversed.occurred_at` and `cause_ledger_id` MUST stay in sync
 * with `self::ledger_rows()` below — they describe the same three rows from
 * two different endpoints, and the negative-balance panel points the ledger
 * tab at `cause_ledger_id` to highlight the real row.
 */
final class DrawerNegativeBalance {

    /** The `negative` scenario's ledger row ids — see `self::ledger_rows()`. */
    private const CAUSE_LEDGER_ID = 3;

    /**
     * @return array{shape:string, earned:?array, spent:?array, reversed:?array, cause_ledger_id:?int, deficit:int}|null
     */
    public static function for_scenario( string $scenario, int $balance ): ?array {
        if ( 'negative' !== $scenario ) {
            return null;
        }

        return [
            'shape'           => 'refund_reversal',
            'earned'          => [
                'order_id'    => 4102,
                'points'      => 1000,
                'occurred_at' => Dates::offset( -3 * DAY_IN_SECONDS ),
            ],
            'spent'           => [
                'order_id' => 4150,
                'points'   => 1000,
                'value'    => '10.00',
            ],
            'reversed'        => [
                'order_id'    => 4102,
                'points'      => 1000,
                'occurred_at' => Dates::offset( -DAY_IN_SECONDS ),
            ],
            'cause_ledger_id' => self::CAUSE_LEDGER_ID,
            'deficit'         => abs( $balance ),
        ];
    }

    /**
     * The same story's three ledger rows, for `DrawerLedger::rows()` — earn
     * 1,000 on #4102 → redeem all 1,000 on #4150 → #4102 refunded, reversing
     * the 1,000 it issued. Sums to the `negative` scenario's −1,000 balance
     * by construction: +1000 − 1000 − 1000 = −1000. Newest first, like every
     * other scenario's rows. Row 3's id MUST stay `self::CAUSE_LEDGER_ID`.
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
                'id'             => self::CAUSE_LEDGER_ID,
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
