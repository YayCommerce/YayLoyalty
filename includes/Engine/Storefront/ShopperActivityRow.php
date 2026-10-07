<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
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
        // 0 picks the plural label.
        $label = SettingsHelper::points_label( 0 );
        $items = [];

        foreach ( $entries as $entry ) {
            $items[] = [
                'label'  => self::label( $entry, $store, $label ),
                'meta'   => self::meta_line( $entry->get_created_at(), $entry->get_order_id() ),
                'points' => $entry->get_points(),
                'delta'  => LauncherCopy::signed_points( $entry->get_points() ),
            ];
        }

        return $items;
    }

    /**
     * @param string $store_name   Shown on adjustments ("Adjustment by {store}").
     * @param string $points_label The merchant's plural points label, as "Points".
     */
    private static function label( LedgerEntry $entry, string $store_name, string $points_label ): string {
        switch ( $entry->get_type() ) {
            case LedgerType::EARN:
                return self::earn_label( $entry->get_source(), $points_label );

            case LedgerType::REDEEM:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s for discount', 'yayloyalty' ), $points_label );

            case LedgerType::REFUND_EARN:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s removed — order refunded', 'yayloyalty' ), $points_label );

            case LedgerType::REFUND_REDEEM:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s restored — refund', 'yayloyalty' ), $points_label );

            case LedgerType::EXPIRE:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s expired', 'yayloyalty' ), $points_label );

            default:
                /* translators: %s: store name. */
                return sprintf( __( 'Adjustment by %s', 'yayloyalty' ), $store_name );
        }
    }

    private static function earn_label( string $source, string $points_label ): string {
        switch ( $source ) {
            case LedgerSource::ORDER:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s earned from an order', 'yayloyalty' ), $points_label );

            case LedgerSource::SIGNUP:
                return __( 'Account created', 'yayloyalty' );

            case LedgerSource::REVIEW:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s earned from a review', 'yayloyalty' ), $points_label );

            default:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s earned', 'yayloyalty' ), $points_label );
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
