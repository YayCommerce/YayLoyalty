<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * One ledger row as the shopper sees it in the launcher's "Recent activity".
 *
 * A shopper-safe projection, not the admin ledger shape: no admin note (it can name an internal
 * reason), no actor id, no campaign id. Adjustments read as made by the store, never by a person.
 */
final class ShopperActivityRow {

    /**
     * Ledger rows as the launcher lists them: GET /me/activity's items, and the Design tab's sample rows.
     *
     * @param LedgerEntry[] $entries
     * @return array<int, array{label: string, meta: string, points: int, delta: string}>
     */
    public static function items( array $entries ): array {
        $store = Text::store_name();
        $items = [];

        foreach ( $entries as $entry ) {
            $row     = self::from_entry( $entry, $store );
            $items[] = [
                'label'  => $row['label'],
                'meta'   => self::meta_line( $row['created_at'], $row['order_id'] ),
                'points' => $row['points'],
                'delta'  => LauncherCopy::signed_points( $row['points'] ),
            ];
        }

        return $items;
    }

    /**
     * @param string $store_name Shown on adjustments ("Adjustment by {store}").
     * @return array{label: string, order_id: ?int, points: int, created_at: string}
     */
    public static function from_entry( LedgerEntry $entry, string $store_name ): array {
        return [
            'label'      => self::label( $entry, $store_name ),
            'order_id'   => $entry->get_order_id(),
            'points'     => $entry->get_points(),
            'created_at' => $entry->get_created_at(),
        ];
    }

    private static function label( LedgerEntry $entry, string $store_name ): string {
        switch ( $entry->get_type() ) {
            case LedgerType::EARN:
                return self::earn_label( $entry->get_source() );

            case LedgerType::REDEEM:
                return __( 'Points for discount', 'yayloyalty' );

            case LedgerType::REFUND_EARN:
                return __( 'Points removed — order refunded', 'yayloyalty' );

            case LedgerType::REFUND_REDEEM:
                return __( 'Refund — points restored', 'yayloyalty' );

            case LedgerType::EXPIRE:
                return __( 'Points expired', 'yayloyalty' );

            default:
                /* translators: %s: store name. */
                return sprintf( __( 'Adjustment by %s', 'yayloyalty' ), $store_name );
        }
    }

    private static function earn_label( string $source ): string {
        switch ( $source ) {
            case LedgerSource::ORDER:
                return __( 'Order earned points', 'yayloyalty' );

            case LedgerSource::SIGNUP:
                return __( 'Account created', 'yayloyalty' );

            case LedgerSource::REVIEW:
                return __( 'Review earned points', 'yayloyalty' );

            default:
                return __( 'Points earned', 'yayloyalty' );
        }
    }

    /** "9 Sept 2026 · order #4207": the date in the site's format and timezone, then the order when there is one. */
    private static function meta_line( string $created_at_utc, ?int $order_id ): string {
        $date = Dates::to_site_tz( $created_at_utc, (string) get_option( 'date_format' ) );

        if ( null === $order_id ) {
            return $date;
        }

        $order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        $number = $order ? $order->get_order_number() : (string) $order_id;

        /* translators: 1: date, 2: order number. */
        return sprintf( __( '%1$s · order #%2$s', 'yayloyalty' ), $date, $number );
    }
}
