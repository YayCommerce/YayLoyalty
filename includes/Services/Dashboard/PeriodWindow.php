<?php
namespace YayLoyalty\Services\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's reporting window: N site-timezone calendar days ending
 * today, plus the N days immediately before for "vs previous period" deltas.
 *
 * Pure: the caller injects "now" already carrying the site zone
 * (`new DateTimeImmutable( 'now', wp_timezone() )`), so this class never
 * touches WordPress and is deterministic under test.
 *
 * Bounds are exposed as UTC MySQL strings because every stored DATETIME is
 * UTC (see Utils\Dates). `from` is inclusive, `to` exclusive.
 *
 * DST: `utc_offset_seconds()` is the offset at `from`, and the SQL bucketing
 * applies that single offset to the whole window. After a transition inside
 * the window every daily bucket boundary is off by one hour until `to`, and
 * after a fall-back the last local hour of the final day lands on a date
 * outside `days()` and is dropped from the series (so the chart can total
 * one hour short of the tile). Accepted: once per half-year, one hour, and
 * the window bounds themselves are exact.
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
        return self::utc( $this->from );
    }

    public function to_utc(): string {
        return self::utc( $this->to );
    }

    public function prev_from_utc(): string {
        return self::utc( $this->prev_from );
    }

    public function prev_to_utc(): string {
        return self::utc( $this->from );
    }

    /** Start of the site's current calendar day, UTC — "today" for activity counts. */
    public function today_from_utc(): string {
        return self::utc( $this->to->modify( '-1 day' ) );
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
     * Site-timezone calendar dates (`Y-m-d`) covered by the window, ascending.
     * Always exactly `range()` entries — the chart's x-axis.
     *
     * @return string[]
     */
    public function days(): array {
        $days = [];

        for ( $i = 0; $i < $this->range; $i++ ) {
            $days[] = $this->from->modify( sprintf( '+%d days', $i ) )->format( 'Y-m-d' );
        }

        return $days;
    }

    /**
     * Seconds to add to a UTC DATETIME so DATE() yields the site calendar day.
     */
    public function utc_offset_seconds(): int {
        return $this->from->getOffset();
    }

    private static function utc( \DateTimeImmutable $at ): string {
        return $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }
}
