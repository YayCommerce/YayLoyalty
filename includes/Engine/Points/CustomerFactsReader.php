<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** Reads CustomerFacts from WooCommerce and the ledger, so whatever consumes the facts queries nothing. */
final class CustomerFactsReader {

    /**
     * Paid means OrderStatus::paid(), the status that awards, so order_count and the award path agree.
     *
     * @param string   $as_of            UTC MySQL datetime; only orders created strictly before it count.
     * @param int|null $exclude_order_id The order being evaluated, never counted as its own history.
     */
    public static function read( int $user_id, string $as_of, ?int $exclude_order_id ): CustomerFacts {
        // TODO(customer-facts-query): count and sum orders in SQL per order storage, instead of loading every paid order, if long histories get slow.
        $orders = wc_get_orders(
            [
                'customer_id'  => $user_id,
                'status'       => OrderStatus::paid(),
                'date_created' => '<' . Dates::to_timestamp( $as_of ),
                'exclude'      => $exclude_order_id ? [ $exclude_order_id ] : [],
                'limit'        => -1,
                'return'       => 'objects',
            ]
        );

        $spend_by_currency = [];

        foreach ( $orders as $order ) {
            $currency                       = (string) $order->get_currency();
            $spend_by_currency[ $currency ] = ( $spend_by_currency[ $currency ] ?? 0.0 ) + (float) $order->get_total();
        }

        return new CustomerFacts(
            ( new LedgerRepository() )->lifetime_earned_before( $user_id, $as_of ),
            count( $orders ),
            $spend_by_currency
        );
    }
}
