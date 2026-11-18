<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Utils\ConfigSpec;

defined( 'ABSPATH' ) || exit;

/**
 * The referral program's settings, in Pro's own option so Lite never reads or rewrites them.
 * Money is a string in the store's base currency. A stored value that no longer fits its spec reads as the default.
 */
final class ReferralSettings {

    public const OPTION = 'yayloyalty_referral_settings';

    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_PERCENT = 'percent';

    private const PERCENT_MIN = 1;

    private const PERCENT_MAX = 100;

    private const SPEC = [
        'enabled'               => [ 'type' => 'bool' ],
        'discount_type'         => [
            'type'   => 'enum',
            'values' => [ self::DISCOUNT_FIXED, self::DISCOUNT_PERCENT ],
        ],
        'discount_amount'       => [
            'type'          => 'number',
            'exclusive_min' => 0,
        ],
        'min_order'             => [
            'type' => 'number',
            'min'  => 0,
        ],
        'coupon_days'           => [
            'type' => 'int',
            'min'  => 1,
            'max'  => 365,
        ],
        'advocate_points'       => [
            'type' => 'int',
            'min'  => 1,
        ],
        'hold_days'             => [
            'type' => 'int',
            'min'  => 0,
            'max'  => 90,
        ],
        'exclude_other_coupons' => [ 'type' => 'bool' ],
        'cookie_days'           => [
            'type' => 'int',
            'min'  => 1,
            'max'  => 365,
        ],
    ];

    /** @return array<string, mixed> */
    private static function defaults(): array {
        return [
            'enabled'               => false,
            'discount_type'         => self::DISCOUNT_FIXED,
            'discount_amount'       => '10',
            'min_order'             => '0',
            'coupon_days'           => 30,
            'advocate_points'       => 1000,
            'hold_days'             => 14,
            'exclude_other_coupons' => true,
            'cookie_days'           => 30,
        ];
    }

    /** @return array<string, mixed> */
    public static function get(): array {
        $stored   = get_option( self::OPTION, [] );
        $settings = self::defaults();

        foreach ( is_array( $stored ) ? $stored : [] as $key => $value ) {
            $clean = self::clean( (string) $key, $value );

            if ( null !== $clean ) {
                $settings[ $key ] = $clean;
            }
        }

        return self::fits_percent( $settings ) ? $settings : array_merge( $settings, [ 'discount_amount' => self::defaults()['discount_amount'] ] );
    }

    /**
     * The first sent field that doesn't fit its spec, judged against the stored settings it would merge into, or null.
     *
     * @param array<string, mixed> $input
     */
    public static function invalid_field( array $input ): ?string {
        foreach ( $input as $key => $value ) {
            if ( isset( self::SPEC[ $key ] ) && null === self::clean( (string) $key, $value ) ) {
                return (string) $key;
            }
        }

        return self::fits_percent( self::merged( $input ) ) ? null : 'discount_amount';
    }

    /**
     * Saves the sent fields over the stored ones and returns the result. Unknown keys are dropped.
     * Call invalid_field() first: a field that doesn't fit is skipped here, never stored.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function update( array $input ): array {
        update_option( self::OPTION, self::merged( $input ) );

        return self::get();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function merged( array $input ): array {
        $settings = self::get();

        foreach ( $input as $key => $value ) {
            $clean = self::clean( (string) $key, $value );

            if ( null !== $clean ) {
                $settings[ $key ] = $clean;
            }
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private static function fits_percent( array $settings ): bool {
        $amount = (float) $settings['discount_amount'];

        return self::DISCOUNT_PERCENT !== $settings['discount_type'] || ( $amount >= self::PERCENT_MIN && $amount <= self::PERCENT_MAX );
    }

    /**
     * $value as it is stored, or null when $key is unknown or the value doesn't fit its spec.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function clean( string $key, $value ) {
        if ( ! isset( self::SPEC[ $key ] ) ) {
            return null;
        }

        $spec = self::SPEC[ $key ];

        switch ( $spec['type'] ) {
            case 'number':
                // Plain decimals only, so the value checked is the value stored: "1e3" never becomes "13".
                $plain = is_int( $value ) || is_float( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d{1,12}(\.\d{1,4})?$/D', $value ) );
                $value = $plain ? wc_format_decimal( $value ) : null;
                break;

            case 'int':
                $plain = is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d{1,9}$/D', $value ) );
                $value = $plain ? (int) $value : null;
                break;
        }

        return null !== $value && ConfigSpec::matches( $value, $spec ) ? $value : null;
    }
}
