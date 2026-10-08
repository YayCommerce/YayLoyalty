<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage: one option, validated on read as well as write, since
 * the stored blob is localised straight into the admin page and an older
 * version or another plugin may have written it.
 */
class SettingsHelper {

    public const OPTION_SETTINGS = 'yayloyalty_settings';
    public const OPTION_REVIEWED = 'yayloyalty_reviewed';

    /** @var array{locale: string, saved: mixed, settings: array}|null The last get_settings() result and what it was built from. */
    private static $cache = null;

    /** Longest points label; the admin form's schema (advanced-settings.form.ts) mirrors it. */
    public const POINTS_LABEL_MAX_LENGTH = 30;

    /** Bump when a migration changes the stored shape. */
    public const SCHEMA_VERSION = 2;

    public static function get_default_settings(): array {
        return [
            'general'  => [
                'enabled'        => true,
                // Role slugs that never earn, read by Engine\Earning\ProgramGate.
                'excluded_roles' => [],
            ],
            'advanced' => [
                'points_label_singular' => __( 'Point', 'yayloyalty' ),
                'points_label_plural'   => __( 'Points', 'yayloyalty' ),
                // null means no limit. Enforced by Pro's DailyEarningLimit.
                'max_points_per_day'    => null,
            ],
            'emails'   => [
                // Weekly per-customer cap on loyalty emails. null means no cap. Engine\Emails\EmailThrottle enforces it.
                'throttle_per_week' => 3,
            ],
        ];
    }

    /**
     * Type map by group then field. Anything absent is dropped in both directions.
     *
     * @return array<string, array<string, string>>
     */
    private static function schema(): array {
        return [
            'general'  => [
                'enabled'        => 'bool',
                'excluded_roles' => 'slug_list',
            ],
            'advanced' => [
                'points_label_singular' => 'points_label',
                'points_label_plural'   => 'points_label',
                'max_points_per_day'    => 'limit',
            ],
            'emails'   => [
                'throttle_per_week' => 'limit',
            ],
        ];
    }

    /**
     * Stored settings merged over defaults, sanitised for output.
     *
     * A page asks for settings many times (every points label, every gate), so the result is reused while
     * the stored option and the locale are unchanged. The locale is part of the key because the defaults
     * are translated, and WooCommerce switches it while it sends an email in the customer's language.
     */
    public static function get_settings(): array {
        $saved  = get_option( self::OPTION_SETTINGS, [] );
        $locale = get_locale();

        if ( null !== self::$cache && $locale === self::$cache['locale'] && $saved === self::$cache['saved'] ) {
            return self::$cache['settings'];
        }

        self::$cache = [
            'locale'   => $locale,
            'saved'    => $saved,
            'settings' => self::build_settings( $saved ),
        ];

        return self::$cache['settings'];
    }

    /** @param mixed $saved The raw stored option. */
    private static function build_settings( $saved ): array {
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        // Per field, not recursive: a recursive merge would splice a stored list into the default list by index.
        $merged = self::get_default_settings();

        foreach ( self::sanitize_output( $saved ) as $group => $fields ) {
            $merged[ $group ] = array_replace( $merged[ $group ], $fields );
        }

        $merged['schema_version'] = isset( $saved['schema_version'] )
            ? (int) $saved['schema_version']
            : self::SCHEMA_VERSION;

        return $merged;
    }

    /** The merchant's point label for $points: singular at exactly 1, plural otherwise. */
    public static function points_label( int $points ): string {
        $advanced = self::get_settings()['advanced'];

        return 1 === $points ? $advanced['points_label_singular'] : $advanced['points_label_plural'];
    }

    /** The merchant's plural label, for a sentence with no count: "View your Points". */
    public static function points_label_plural(): string {
        return self::get_settings()['advanced']['points_label_plural'];
    }

    /** "50 Points": the amount in the store's number format with the merchant's label, in an order translators set. */
    public static function points_text( int $points ): string {
        /* translators: 1: number of points, 2: the store's points label, e.g. "Points". */
        return sprintf( _x( '%1$s %2$s', 'points amount', 'yayloyalty' ), self::format_number( $points ), self::points_label( $points ) );
    }

    /** A number with WooCommerce's price separators, so points read like money: "12.400" in a store priced "12.400 ₫". */
    public static function format_number( float $value, int $decimals = 0 ): string {
        if ( ! function_exists( 'wc_get_price_thousand_separator' ) ) {
            return number_format_i18n( $value, $decimals );
        }

        return number_format( $value, $decimals, wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
    }

    /**
     * Saves only the fields sent, over what is stored, and returns the merged settings.
     *
     * Defaults are never written, so translated defaults such as the point labels
     * keep following the site language. Fields are replaced one by one rather than
     * merged recursively, so a list can be emptied.
     */
    public static function update_settings( array $settings ): array {
        $saved  = get_option( self::OPTION_SETTINGS, [] );
        $stored = self::sanitize_output( is_array( $saved ) ? $saved : [] );

        foreach ( self::validate( $settings ) as $group => $fields ) {
            foreach ( $fields as $field => $value ) {
                $stored[ $group ][ $field ] = $value;
            }
        }

        $stored['schema_version'] = self::SCHEMA_VERSION;

        update_option( self::OPTION_SETTINGS, $stored );

        return self::get_settings();
    }

    /**
     * Whether every `limit` field in $input is null or a whole number from 1. The controllers answer
     * 400 on false; coerce() alone would read a 0 or a fraction as "no limit" without saying so.
     *
     * @param array<string, mixed> $input
     */
    public static function is_valid( array $input ): bool {
        foreach ( self::schema() as $group => $fields ) {
            foreach ( $fields as $field => $type ) {
                if ( 'limit' !== $type || ! isset( $input[ $group ] ) || ! is_array( $input[ $group ] ) || ! array_key_exists( $field, $input[ $group ] ) ) {
                    continue;
                }

                $value = $input[ $group ][ $field ];

                if ( null !== $value && ! self::is_limit( $value ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param mixed $value */
    private static function is_limit( $value ): bool {
        return ! is_bool( $value ) && is_numeric( $value ) && floor( (float) $value ) === (float) $value && (float) $value >= 1;
    }

    /**
     * Whitelists and coerces incoming settings.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate( array $input ): array {
        return self::apply_schema( $input );
    }

    /**
     * Whitelists and coerces stored settings on the way out, with validate()'s rules.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function sanitize_output( array $stored ): array {
        return self::apply_schema( $stored );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function apply_schema( array $data ): array {
        $clean = [];

        foreach ( self::schema() as $group => $fields ) {
            if ( ! isset( $data[ $group ] ) || ! is_array( $data[ $group ] ) ) {
                continue;
            }

            foreach ( $fields as $field => $type ) {
                if ( ! array_key_exists( $field, $data[ $group ] ) ) {
                    continue;
                }

                $value = self::coerce( $data[ $group ][ $field ], $type );

                // An empty label is never kept: on read the translated default stands, on write the stored label does.
                if ( 'points_label' === $type && '' === $value ) {
                    continue;
                }

                $clean[ $group ][ $field ] = $value;
            }
        }

        return $clean;
    }

    private static function coerce( $value, string $type ) {
        switch ( $type ) {
            case 'bool':
                return rest_sanitize_boolean( $value ); // A plain cast would store the string "false" as true.

            case 'int':
                return (int) $value;

            case 'non_negative_int':
                return max( 0, (int) $value );

            // null is no limit. is_valid() refuses anything else that isn't a whole number from 1 on write.
            case 'limit':
                return self::is_limit( $value ) ? (int) $value : null;

            case 'points_label':
                return mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, self::POINTS_LABEL_MAX_LENGTH );

            case 'slug_list':
                return array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );

            case 'text':
            default:
                return sanitize_text_field( (string) $value );
        }
    }
}
