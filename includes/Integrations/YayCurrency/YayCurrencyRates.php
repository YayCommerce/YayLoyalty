<?php
namespace YayLoyalty\Integrations\YayCurrency;

defined( 'ABSPATH' ) || exit;

/**
 * The only class that calls YayCurrency, and only through its public helpers.
 * Called from hook callbacks that run after plugins_loaded, once YayCurrency's classes can autoload.
 */
final class YayCurrencyRates {

    /** @var array<string, float|null> live_rate() per currency and fee choice, for this request: YayCurrency rebuilds its list on every lookup. */
    private static $live = [];

    /** YayCurrency's rate for $currency, after its exchange fee when $include_fee, or null when it doesn't list $currency. */
    public static function live_rate( string $currency, bool $include_fee ): ?float {
        $key = $currency . '|' . (int) $include_fee;

        if ( ! array_key_exists( $key, self::$live ) ) {
            self::$live[ $key ] = self::lookup( $currency, $include_fee );
        }

        return self::$live[ $key ];
    }

    private static function lookup( string $currency, bool $include_fee ): ?float {
        $listed = \Yay_Currency\Helpers\YayCurrencyHelper::get_currency_by_currency_code( $currency );

        if ( ! $listed ) {
            return null;
        }

        if ( $include_fee ) {
            return (float) \Yay_Currency\Helpers\YayCurrencyHelper::get_rate_fee( $listed );
        }

        return (float) ( $listed['rate'] ?? 0 );
    }

    public static function base_currency(): string {
        return (string) \Yay_Currency\Helpers\Helper::default_currency_code();
    }
}
