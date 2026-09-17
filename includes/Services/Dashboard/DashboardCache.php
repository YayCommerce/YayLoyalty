<?php
namespace YayLoyalty\Services\Dashboard;

use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Transient cache for the site-wide dashboard payload, one key per range.
 *
 * Active only above a member threshold: a small store's aggregates are cheap
 * and always live, and only a large one pays for the queries. What goes in
 * the transient is site-wide by construction — stats, series, lists and RAW
 * notices — never anything per user; the controller applies per-user
 * dismissals after the cache.
 *
 * flush() is hooked to `yayloyalty_ledger_recorded` (figures) and
 * `yayloyalty_campaigns_changed` (the paused-campaign notice lives in the
 * payload), so a busy store's dashboard stays near-live at the cost of a
 * cold cache after every write (decision recorded in the plan).
 * is_active() costs one COUNT on the `joined_at` index per request.
 *
 * The key carries a payload version: bump it when the cached shape changes
 * so a deploy never serves an old-shaped array for one TTL.
 */
final class DashboardCache {

    public const TTL       = 5 * MINUTE_IN_SECONDS;
    public const THRESHOLD = 10000;

    private const KEY_PREFIX = 'yayloyalty_dashboard_v1_';

    /** @var DashboardRepository */
    private $repository;

    public function __construct( DashboardRepository $repository ) {
        $this->repository = $repository;
    }

    /** Key variants: '' for the Free payload, 'pro' for the Pro one. */
    public const VARIANTS = [ '', 'pro' ];

    /**
     * @param callable(): array $build   Produces the site-wide payload.
     * @param string            $variant One of VARIANTS — a second payload under the same policy.
     * @return array The payload plus `cached`, `generated_at`, `next_refresh_at`.
     */
    public function remember( int $range, callable $build, string $variant = '' ): array {
        if ( ! $this->is_active() ) {
            return $build() + [
                'cached'          => false,
                'generated_at'    => Dates::now(),
                'next_refresh_at' => null,
            ];
        }

        $key    = self::key( $range, $variant );
        $cached = get_transient( $key );

        if ( is_array( $cached ) ) {
            return $cached + [ 'cached' => true ];
        }

        $ttl     = $this->ttl();
        $payload = $build() + [
            'generated_at'    => Dates::now(),
            'next_refresh_at' => Dates::offset( $ttl ),
        ];

        set_transient( $key, $payload, $ttl );

        return $payload + [ 'cached' => true ];
    }

    /** Drop every range's entry, both variants. Static so a hook can call it without wiring. */
    public static function flush(): void {
        foreach ( PeriodWindow::RANGES as $range ) {
            foreach ( self::VARIANTS as $variant ) {
                delete_transient( self::key( $range, $variant ) );
            }
        }
    }

    private static function key( int $range, string $variant ): string {
        return self::KEY_PREFIX . ( '' === $variant ? '' : $variant . '_' ) . $range;
    }

    private function is_active(): bool {
        /**
         * Members above which the dashboard payload is served from a transient.
         *
         * @param int $threshold
         */
        $threshold = (int) apply_filters( 'yayloyalty_dashboard_cache_threshold', self::THRESHOLD );

        return $this->repository->count_members( Dates::now() ) > $threshold;
    }

    private function ttl(): int {
        /**
         * Seconds a cached dashboard payload is served before recomputing.
         *
         * @param int $ttl
         */
        return max( 1, (int) apply_filters( 'yayloyalty_dashboard_cache_ttl', self::TTL ) );
    }
}
