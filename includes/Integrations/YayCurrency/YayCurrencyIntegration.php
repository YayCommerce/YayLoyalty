<?php
namespace YayLoyalty\Integrations\YayCurrency;

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Log;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a point's value the same in every currency a shopper picks in YayCurrency: answers
 * yayloyalty_currency_rate from YayCurrency's rates, saves the rate on each non-base order, and keeps
 * YayCurrency's conversion off yayloyalty/v1 REST requests. YayLoyalty boots before YayCurrency loads
 * its classes, so they are named only inside these callbacks, through YayCurrencyRates.
 */
class YayCurrencyIntegration {

    use SingletonTrait;

    protected function __construct() {
        if ( ! self::is_active() ) {
            return;
        }

        add_filter( 'yayloyalty_currency_rate', [ $this, 'rate' ], 10, 3 );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_order_rate' ], 20, 1 );
        add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'save_order_rate' ], 20, 1 );
        add_filter( 'YayCurrency/ConvertPrice/NotPermitted', [ $this, 'skip_own_rest_requests' ], 10, 1 );
    }

    /** YayCurrency's main file defines this before YayLoyalty boots. */
    public static function is_active(): bool {
        return defined( 'YAY_CURRENCY_VERSION' );
    }

    /** The order's saved rate, else YayCurrency's live one (after fee when the merchant includes it), else 1.0. */
    public function rate( float $rate, string $currency, ?\WC_Order $order ): float {
        $saved = null === $order ? null : OrderRateSnapshot::read( $order );
        $live  = YayCurrencyRates::live_rate( $currency, (bool) SettingsHelper::get_settings()['currency_include_fee'] );

        if ( self::unusable( $saved ) && self::unusable( $live ) ) {
            Log::debug(
                'YayCurrencyIntegration',
                sprintf( 'No usable rate for %s on order %s; using 1.0.', $currency, null === $order ? 'none' : (string) $order->get_id() )
            );
        }

        return CurrencyRateMath::resolve( $saved, $live );
    }

    public function save_order_rate( \WC_Order $order ): void {
        OrderRateSnapshot::save( $order );
    }

    /**
     * The admin SPA and the Design preview call yayloyalty/v1 and must see base-currency amounts,
     * even when the admin's browser carries a YayCurrency cookie.
     *
     * @param bool $not_permitted
     */
    public function skip_own_rest_requests( $not_permitted ) {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return $not_permitted;
        }

        // Decoded first, so an encoded rest_route=%2Fyayloyalty%2Fv1%2F matches too.
        $uri = sanitize_text_field( rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );

        if ( str_contains( $uri, '/' . rest_get_url_prefix() . '/yayloyalty/v1/' ) || str_contains( $uri, 'rest_route=/yayloyalty/v1/' ) ) {
            return true;
        }

        return $not_permitted;
    }

    /** @param string|float|null $candidate */
    private static function unusable( $candidate ): bool {
        return ! is_numeric( $candidate ) || (float) $candidate <= 0;
    }
}
