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
 * reason), no actor id, no campaign id. Adjustments read as made by the store, never by a person,
 * unless the admin wrote a customer note for that adjustment.
 */
final class ShopperActivityRow {

    /**
     * Ledger rows as the launcher lists them: GET /me/activity's items, and the Design tab's sample rows.
     *
     * @param LedgerEntry[] $entries
     * @return array<int, array{label: string, meta: string, delta: string}>
     */
    public static function items( array $entries ): array {
        $store = Text::store_name();
        // 0 picks the plural label.
        $label = SettingsHelper::points_label( 0 );
        $items = [];

        foreach ( $entries as $entry ) {
            $items[] = [
                'label' => self::label( $entry, $store, $label ),
                'meta'  => self::meta_line( $entry->get_created_at(), $entry->get_order_id() ),
                'delta' => LauncherCopy::signed_points( $entry->get_points() ),
            ];
        }

        return $items;
    }

    /**
     * Ledger rows for the My Account history and its CSV, unformatted: each caller formats for its medium.
     *
     * @param LedgerEntry[] $entries
     * @return array<int, array{created_at: string, label: string, order: string, points: int, balance: int}>
     *         `created_at` in UTC; `order` the order number, or '' when the row has none.
     */
    public static function history_rows( array $entries ): array {
        $store = Text::store_name();
        // 0 picks the plural label.
        $label  = SettingsHelper::points_label( 0 );
        $orders = [];
        $rows   = [];

        foreach ( $entries as $entry ) {
            $order_id = $entry->get_order_id();

            if ( null !== $order_id && ! isset( $orders[ $order_id ] ) ) {
                // An earn row and its refund share an order: load it once.
                $orders[ $order_id ] = self::order_number( $order_id );
            }

            $rows[] = [
                'created_at' => $entry->get_created_at(),
                'label'      => self::label( $entry, $store, $label ),
                'order'      => null === $order_id ? '' : $orders[ $order_id ],
                'points'     => $entry->get_points(),
                'balance'    => $entry->get_balance_after(),
            ];
        }

        return $rows;
    }

    /**
     * @param string $store_name   Shown on adjustments without a customer note ("Adjustment by {store}").
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
                // A system ADJUST, such as LedgerService::restore_refund()'s, keeps the store label.
                $customer_note = LedgerSource::MANUAL === $entry->get_source() ? (string) $entry->get_customer_note() : '';

                if ( '' !== $customer_note ) {
                    return $customer_note;
                }

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
        $date = Dates::to_site_date( $created_at_utc );

        if ( null === $order_id ) {
            return $date;
        }

        /* translators: 1: date, 2: order number. */
        return sprintf( __( '%1$s · order #%2$s', 'yayloyalty' ), $date, self::order_number( $order_id ) );
    }

    /** The order's display number, or its id once the order is gone. */
    private static function order_number( int $order_id ): string {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

        return $order ? (string) $order->get_order_number() : (string) $order_id;
    }
}
