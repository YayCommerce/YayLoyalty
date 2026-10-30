<?php
namespace YayLoyalty\Pro\Helpers;

use YayLoyalty\Enums\CustomerFilters;

defined( 'ABSPATH' ) || exit;

/**
 * Saved Customers-page filter sets, a site-wide list in one option. A
 * segment is { name, filters }; name is the identity, and a collision is
 * rejected rather than upserted. Filters are whitelisted on read as well as
 * write: the blob is localised straight into the page and drives Select
 * values. Writes are read-check-write without locking, accepted: a small
 * admin-edited list where a retry fixes a lost race.
 */
final class CustomerSegmentsHelper {

    public const OPTION_SEGMENTS = 'yayloyalty_customer_segments';

    /** Bump when a migration changes the stored shape. */
    public const SCHEMA_VERSION = 1;

    private const MAX_SEGMENTS    = 50;
    private const MAX_NAME_LENGTH = 60;

    /** The SPA's ALL_SEGMENTS_VALUE; never a real segment's name. */
    private const RESERVED_NAME = 'all';

    /**
     * Allowed filter values by field; the first is the default. Same keys as
     * CustomerRowFilters::DEFAULTS minus `search`.
     *
     * @var array<string, string[]>
     */
    public const FILTER_SCHEMA = [
        'balance_range' => CustomerFilters::BALANCE_RANGES,
        'expiring_soon' => CustomerFilters::EXPIRY_RANGES,
    ];

    /**
     * Stored segments, sanitised for output; a corrupt option degrades to an empty list.
     *
     * @return array<int, array{name: string, filters: array<string, string>}>
     */
    public static function get_segments(): array {
        $stored = get_option( self::OPTION_SEGMENTS, [] );

        if ( ! is_array( $stored ) ) {
            return [];
        }

        // Current wrapper shape or a bare legacy list.
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

    /** Sanitises a name as storage does; the controller validates the result, since sanitize_text_field() can blank a tags-only string. */
    public static function sanitize_name( string $name ): string {
        return substr( sanitize_text_field( $name ), 0, self::MAX_NAME_LENGTH );
    }

    /**
     * Why a sanitised, non-blank name cannot be saved as a new segment, or null.
     * Names compare case-insensitively via mb_strtolower(): they can carry non-ASCII text.
     *
     * @return 'duplicate'|'max_segments'|null
     */
    public static function rejection_reason( string $name ): ?string {
        $normalized = mb_strtolower( $name );

        // Would collide with the virtual "All customers" option; to the merchant it is a duplicate.
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
     * Appends a segment and persists. Does not re-check rejection_reason().
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

        // Not autoloaded: read on one admin screen only.
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
     * Removes a segment by name (case-insensitive) and persists.
     *
     * @return array<int, array{name: string, filters: array<string, string>}>|null Remaining list, or null when nothing matched.
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
     * Whitelists and coerces; always returns every key so the SPA reads a
     * complete CustomerListFilters patch. is_scalar() guards the cast: the REST arg is
     * an open object, so a value can arrive as an array.
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
