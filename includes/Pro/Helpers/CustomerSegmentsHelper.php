<?php
namespace YayLoyalty\Pro\Helpers;

use YayLoyalty\Enums\CustomerFilters;

defined( 'ABSPATH' ) || exit;

/**
 * Saved Customers-page filter sets — a site-wide list in one wp_options entry.
 *
 * A segment is `{ name, filters }`. `name` IS the identity: there is no
 * generated id, and `rejection_reason()` rejects an exact-name collision
 * rather than append_segment() overwriting it (nothing needs rename — delete
 * is remove_segment() — so an upsert would silently erase whichever segment
 * the name pointed to before).
 *
 * `filters` mirrors `customers-page.filter-rows.ts`'s `RowFilters` minus
 * `search` field-for-field — a segment is a filter set, not a search.
 * Whitelisted in BOTH directions, like SettingsHelper: the stored blob is
 * localised straight into the admin page and drives `Select` values, so an
 * unvalidated value on the read path is as much a hazard as one on the write
 * path (a stray value renders a blank trigger, or worse, is echoed raw).
 *
 * KNOWN LIMITATION: append_segment() and remove_segment() are read-check-write
 * with no locking. Two admins writing (saving a new segment, removing one, any
 * mix) within the same request window can race — the second update_option()
 * wins and silently undoes the first admin's write (a lost save, or a removed
 * segment reappearing). Accepted rather than fixed: this option holds a small,
 * site-wide list edited by administrators/shop managers, not high-frequency
 * concurrent writers, so the odds and the blast radius (retrying fixes it, no
 * corrupt data) do not justify a locking scheme here.
 */
final class CustomerSegmentsHelper {

    public const OPTION_SEGMENTS = 'yayloyalty_customer_segments';

    /** Current stored shape. Bump when a migration changes it. */
    public const SCHEMA_VERSION = 1;

    private const MAX_SEGMENTS    = 50;
    private const MAX_NAME_LENGTH = 60;

    /** Mirrors the SPA's `ALL_SEGMENTS_VALUE` — reserved, never a real segment's name. */
    private const RESERVED_NAME = 'all';

    /**
     * Allowed filter values, keyed by field. First value is that field's
     * default. Keys are the same wire/param names `CustomerRowFilters::DEFAULTS`
     * uses for `GET /customers` (minus `search`, which a segment never
     * carries) — one spelling of this vocabulary server-side; the admin app
     * maps to its camelCase `RowFilters` in `pro/customers/customer-segments.api.ts`.
     *
     * @var array<string, string[]>
     */
    public const FILTER_SCHEMA = [
        'balance_range' => CustomerFilters::BALANCE_RANGES,
        'expiring_soon' => CustomerFilters::EXPIRY_RANGES,
        'tier'          => CustomerFilters::TIER_RANGES,
    ];

    /**
     * Stored segments, sanitised for output. Never throws — a corrupt or
     * legacy option value degrades to an empty list rather than a fatal.
     *
     * @return array<int, array{name: string, filters: array<string, string>}>
     */
    public static function get_segments(): array {
        $stored = get_option( self::OPTION_SEGMENTS, [] );

        if ( ! is_array( $stored ) ) {
            return [];
        }

        // Accept the current wrapper shape and a bare legacy list alike.
        $items = isset( $stored['items'] ) && is_array( $stored['items'] ) ? $stored['items'] : $stored;

        $segments = [];

        foreach ( $items as $item ) {
            $segment = self::sanitize_one( $item );

            if ( null !== $segment ) {
                $segments[] = $segment;
            }
        }

        return array_values( $segments );
    }

    /**
     * Sanitise a raw name the same way storage does — the controller calls
     * this BEFORE checking blank/availability, so what it validates is what
     * actually gets stored (see `sanitize_text_field()`: it can turn a
     * non-blank raw string blank, e.g. one that is only tags/entities).
     */
    public static function sanitize_name( string $name ): string {
        return substr( sanitize_text_field( $name ), 0, self::MAX_NAME_LENGTH );
    }

    /**
     * Why an already-sanitised, non-blank `$name` cannot be saved as a NEW
     * segment — `null` if it can. Separate from append_segment() so the
     * controller (which owns REST error semantics) can pick the right
     * message instead of one generic "already exists" covering three
     * different reasons.
     *
     * Name comparisons are CASE-INSENSITIVE (`mb_strtolower()`, not
     * `strtolower()` — segment names can carry non-ASCII/Vietnamese text):
     * "bbb" and "BBB" are the same name to a merchant, and letting both
     * exist would be confusing, not useful.
     *
     * @return 'duplicate'|'max_segments'|null
     */
    public static function rejection_reason( string $name ): ?string {
        $normalized = mb_strtolower( $name );

        // 'all' is the SPA's reserved value for the virtual "All customers"
        // option (never stored) — a stored segment with that exact name
        // would collide with it in the dropdown. Treated as a duplicate: to
        // the merchant it's the same complaint, "that name is taken."
        if ( self::RESERVED_NAME === $normalized ) {
            return 'duplicate';
        }

        $segments = self::get_segments();

        foreach ( $segments as $segment ) {
            if ( mb_strtolower( $segment['name'] ) === $normalized ) {
                return 'duplicate';
            }
        }

        if ( count( $segments ) >= self::MAX_SEGMENTS ) {
            return 'max_segments';
        }

        return null;
    }

    /**
     * Append a new segment and persist. Caller must already have checked
     * `rejection_reason( $name )` is `null` — this does not re-check.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array{name: string, filters: array<string, string>}>
     */
    public static function append_segment( string $name, array $filters ): array {
        $segments   = self::get_segments();
        $segments[] = [
            'name'    => $name,
            'filters' => self::sanitize_filters( $filters ),
        ];

        // Not autoloaded (`false`): read on one admin screen only, no reason
        // to load it on every request.
        update_option(
            self::OPTION_SEGMENTS,
            [
                'schema_version' => self::SCHEMA_VERSION,
                'items'          => $segments,
            ],
            false
        );

        return self::get_segments();
    }

    /**
     * Remove a segment by name (case-insensitive, same rule as
     * `rejection_reason()`) and persist. Returns the full remaining list on
     * success, `null` when no stored segment matched — the caller (the REST
     * controller) turns `null` into a 404. Idempotent by construction: two
     * removes of the same name can't both succeed, since the second finds
     * nothing left to match.
     *
     * @return array<int, array{name: string, filters: array<string, string>}>|null
     */
    public static function remove_segment( string $name ): ?array {
        $normalized = mb_strtolower( $name );
        $segments   = self::get_segments();

        $remaining = array_values(
            array_filter(
                $segments,
                static fn( $segment ) => mb_strtolower( $segment['name'] ) !== $normalized
            )
        );

        if ( count( $remaining ) === count( $segments ) ) {
            return null;
        }

        update_option(
            self::OPTION_SEGMENTS,
            [
                'schema_version' => self::SCHEMA_VERSION,
                'items'          => $remaining,
            ],
            false
        );

        return self::get_segments();
    }

    /**
     * @param mixed $item
     * @return array{name: string, filters: array<string, string>}|null
     */
    private static function sanitize_one( $item ): ?array {
        if ( ! is_array( $item ) ) {
            return null;
        }

        $name = self::sanitize_name( (string) ( $item['name'] ?? '' ) );

        if ( '' === $name ) {
            return null;
        }

        $filters = is_array( $item['filters'] ?? null ) ? $item['filters'] : [];

        return [
            'name'    => $name,
            'filters' => self::sanitize_filters( $filters ),
        ];
    }

    /**
     * Whitelist and coerce. Always returns all three keys — an unknown or
     * missing value falls back to that key's default — so every segment the
     * SPA reads is a complete `RowFilters` patch, never a partial one.
     *
     * `is_scalar()` guards the `(string)` cast: the REST route's `filters`
     * arg is an open `type: object` (see the controller), so a value can
     * arrive as an array (e.g. `{"tier": ["x"]}`) — casting THAT to string
     * raises a PHP warning instead of just falling back to the default.
     *
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    private static function sanitize_filters( array $filters ): array {
        $clean = [];

        foreach ( self::FILTER_SCHEMA as $field => $allowed ) {
            $raw             = $filters[ $field ] ?? null;
            $value           = is_scalar( $raw ) ? (string) $raw : '';
            $clean[ $field ] = in_array( $value, $allowed, true ) ? $value : $allowed[0];
        }

        return $clean;
    }
}
