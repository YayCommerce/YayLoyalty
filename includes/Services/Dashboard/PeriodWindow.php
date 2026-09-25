<?php
namespace YayLoyalty\Services\Dashboard;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's reporting window: N site-timezone days ending today, plus
 * the N before for deltas. Pure: the caller injects "now" in the site zone.
 * Bounds are UTC MySQL strings, `from` inclusive, `to` exclusive. One UTC
 * offset (at `from`) buckets the whole window, so a DST transition inside
 * it shifts daily boundaries by an hour; accepted.
 */
final class PeriodWindow {

    /** @var int[] */
    public const RANGES = [ 7, 30, 90 ];

    private int $range;

    private \DateTimeImmutable $from;

    private \DateTimeImmutable $to;

    private \DateTimeImmutable $prev_from;

    private function __construct( int $range, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $prev_from ) {
        $this->range     = $range;
        $this->from      = $from;
        $this->to        = $to;
        $this->prev_from = $prev_from;
    }

    /**
     * @param \DateTimeImmutable $now_site "Now" in the site timezone.
     * @throws \InvalidArgumentException For ranges other than 7, 30 or 90.
     */
    public static function for_range( int $range, \DateTimeImmutable $now_site ): self {
        if ( ! in_array( $range, self::RANGES, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'YayLoyalty: unsupported dashboard range %d. Expected one of: %s.', $range, implode( ', ', self::RANGES ) ) );
        }

        $today = $now_site->setTime( 0, 0, 0 );
        $from  = $today->modify( sprintf( '-%d days', $range - 1 ) );

        return new self( $range, $from, $today->modify( '+1 day' ), $from->modify( sprintf( '-%d days', $range ) ) );
    }

    public function range(): int {
        return $this->range;
    }

    public function from_utc(): string {
        return Dates::to_utc( $this->from );
    }

    public function to_utc(): string {
        return Dates::to_utc( $this->to );
    }

    public function prev_from_utc(): string {
        return Dates::to_utc( $this->prev_from );
    }

    public function prev_to_utc(): string {
        return Dates::to_utc( $this->from );
    }

    /** Start of the site's current calendar day, UTC — "today" for activity counts. */
    public function today_from_utc(): string {
        return Dates::to_utc( $this->to->modify( '-1 day' ) );
    }

    /** Unix timestamps for WooCommerce order queries: from, to, prev_from (prev_to is from_ts()). */
    public function from_ts(): int {
        return $this->from->getTimestamp();
    }

    public function to_ts(): int {
        return $this->to->getTimestamp();
    }

    public function prev_from_ts(): int {
        return $this->prev_from->getTimestamp();
    }

    /**
     * Site-timezone dates (Y-m-d) covered by the window, ascending; exactly range() entries.
     *
     * @return string[]
     */
    public function days(): array {
        return self::days_between( $this->from->format( 'Y-m-d' ), $this->to->modify( '-1 day' )->format( 'Y-m-d' ) );
    }

    /**
     * Every Y-m-d from `$first_day` to `$last_day`, both inclusive, ascending —
     * the one day-list builder for zero-filled series (this window and the
     * campaign performance chart).
     *
     * @return string[]
     */
    public static function days_between( string $first_day, string $last_day ): array {
        $days   = [];
        $cursor = new \DateTimeImmutable( $first_day );
        $end    = new \DateTimeImmutable( $last_day );

        while ( $cursor <= $end ) {
            $days[] = $cursor->format( 'Y-m-d' );
            $cursor = $cursor->modify( '+1 day' );
        }

        return $days;
    }

    /** Seconds to add to a UTC DATETIME so DATE() yields the site calendar day. */
    public function utc_offset_seconds(): int {
        return $this->from->getOffset();
    }
}
