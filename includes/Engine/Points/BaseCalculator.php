<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * The base-currency amount a purchase campaign earns on: included lines'
 * totals per `base`. After discounts, every coupon on the line lowers it,
 * merchant coupons and our own points discount and reward coupons alike.
 */
final class BaseCalculator {

    public function compute( Campaign $campaign, Context $ctx ): float {
        $config           = $campaign->get_config();
        $before_discounts = 'before_discounts' === ( $config['base'] ?? 'after_discounts' );

        $included = 0.0; // What we earn on, order currency.

        foreach ( $ctx->get_line_items() as $item ) {
            if ( ! self::includes_line( $campaign, $item ) ) {
                continue;
            }

            $included += $before_discounts ? $item->get_subtotal() : $item->get_total();

            if ( ! empty( $config['include_tax'] ) ) {
                $included += $item->get_tax_total();
            }
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

    /** Whether a line counts toward the campaign's base: not an excluded product, not in an excluded category. Also decides a refund's clawback share. */
    public static function includes_line( Campaign $campaign, ContextLineItem $item ): bool {
        $config = $campaign->get_config();

        if ( in_array( $item->get_product_id(), array_map( 'intval', (array) ( $config['excluded_product_ids'] ?? [] ) ), true ) ) {
            return false;
        }

        return ! array_intersect( $item->get_category_ids(), array_map( 'intval', (array) ( $config['excluded_category_ids'] ?? [] ) ) );
    }
}
