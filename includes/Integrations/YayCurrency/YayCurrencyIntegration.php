<?php
namespace YayLoyalty\Integrations\YayCurrency;

use YayLoyalty\Controllers\BaseRestController;
use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Log;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a point's value the same in every currency a shopper picks in YayCurrency: answers
 * yayloyalty_currency_rate from YayCurrency's rates, saves the rate on each non-base order, keeps
 * YayCurrency from converting the loyalty coupons a second time, and keeps its conversion off
 * yayloyalty/v1 REST requests. YayLoyalty boots before YayCurrency loads its classes, so they are
 * named only inside these callbacks, through YayCurrencyRates.
 */
class YayCurrencyIntegration {

    use SingletonTrait;

    /** The WC_Coupon getters YayCurrency multiplies by its rate, keyed by their filter. */
    private const COUPON_GETTERS = [
        'woocommerce_coupon_get_amount'         => 'get_amount',
        'woocommerce_coupon_get_minimum_amount' => 'get_minimum_amount',
        'woocommerce_coupon_get_maximum_amount' => 'get_maximum_amount',
    ];

    protected function __construct() {
        if ( ! self::is_active() ) {
            return;
        }

        add_filter( 'yayloyalty_currency_rate', [ $this, 'rate' ], 10, 3 );
        add_action( 'woocommerce_checkout_create_order', [ OrderRateSnapshot::class, 'save' ], 20, 1 );
        add_action( 'woocommerce_store_api_checkout_update_order_meta', [ OrderRateSnapshot::class, 'save' ], 20, 1 );
        add_filter( 'YayCurrency/ConvertPrice/NotPermitted', [ $this, 'skip_own_rest_requests' ], 10, 1 );

        // After YayCurrency's own conversion at 10.
        foreach ( array_keys( self::COUPON_GETTERS ) as $hook ) {
            add_filter( $hook, [ $this, 'keep_loyalty_coupon_amount' ], 20, 2 );
        }
    }

    /** YayCurrency's main file defines this before YayLoyalty boots. */
    public static function is_active(): bool {
        return defined( 'YAY_CURRENCY_VERSION' );
    }

    /** The order's saved rate, else YayCurrency's live one (after fee when the merchant includes it), else 1.0. */
    public function rate( float $rate, string $currency, ?\WC_Order $order ): float {
        $saved = null === $order ? null : OrderRateSnapshot::read( $order );

        // The saved rate wins, so the live one is looked up only without it.
        if ( CurrencyRate::is_usable( $saved ) ) {
            return (float) $saved;
        }

        $live = YayCurrencyRates::live_rate( $currency, (bool) SettingsHelper::get_settings()['currency_include_fee'] );

        if ( ! CurrencyRate::is_usable( $live ) ) {
            Log::debug(
                'YayCurrencyIntegration',
                sprintf( 'No usable rate for %s on order %s; using 1.0.', $currency, null === $order ? 'none' : (string) $order->get_id() )
            );
        }

        return CurrencyRateMath::resolve( $saved, $live );
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
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field() runs after rawurldecode() on purpose: run first, it strips the %XX octets this match needs decoded.
        $uri = sanitize_text_field( rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );

        $namespace = '/' . BaseRestController::REST_NAMESPACE . '/';

        if ( str_contains( $uri, '/' . rest_get_url_prefix() . $namespace ) || str_contains( $uri, 'rest_route=' . $namespace ) ) {
            return true;
        }

        return $not_permitted;
    }

    /**
     * The loyalty coupon's own value, already in the charged currency, in place of YayCurrency's conversion of it.
     * Every other coupon keeps the incoming value, so YayCurrency still converts it.
     *
     * @param mixed      $value  The value after YayCurrency's filter.
     * @param \WC_Coupon $coupon
     * @return mixed
     */
    public function keep_loyalty_coupon_amount( $value, $coupon ) {
        $getter = self::COUPON_GETTERS[ current_filter() ] ?? null;

        if ( null === $getter || ! $coupon instanceof \WC_Coupon || ! EarnContext::is_loyalty_code( (string) $coupon->get_code() ) ) {
            return $value;
        }

        return $coupon->$getter( 'edit' );
    }
}
