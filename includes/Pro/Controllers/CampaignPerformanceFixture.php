<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Services\Dashboard\DashboardMath;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Invented-but-deterministic data behind CampaignPerformanceRestController.
 * See that class's SWAP POINT docblock for the real-data replacement.
 *
 * Everything here derives from ONE generated row list per campaign
 * (all_entries()) — summary() and entries() both read it, so the tiles, the
 * chart and the table can never disagree with each other. Nothing here uses
 * rand(): every figure is a function of the campaign id and a row index, so
 * the same campaign always renders the same numbers.
 *
 * Deleted at swap time, along with the two SWAP POINT branches in
 * CampaignPerformanceRestController.
 */
final class CampaignPerformanceFixture {

    /** First-name pool for generated customers — the wireframe's own five, plus more to spread across bigger campaigns. */
    private const CUSTOMER_NAMES = [
        'Ana Sousa',
        'Yuki Tanaka',
        'Tom Weaver',
        'Lars Fredriksen',
        'Priya Raghunathan',
        'Noah Becker',
        'Chidinma Okafor',
        'Mateo Rossi',
        'Freya Solberg',
        'Ivan Petrov',
        'Aiko Fujimoto',
        'Diego Fernandez',
        'Grace Mwangi',
        'Liam O\'Connor',
        'Sara Haddad',
        'Wei Zhang',
        'Elena Popescu',
        'Omar Farouk',
        'Nina Kowalski',
        'Hugo Silva',
        'Maya Cohen',
        'Felix Bauer',
        'Amara Nwosu',
        'Jonas Lindgren',
    ];

    /**
     * Row counts for specific dummy ids, so the demo reliably lands on both
     * sides of the SPA's >30-total search threshold and of a single page of
     * 10. Any id not listed falls back to a smaller deterministic count
     * (see all_entries()).
     */
    private const DEMO_TOTALS = [
        1  => 540,  // purchase, always-on
        4  => 1210, // purchase, built-in default
        8  => 214,  // purchase, archived — the wireframe's own numbers
        9  => 3020, // purchase, always-on
        11 => 24,   // purchase, small — no search bar
        5  => 380,  // signup, built-in
        14 => 22,   // signup, small — no search bar
        15 => 9,    // signup, tiny — no search bar, single page
        6  => 96,   // review, built-in
        16 => 12,   // review, small — no search bar
    ];

    /** Relative weekday weight, Monday first (ISO-8601 `N`) — the wireframe's Saturday peak. */
    private const WEEKDAY_FACTOR = [ 1.0, 0.9, 0.9, 0.95, 1.1, 1.4, 1.1 ];

    /** Per-request cache, keyed by campaign id — all_entries() is called by both summary() and entries(). */
    private static array $entries_cache = [];

    /**
     * @param array<string, mixed> $row Campaign::to_array()'s shape.
     * @return array<string, mixed>
     */
    public static function summary( array $row, float $rate ): array {
        $window   = self::window( $row );
        $entries  = self::all_entries( $row );
        $has_data = [] !== $entries;

        $days  = self::days_between( $window['from'], $window['to'] );
        $daily = DashboardMath::fill_series( self::group_by_day( $entries ), $days, [ 'points' ] );

        $points_issued = array_sum( array_column( $entries, 'points' ) );
        $entries_total = count( $entries );

        $totals = [
            'points_issued'           => $points_issued,
            'entries_total'           => $entries_total,
            'orders_affected'         => null,
            'orders_in_window'        => null,
            'customers_earned'        => null,
            'avg_points_per_order'    => null,
            'avg_order_subtotal'      => null,
            'liability_amount'        => null,
            'liability_above_default' => null,
        ];

        $skipped  = null;
        $estimate = null;

        if ( CampaignTrigger::PURCHASE === $row['trigger'] ) {
            $totals['orders_affected']  = $entries_total;
            $totals['liability_amount'] = PointsMath::points_to_currency( $points_issued, $rate );

            if ( $has_data ) {
                $skipped_total                     = (int) round( $entries_total * 0.4 );
                $skipped                           = self::skipped_breakdown( $row, $skipped_total );
                $totals['orders_in_window']        = $entries_total + $skipped_total;
                $totals['avg_points_per_order']    = intdiv( $points_issued, $entries_total );
                $totals['avg_order_subtotal']      = round( array_sum( array_column( $entries, 'subtotal' ) ) / $entries_total, 2 );
                $totals['liability_above_default'] = self::liability_above_default( $row, $points_issued, $rate );
            } else {
                $estimate = self::estimate( $row, $rate );
            }
        } else {
            // signup / review: no order concepts — just how many distinct customers earned.
            $totals['customers_earned'] = count( array_unique( array_column( $entries, 'customer_id' ) ) );
        }

        return [
            'campaign_id' => $row['id'],
            'window'      => $window,
            'totals'      => $totals,
            'daily'       => $daily,
            'skipped'     => $skipped,
            'estimate'    => $estimate,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{items: mixed[], total: int, page: int, per_page: int}
     */
    public static function entries( array $row, int $page, int $per_page, string $search ): array {
        // Clamped once, up front — same as CampaignRepository::paginate_filtered().
        // The route's arg schema declares `page`'s minimum as 1, but WP does
        // not actually enforce `minimum`/`maximum` on a REST arg unless a
        // `validate_callback` is registered for it (none is here), so a raw
        // 0 or negative `page` can still reach this method.
        $page = max( 1, $page );
        $all  = self::all_entries( $row );

        $search = trim( $search );

        if ( '' !== $search ) {
            // Two needles, not one: ltrim( $search, '#' ) on a search that is
            // ALL '#' characters (e.g. the single character "#") reduces to
            // '' — and stripos( $haystack, '' ) matches every haystack, which
            // would silently return the whole list instead of nothing. The
            // name needle is never ltrimmed, so a bare "#" correctly finds no
            // customer name; the reference needle is skipped entirely once
            // it is empty, rather than being allowed to match everything.
            $name_needle = strtolower( $search );
            $ref_needle  = strtolower( ltrim( $search, '#' ) );

            $all = array_values(
                array_filter(
                    $all,
                    static function ( array $entry ) use ( $name_needle, $ref_needle ) {
                        if ( false !== stripos( $entry['customer_name'], $name_needle ) ) {
                            return true;
                        }

                        if ( '' === $ref_needle ) {
                            return false;
                        }

                        $label = $entry['reference']['label'] ?? '';

                        return false !== stripos( ltrim( (string) $label, '#' ), $ref_needle );
                    }
                )
            );
        }

        return [
            'items'    => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
            'total'    => count( $all ),
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Whether this campaign has ever paid out. Mirrors the SPA's
     * `hasPointsFigure()` (pro/campaigns/campaign-points-dummy.ts): a
     * campaign that has not started, or was never turned on, has nothing to
     * show yet — only active/ended/archived carry a figure.
     */
    private static function has_data( array $row ): bool {
        $display = Status::resolve_display( $row['status'], $row['starts_at'], $row['ends_at'], Dates::now() );

        return in_array( $display, [ Status::ACTIVE, Status::ENDED, Status::ARCHIVED ], true );
    }

    /**
     * The full generated row list for one campaign, newest first. Memoised
     * per request — summary() and entries() both call this, and it must
     * stay identical between the two calls within one response cycle.
     *
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private static function all_entries( array $row ): array {
        $id = $row['id'];

        if ( array_key_exists( $id, self::$entries_cache ) ) {
            return self::$entries_cache[ $id ];
        }

        if ( ! self::has_data( $row ) ) {
            self::$entries_cache[ $id ] = [];

            return self::$entries_cache[ $id ];
        }

        $window = self::window( $row );
        $days   = self::days_between( $window['from'], $window['to'] );
        $tz     = wp_timezone();
        $count  = self::DEMO_TOTALS[ $id ] ?? ( 40 + ( $id * 53 ) % 400 );
        $names  = count( self::CUSTOMER_NAMES );

        $entries = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $created_at = self::entry_timestamp( self::assign_day( $days, $i ), $i, $tz );

            $entry = [
                'id'            => 90000 + $id * 1000 + $i,
                'customer_id'   => 100 + ( $i * 17 ) % 400,
                'customer_name' => self::CUSTOMER_NAMES[ $i % $names ],
                'points'        => 0,
                'created_at'    => $created_at,
                'reference'     => null,
                'subtotal'      => null,
            ];

            switch ( $row['trigger'] ) {
                case CampaignTrigger::PURCHASE:
                    $subtotal = round( 20 + ( $i * 37 ) % 230 + ( 0 === $i % 2 ? 0.5 : 0.0 ), 2 );
                    $order_id = 4000 + $count - $i;

                    $entry['subtotal']  = $subtotal;
                    $entry['points']    = self::purchase_points( $row['config'], $subtotal );
                    $entry['reference'] = [
                        'id'    => $order_id,
                        'label' => '#' . $order_id,
                        'url'   => self::order_edit_url( $order_id ),
                    ];
                    break;

                case CampaignTrigger::REVIEW:
                    $review_id = 500 + $count - $i;

                    $entry['points']      = (int) ( $row['config']['points'] ?? 0 );
                    $entry['customer_id'] = 100 + ( $i * 17 ) % max( 1, intdiv( $count * 4, 5 ) );
                    $entry['reference']   = [
                        'id'    => $review_id,
                        'label' => '#' . $review_id,
                        'url'   => admin_url( 'comment.php?action=editcomment&c=' . $review_id ),
                    ];
                    break;

                default: // CampaignTrigger::SIGNUP — one customer per row, no reference.
                    $entry['points'] = (int) ( $row['config']['points'] ?? 0 );
                    break;
            }

            $entries[] = $entry;
        }

        usort(
            $entries,
            static function ( array $a, array $b ) {
                $cmp = strcmp( $b['created_at'], $a['created_at'] );

                return 0 !== $cmp ? $cmp : ( $b['id'] <=> $a['id'] );
            }
        );

        self::$entries_cache[ $id ] = $entries;

        return self::$entries_cache[ $id ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array{date: string, points: int}>
     */
    private static function group_by_day( array $entries ): array {
        $by_date = [];

        foreach ( $entries as $entry ) {
            $date             = Dates::to_site_tz( $entry['created_at'], 'Y-m-d' );
            $by_date[ $date ] = ( $by_date[ $date ] ?? 0 ) + $entry['points'];
        }

        $rows = [];

        foreach ( $by_date as $date => $points ) {
            $rows[] = [
                'date'   => $date,
                'points' => $points,
            ];
        }

        return $rows;
    }

    /**
     * `points = subtotal × the running rate`. Mirrors the SPA's
     * `resolvePurchaseMode()`: multiplier mode scales the DEFAULT purchase
     * campaign's rate, never this campaign's own points_per_unit — the same
     * inheritance rule the editor documents ("multiplier mode inherits the
     * default campaign's ... base amount").
     *
     * @param array<string, mixed> $config
     */
    private static function purchase_points( array $config, float $subtotal ): int {
        $default_rate = (float) ( Campaign::default_config( CampaignTrigger::PURCHASE )['points_per_unit'] ?? 1 );

        if ( 'multiplier' === ( $config['mode'] ?? 'rate' ) ) {
            $multiplier = (float) ( $config['multiplier'] ?? 1 );

            return (int) floor( $subtotal * $default_rate * $multiplier );
        }

        $points_per_unit = (float) ( $config['points_per_unit'] ?? $default_rate );

        return (int) floor( $subtotal * $points_per_unit );
    }

    /**
     * The part of `$points_issued` genuinely above what the default rate
     * would have awarded — only meaningful for a non-built-in multiplier
     * campaign (a rate-mode or built-in campaign IS the default, so there is
     * no "above" to report).
     *
     * @param array<string, mixed> $row
     */
    private static function liability_above_default( array $row, int $points_issued, float $rate ): ?float {
        if ( 'multiplier' !== ( $row['config']['mode'] ?? 'rate' ) || $row['is_builtin'] ) {
            return null;
        }

        $multiplier = (float) ( $row['config']['multiplier'] ?? 1 );

        if ( $multiplier <= 0 ) {
            return null;
        }

        $default_equivalent = (int) round( $points_issued / $multiplier );

        return PointsMath::points_to_currency( $points_issued - $default_equivalent, $rate );
    }

    /**
     * "Where the other orders went" — split into higher_priority / guest /
     * excluded, roughly 70/20/10 of $total. Row id 1 has nothing invented
     * above it in this generator, so its own skipped orders split
     * guest/excluded only; every other id gets a higher_priority share.
     * `campaign_id`/`campaign_name` stay null for it — this generator has no
     * real second campaign to name, and guest/excluded are null for the
     * same reason, so this stays consistent with them rather than inventing
     * one.
     *
     * @param array<string, mixed> $row
     * @return array<int, array{reason: string, count: int, campaign_id: int|null, campaign_name: string|null}>
     */
    private static function skipped_breakdown( array $row, int $total ): array {
        if ( 0 === $total ) {
            return [];
        }

        if ( 1 === $row['id'] ) {
            $guest = (int) round( $total * 0.7 );

            return [
                [
                    'reason'        => 'guest',
                    'count'         => $guest,
                    'campaign_id'   => null,
                    'campaign_name' => null,
                ],
                [
                    'reason'        => 'excluded',
                    'count'         => $total - $guest,
                    'campaign_id'   => null,
                    'campaign_name' => null,
                ],
            ];
        }

        $higher_count = (int) round( $total * 0.7 );
        $guest_count  = (int) round( $total * 0.2 );

        return [
            [
                'reason'        => 'higher_priority',
                'count'         => $higher_count,
                'campaign_id'   => null,
                'campaign_name' => null,
            ],
            [
                'reason'        => 'guest',
                'count'         => $guest_count,
                'campaign_id'   => null,
                'campaign_name' => null,
            ],
            [
                'reason'        => 'excluded',
                'count'         => $total - $higher_count - $guest_count,
                'campaign_id'   => null,
                'campaign_name' => null,
            ],
        ];
    }

    /**
     * "What it would have done last year" — the same window, shifted back
     * one year, at this campaign's own rate. Only ever called for a purchase
     * campaign with no real data yet.
     *
     * @param array<string, mixed> $row
     * @return array{from: string, to: string, orders: int, points: int, liability_amount: float|null}
     */
    private static function estimate( array $row, float $rate ): array {
        $window = self::window( $row );
        $from   = ( new \DateTimeImmutable( $window['from'] ) )->modify( '-1 year' );
        $to     = ( new \DateTimeImmutable( $window['to'] ) )->modify( '-1 year' );

        $orders = 260 + $row['id'] % 20;
        $avg    = 150 + ( $row['id'] * 31 ) % 90;
        $points = $orders * $avg;

        return [
            'from'             => $from->format( 'Y-m-d' ),
            'to'               => $to->format( 'Y-m-d' ),
            'orders'           => $orders,
            'points'           => $points,
            'liability_amount' => PointsMath::points_to_currency( $points, $rate ),
        ];
    }

    /**
     * The reporting window for this campaign. Site-timezone dates; `to` is
     * inclusive.
     *
     * @param array<string, mixed> $row
     * @return array{from: string, to: string}
     */
    private static function window( array $row ): array {
        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0, 0 );

        $starts = self::to_site_midnight( $row['starts_at'], $tz );
        $ends   = self::to_site_midnight( $row['ends_at'], $tz );

        if ( null !== $starts && null !== $ends ) {
            $to   = $ends->modify( '-1 day' );
            $from = $starts;

            // A campaign that starts and ends on the SAME calendar day (a
            // half-day flash sale: starts_at 00:00, ends_at 12:00) is valid —
            // starts_at < ends_at — but to_site_midnight() truncates both to
            // that one date, so `$to` (ends minus a day) lands BEFORE `$from`.
            // Floor to a same-day, one-day-wide window rather than letting
            // days_between() see from > to and silently produce zero days.
            if ( $to < $from ) {
                $to = $from;
            } elseif ( (int) $from->diff( $to )->days > 29 ) {
                $from = $to->modify( '-29 days' );
            }
        } elseif ( null !== $starts ) {
            $from = $starts;
            $to   = $starts->modify( '+6 days' );
        } elseif ( null !== $ends ) {
            $last = $ends->modify( '-1 day' );
            $to   = $last < $now ? $last : $now;
            $from = $to->modify( '-29 days' );
        } else {
            $to   = $now;
            $from = $to->modify( '-29 days' );
        }

        return [
            'from' => $from->format( 'Y-m-d' ),
            'to'   => $to->format( 'Y-m-d' ),
        ];
    }

    private static function to_site_midnight( ?string $utc, \DateTimeZone $tz ): ?\DateTimeImmutable {
        if ( null === $utc || '' === $utc ) {
            return null;
        }

        $date = date_create_immutable( $utc, new \DateTimeZone( 'UTC' ) );

        return null === $date ? null : $date->setTimezone( $tz )->setTime( 0, 0, 0 );
    }

    /**
     * @return string[] Y-m-d, ascending, both ends inclusive.
     */
    private static function days_between( string $from, string $to ): array {
        $days   = [];
        $cursor = new \DateTimeImmutable( $from );
        $end    = new \DateTimeImmutable( $to );

        while ( $cursor <= $end ) {
            $days[] = $cursor->format( 'Y-m-d' );
            $cursor = $cursor->modify( '+1 day' );
        }

        return $days;
    }

    /**
     * Deterministically spreads row $i across $days, weighted by weekday
     * (WEEKDAY_FACTOR) — the wireframe's Saturday peak. No rand(): a
     * golden-ratio step keeps consecutive indices from landing on the same
     * day while staying fully reproducible.
     *
     * @param string[] $days
     */
    private static function assign_day( array $days, int $i ): string {
        $weights = [];

        foreach ( $days as $day ) {
            $iso       = (int) ( new \DateTimeImmutable( $day ) )->format( 'N' );
            $weights[] = self::WEEKDAY_FACTOR[ $iso - 1 ];
        }

        $total  = array_sum( $weights );
        $target = fmod( $i * 0.6180339887 * $total, $total );
        $cursor = 0.0;

        foreach ( $days as $index => $day ) {
            $cursor += $weights[ $index ];

            if ( $target < $cursor ) {
                return $day;
            }
        }

        return $days[ array_key_last( $days ) ];
    }

    /** UTC `Y-m-d H:i:s` for row $i on site-tz calendar date $site_day. */
    private static function entry_timestamp( string $site_day, int $i, \DateTimeZone $tz ): string {
        $seconds = ( $i * 7919 ) % 86400;
        $local   = ( new \DateTimeImmutable( $site_day . ' 00:00:00', $tz ) )->modify( "+{$seconds} seconds" );

        return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }

    /** HPOS-safe order edit URL — same detection DashboardNotices::orders_admin_url() uses. */
    private static function order_edit_url( int $order_id ): string {
        $hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        return $hpos
            ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
            : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
    }
}
