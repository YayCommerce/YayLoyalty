<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * The base-currency amount a purchase campaign earns on: included lines'
 * totals per `base`, plus the share of our own redemption discount that
 * fell on them, since spending points must not reduce future earning.
 */
final class BaseCalculator {

    public function compute( Campaign $campaign, Context $ctx ): float {
        $config           = $campaign->get_config();
        $before_discounts = 'before_discounts' === ( $config['base'] ?? 'after_discounts' );
        $excluded_ids     = array_map( 'intval', (array) ( $config['excluded_product_ids'] ?? [] ) );
        $excluded_cats    = array_map( 'intval', (array) ( $config['excluded_category_ids'] ?? [] ) );

        $included          = 0.0; // What we earn on, order currency.
        $included_subtotal = 0.0; // Pre-discount share of the order, for proportioning.

        foreach ( $ctx->get_line_items() as $item ) {
            if ( in_array( $item->get_product_id(), $excluded_ids, true ) ) {
                continue;
            }

            if ( array_intersect( $item->get_category_ids(), $excluded_cats ) ) {
                continue;
            }

            $included_subtotal += $item->get_subtotal();
            $included          += $before_discounts ? $item->get_subtotal() : $item->get_total();

            if ( ! empty( $config['include_tax'] ) ) {
                $included += $item->get_tax_total();
            }
        }

        // Only the included lines' share: the part on an excluded product never counted.
        if ( ! $before_discounts && $ctx->get_loyalty_discount() > 0 && $ctx->get_order_subtotal() > 0 ) {
            $included += $ctx->get_loyalty_discount() * ( $included_subtotal / $ctx->get_order_subtotal() );
        }

        $base = max( 0.0, $included * $ctx->get_base_rate() );

        /**
         * Base-currency amount a purchase campaign earns on.
         *
         * @param float    $base
         * @param Campaign $campaign
         * @param Context  $ctx
         */
        return (float) apply_filters( 'yayloyalty_earning_base_amount', $base, $campaign, $ctx );
    }
}
