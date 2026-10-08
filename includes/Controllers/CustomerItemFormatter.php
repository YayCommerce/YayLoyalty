<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Models\CustomerMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the `GET /customers/{id}` response. Pure array assembly, with no $wpdb,
 * get_userdata() or __(): the caller resolves identity and the deletion row first.
 *
 * `$deletion` must be null unless the caller has confirmed both that a deletion row exists
 * and that get_userdata() returns false now. A reinstated user id must stop rendering as
 * deleted, and that two-condition gate lives in the controller only.
 */
final class CustomerItemFormatter {

    /**
     * @param array<string, mixed>                            $base          Customer::to_array().
     * @param array{display_name: string, email: string}|null $live_identity Null when the WP user is gone.
     * @return array<string, mixed>
     */
    public static function format(
        array $base,
        ?array $live_identity,
        int $ledger_count,
        int $activity_count,
        int $redemption_count,
        ?CustomerMeta $deletion,
        float $points_per_currency_unit
    ): array {
        return $base + [
            'name'             => self::identity( $live_identity, $deletion, 'display_name' ),
            'email'            => self::identity( $live_identity, $deletion, 'email' ),
            'deleted_at'       => null !== $deletion ? $deletion->get_created_at() : null,
            'counts'           => [
                'ledger'      => $ledger_count,
                'activity'    => $activity_count,
                'redemptions' => $redemption_count,
            ],
            'loose_ends'       => self::loose_ends( $deletion ),
            // Needs the `lots` table: designed, not built yet.
            'expiry'           => null,
            // Needs a backwards walk of the ledger, a separate feature not built yet.
            'negative_balance' => null,
        ];
    }

    /** @param array{display_name: string, email: string}|null $live_identity */
    private static function identity( ?array $live_identity, ?CustomerMeta $deletion, string $key ): string {
        if ( null !== $live_identity && isset( $live_identity[ $key ] ) ) {
            return (string) $live_identity[ $key ];
        }

        if ( null === $deletion ) {
            return '';
        }

        $snapshot = $deletion->snapshot_identity();

        return 'email' === $key ? $snapshot['email'] : $snapshot['name'];
    }

    /**
     * `{display_name, email}` from a live `WP_User`, or `null` when the WP
     * user is gone: the shape format() expects.
     *
     * @param \WP_User|false $user
     * @return array{display_name: string, email: string}|null
     */
    public static function live_identity( $user ): ?array {
        if ( ! $user ) {
            return null;
        }

        return [
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
        ];
    }

    /**
     * `kept_ledger_entries` is always real. The other three facts have no
     * source feature yet, so they are null and the SPA shows "Not tracked yet".
     *
     * @return array<string, mixed>|null
     */
    private static function loose_ends( ?CustomerMeta $deletion ): ?array {
        if ( null === $deletion ) {
            return null;
        }

        return [
            'void_coupons'        => null,
            'counted_in_members'  => null,
            'kept_ledger_entries' => (int) ( $deletion->get_data()['kept_ledger_entries'] ?? 0 ),
        ];
    }
}
