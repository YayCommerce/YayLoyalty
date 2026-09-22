<?php
namespace YayLoyalty\Controllers\Schemas;

use YayLoyalty\Enums\LedgerType;

defined( 'ABSPATH' ) || exit;

/**
 * The four extra `GET /customers/{id}/ledger` args the customer drawer's
 * ledger tab needs — split out of LedgerRestController to keep it near the
 * 200-line cap.
 *
 * Registered on every request, real path included, but the real path has no
 * SQL behind them yet (Wave B: cheap `WHERE`/`BETWEEN`/`LIKE` filters, an
 * expensive `GROUP BY` for per-type counts — two different costs, not
 * bundled). The real handler accepts and ignores them, returning
 * `type_counts: null` as the "server cannot filter yet" signal the SPA
 * renders a disabled filter row from.
 */
final class LedgerQueryArgs {

    /**
     * Grouped filter vocabulary, not the raw `LedgerType` enum: `earn` covers
     * `earn` + `refund_earn`, `redeem` covers `redeem` + `refund_redeem`.
     * Five labels over six ledger types — `all` plus the four groups the
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

        return $type;
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
