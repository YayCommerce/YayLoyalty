<?php
namespace YayLoyalty\Controllers\Schemas;

use YayLoyalty\Enums\LedgerType;

defined( 'ABSPATH' ) || exit;

/**
 * The four extra `GET /customers/{id}/ledger` args the customer drawer's
 * ledger tab needs, plus the type-group vocabulary they filter by — split
 * out of LedgerRestController to keep it near the 200-line cap.
 */
final class LedgerQueryArgs {

    /**
     * Grouped filter vocabulary, not the raw `LedgerType` enum: `earn` covers
     * `earn` + `refund_earn`, `redeem` covers `redeem` + `refund_redeem`,
     * `adjust` covers `adjust` + `correction`. `all` plus the four groups the
     * artboards show.
     *
     * @return string[]
     */
    public static function filter_types(): array {
        return [ 'all', 'earn', 'redeem', LedgerType::ADJUST, LedgerType::EXPIRE ];
    }

    /** The filter group a raw `LedgerType` value belongs to. */
    public static function group_of( string $type ): string {
        if ( in_array( $type, [ LedgerType::EARN, LedgerType::REFUND_EARN ], true ) ) {
            return 'earn';
        }

        if ( in_array( $type, [ LedgerType::REDEEM, LedgerType::REFUND_REDEEM ], true ) ) {
            return 'redeem';
        }

        if ( LedgerType::is_manual_adjustment( $type ) ) {
            return LedgerType::ADJUST;
        }

        return $type;
    }

    /**
     * The raw `LedgerType` values a filter group covers; empty for `all`
     * (no type filter). Derived from group_of() so the grouping lives once.
     *
     * @return string[]
     */
    public static function types_of( string $group ): array {
        if ( 'all' === $group ) {
            return [];
        }

        return array_values(
            array_filter( LedgerType::all(), fn( string $type ) => self::group_of( $type ) === $group )
        );
    }

    /**
     * Raw per-`LedgerType` row counts folded into every filter group, `all`
     * the total — the shape of the endpoint's `type_counts`.
     *
     * @param array<string, int> $by_type
     * @return array<string, int>
     */
    public static function group_counts( array $by_type ): array {
        $counts = array_fill_keys( self::filter_types(), 0 );

        foreach ( $by_type as $type => $count ) {
            $counts[ self::group_of( $type ) ] += $count;
            $counts['all']                     += $count;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array {
        return [
            'type'   => [
                'description'       => __( 'Ledger type group to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => self::filter_types(),
                'default'           => 'all',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'search' => [
                'description'       => __( 'Free-text search over description and order number.', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'after'  => [
                'description'       => __( 'Only entries on or after this date (Y-m-d).', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ self::class, 'validate_date' ],
            ],
            'before' => [
                'description'       => __( 'Only entries on or before this date (Y-m-d).', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ self::class, 'validate_date' ],
            ],
        ];
    }

    /**
     * @param mixed $value
     */
    public static function validate_date( $value ): bool {
        if ( ! is_string( $value ) || '' === $value ) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value );

        return false !== $date && $date->format( 'Y-m-d' ) === $value;
    }
}
