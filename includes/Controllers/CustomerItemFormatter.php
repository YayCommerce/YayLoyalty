<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Models\CustomerMeta;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /customers/{id}` response assembly — split out of
 * `CustomerRestController` to keep it under the 200-line cap (mirrors
 * `CustomerListFormatter`). Pure array assembly: no `$wpdb`, no
 * `get_userdata()`, no `__()` — the caller resolves identity and the
 * deletion row first, so this stays unit-testable without a WP bootstrap.
 *
 * `$deletion` must be `null` unless the caller has ALREADY confirmed BOTH
 * a deletion row exists AND `get_userdata()` currently returns false — a
 * reinstated WP user id must stop rendering as deleted with no reversal
 * event, and that two-condition gate lives in the controller, never
 * duplicated here.
 */
final class CustomerItemFormatter {

    /**
     * @param array<string, mixed>                     $base Customer::to_array().
     * @param array{display_name: string, email: string}|null $live_identity Null when the WP user is gone.
     * @return array<string, mixed>
     */
    public static function format(
        array $base,
        ?array $live_identity,
        int $ledger_count,
        int $activity_count,
        ?CustomerMeta $deletion,
        float $points_per_currency_unit
    ): array {
        return $base + [
            'name'                     => self::identity( $live_identity, $deletion, 'display_name' ),
            'email'                    => self::identity( $live_identity, $deletion, 'email' ),
            'deleted_at'               => null !== $deletion ? $deletion->get_created_at() : null,
            'counts'                   => [
                'ledger'      => $ledger_count,
                'activity'    => $activity_count,
                // No redemptions/referrals table exists — never fabricate.
                'redemptions' => 0,
                'referrals'   => 0,
            ],
            'loose_ends'               => self::loose_ends( $deletion ),
            // Needs `lots`, which doesn't exist yet (docs/data-model.md) — a separate, later feature.
            'expiry'                   => null,
            // Needs walking the ledger backwards — a separate, later feature.
            'negative_balance'         => null,
            'points_per_currency_unit' => $points_per_currency_unit,
        ];
    }

    /**
     * @param array{display_name: string, email: string}|null $live_identity
     */
    private static function identity( ?array $live_identity, ?CustomerMeta $deletion, string $key ): string {
        if ( null !== $live_identity && isset( $live_identity[ $key ] ) ) {
            return (string) $live_identity[ $key ];
        }

        if ( null !== $deletion ) {
            return (string) ( $deletion->get_data()[ $key ] ?? '' );
        }

        return '';
    }

    /**
     * `kept_ledger_entries` is always real; the other three facts have no
     * source feature (no redemptions/referrals/member-count) — the SPA
     * shows "Not tracked yet" when `null`, never a fabricated value.
     *
     * @return array<string, mixed>|null
     */
    private static function loose_ends( ?CustomerMeta $deletion ): ?array {
        if ( null === $deletion ) {
            return null;
        }

        return [
            'void_coupons'        => null,
            'credited_referrals'  => null,
            'counted_in_members'  => null,
            'kept_ledger_entries' => (int) ( $deletion->get_data()['kept_ledger_entries'] ?? 0 ),
        ];
    }
}
