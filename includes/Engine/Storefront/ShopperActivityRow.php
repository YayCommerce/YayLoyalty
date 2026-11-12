<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\TierRepository;
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
     * @return array<int, array{label: string, meta: string, delta: string, tone: string}> `tone` as tone() returns it.
     */
    public static function items( array $entries ): array {
        $store = Text::store_name();
        $label = SettingsHelper::points_label_plural();
        $tiers = self::tier_names( $entries );
        $items = [];

        foreach ( $entries as $entry ) {
            $items[] = [
                'label' => self::label( $entry, $store, $label, $tiers ),
                'meta'  => self::meta_line( $entry->get_created_at(), $entry->get_order_id() ),
                'delta' => LauncherCopy::signed_points( $entry->get_points() ),
                'tone'  => self::tone( $entry->get_type(), $entry->get_points() ),
            ];
        }

        return $items;
    }

    /** A row's points badge colour, the same in the history table and the launcher: 'expire', 'plus' or 'minus'. */
    public static function tone( string $type, int $points ): string {
        if ( LedgerType::EXPIRE === $type ) {
            return 'expire';
        }

        return $points > 0 ? 'plus' : 'minus';
    }

    /**
     * Ledger rows for the My Account history and its CSV, unformatted: each caller formats for its medium.
     *
     * @param LedgerEntry[] $entries
     * @return array<int, array{created_at: string, type: string, label: string, order: string, order_url: string, points: int, balance: int}>
     *         `created_at` in UTC; `order` the order number, or '' when the row has none; `order_url` its My Account
     *         page, or '' when the order is gone.
     */
    public static function history_rows( array $entries ): array {
        $store  = Text::store_name();
        $label  = SettingsHelper::points_label_plural();
        $tiers  = self::tier_names( $entries );
        $orders = [];
        $rows   = [];

        foreach ( $entries as $entry ) {
            $order_id = $entry->get_order_id();

            if ( null !== $order_id && ! isset( $orders[ $order_id ] ) ) {
                // An earn row and its refund share an order: load it once.
                $orders[ $order_id ] = self::order_ref( $order_id );
            }

            $rows[] = [
                'created_at' => $entry->get_created_at(),
                'type'       => $entry->get_type(),
                'label'      => self::label( $entry, $store, $label, $tiers ),
                'order'      => null === $order_id ? '' : $orders[ $order_id ]['number'],
                'order_url'  => null === $order_id ? '' : $orders[ $order_id ]['url'],
                'points'     => $entry->get_points(),
                'balance'    => $entry->get_balance_after(),
            ];
        }

        return $rows;
    }

    /**
     * The names of the tiers these rows' entry bonuses were paid for, in one query. Tiers are soft-deleted,
     * so a bonus keeps its label after its tier is removed, and on Lite.
     *
     * @param LedgerEntry[] $entries
     * @return array<int, string> Tier id => name.
     */
    private static function tier_names( array $entries ): array {
        $ids = [];

        foreach ( $entries as $entry ) {
            if ( LedgerSource::TIER_BONUS === $entry->get_source() && null !== $entry->get_reference_id() ) {
                $ids[] = $entry->get_reference_id();
            }
        }

        return ( new TierRepository() )->names_by_id( $ids );
    }

    /**
     * @param string             $store_name   Shown on adjustments without a customer note ("Adjustment by {store}").
     * @param string             $points_label The merchant's plural points label, as "Points".
     * @param array<int, string> $tier_names   From tier_names().
     */
    private static function label( LedgerEntry $entry, string $store_name, string $points_label, array $tier_names ): string {
        switch ( $entry->get_type() ) {
            case LedgerType::EARN:
                return self::earn_label( $entry, $points_label, $tier_names );

            case LedgerType::REDEEM:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s for discount', 'yayloyalty' ), $points_label );

            case LedgerType::REFUND_EARN:
                if ( ReferenceType::COMMENT === $entry->get_reference_type() ) {
                    /* translators: %s: the store's points label, as "Points". */
                    return sprintf( __( '%s removed — review unpublished', 'yayloyalty' ), $points_label );
                }

                // An order reference is a cancel, a trash or the refunded status: all read as refunded to the shopper.
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
                $customer_note = LedgerSource::MANUAL === $entry->get_source() ? Text::plain( (string) $entry->get_customer_note() ) : '';

                if ( '' !== $customer_note ) {
                    return $customer_note;
                }

                /* translators: %s: store name. */
                return sprintf( __( 'Adjustment by %s', 'yayloyalty' ), $store_name );
        }
    }

    /** @param array<int, string> $tier_names From tier_names(). */
    private static function earn_label( LedgerEntry $entry, string $points_label, array $tier_names ): string {
        switch ( $entry->get_source() ) {
            case LedgerSource::ORDER:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s earned from an order', 'yayloyalty' ), $points_label );

            case LedgerSource::SIGNUP:
                return __( 'Account created', 'yayloyalty' );

            case LedgerSource::REVIEW:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s earned from a review', 'yayloyalty' ), $points_label );

            case LedgerSource::BIRTHDAY:
                return __( 'Birthday bonus', 'yayloyalty' );

            case LedgerSource::TIER_BONUS:
                $name = $tier_names[ (int) $entry->get_reference_id() ] ?? '';

                return '' === $name
                    ? __( 'Tier bonus', 'yayloyalty' )
                    /* translators: %s: the tier's name. */
                    : sprintf( __( '%s tier bonus', 'yayloyalty' ), $name );

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
        return sprintf( __( '%1$s · order #%2$s', 'yayloyalty' ), $date, self::order_ref( $order_id )['number'] );
    }

    /**
     * The order's display number and My Account link; once the order is gone, its id and no link.
     *
     * @return array{number: string, url: string}
     */
    private static function order_ref( int $order_id ): array {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

        return [
            'number' => $order ? (string) $order->get_order_number() : (string) $order_id,
            'url'    => $order ? $order->get_view_order_url() : '',
        ];
    }
}
