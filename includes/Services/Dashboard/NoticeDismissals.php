<?php
namespace YayLoyalty\Services\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user dashboard notice dismissals, in user meta as `{ key: fingerprint }`.
 *
 * A dismissal hides a notice only while its fingerprint is unchanged: the
 * detectors choose fingerprints that move exactly when the situation does
 * (the negative-balance bucket grows, the campaign is paused again, a new
 * day spikes), so a dismissed notice that becomes true again re-surfaces
 * on its own. Applied AFTER the shared cache — this is the only per-user
 * step in the dashboard payload.
 *
 * Thin WordPress wrapper; no unit test.
 */
final class NoticeDismissals {

    public const META_KEY = 'yayloyalty_dashboard_dismissed';

    /** The only keys a client may dismiss — fixture-only keys never persist. */
    public const DISMISSABLE = [ 'negative_balance', 'default_campaign_paused', 'points_jump' ];

    /**
     * @param array<int, array{key: string, fingerprint: string}> $notices
     * @return array<int, array> Same order, dismissed pairs removed.
     */
    public function filter( array $notices, int $user_id ): array {
        $dismissed = $this->read( $user_id );

        return array_values(
            array_filter(
                $notices,
                static fn( array $n ): bool => ! isset( $dismissed[ $n['key'] ] ) || $dismissed[ $n['key'] ] !== $n['fingerprint']
            )
        );
    }

    public function dismiss( int $user_id, string $key, string $fingerprint ): void {
        $dismissed         = $this->read( $user_id );
        $dismissed[ $key ] = $fingerprint;

        update_user_meta( $user_id, self::META_KEY, $dismissed );
    }

    /**
     * @return array<string, string>
     */
    private function read( int $user_id ): array {
        $meta = get_user_meta( $user_id, self::META_KEY, true );

        return is_array( $meta ) ? array_filter( $meta, 'is_string' ) : [];
    }
}
