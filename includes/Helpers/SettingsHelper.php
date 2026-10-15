<?php
namespace YayLoyalty\Helpers;

use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Utils\ConfigSpec;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage: one option, validated on read as well as write, since
 * the stored blob is localised straight into the admin page and an older
 * version or another plugin may have written it.
 */
class SettingsHelper {

    public const OPTION_SETTINGS = 'yayloyalty_settings';
    public const OPTION_REVIEWED = 'yayloyalty_reviewed';

    /** Set once the setup wizard is finished or exited; absent means the wizard opens. */
    public const OPTION_SETUP_COMPLETED = 'yayloyalty_setup_completed';

    /** @var array{locale: string, saved: mixed, settings: array}|null The last get_settings() result and what it was built from. */
    private static $cache = null;

    /** Longest points label; the admin form's schema (general-settings.form.ts) mirrors it. */
    public const POINTS_LABEL_MAX_LENGTH = 30;

    /** Longest expiry window, in days or months, so no date passes year 9999. The zod schema mirrors it. */
    public const EXPIRY_WINDOW_MAX = 36500;

    /** Most days ahead an expiry reminder may go out. The zod schema mirrors it. */
    public const REMINDER_DAYS_MAX = 365;

    /** Bump when a migration changes the stored shape. */
    public const SCHEMA_VERSION = 2;

    public static function get_default_settings(): array {
        return [
            'enabled'               => true,
            // Role slugs that never earn, read by Engine\Earning\ProgramGate.
            'excluded_roles'        => [],
            'points_label_singular' => __( 'Point', 'yayloyalty' ),
            'points_label_plural'   => __( 'Points', 'yayloyalty' ),
            // null means no limit. Enforced by Pro's DailyEarningLimit.
            'max_points_per_day'    => null,
            'emails'                => [
                // Weekly per-customer cap on loyalty emails. null means no cap. Engine\Emails\EmailThrottle enforces it.
                'throttle_per_week' => 3,
            ],
            // Read through Engine\Expiry\ExpiryPolicy. mode and spend_order are kept in Lite, which runs inactivity only.
            'expiry'                => [
                'enabled'         => false,
                'mode'            => ExpiryMode::INACTIVITY,
                'inactivity_days' => 365,
                'batch_months'    => 12,
                'spend_order'     => ExpiryPolicy::SPEND_OLDEST,
                'on_shorten'      => ExpiryPolicy::ON_SHORTEN_KEEP,
                // Days before expiry the Points expiring soon email goes out. Engine\Expiry\ExpiryReminders sends it.
                'reminder_days'   => 30,
            ],
        ];
    }

    /**
     * Type map: a string is a root field's type, an array is a group's fields. Anything absent is dropped in both directions.
     *
     * @return array<string, string|array<string, string>>
     */
    private static function schema(): array {
        return [
            'enabled'               => 'bool',
            'excluded_roles'        => 'slug_list',
            'points_label_singular' => 'points_label',
            'points_label_plural'   => 'points_label',
            'max_points_per_day'    => 'limit',
            'emails'                => [
                'throttle_per_week' => 'limit',
            ],
            'expiry'                => [
                'enabled'         => 'bool',
                'mode'            => 'expiry_mode',
                'inactivity_days' => 'expiry_window',
                'batch_months'    => 'expiry_window',
                'spend_order'     => 'spend_order',
                'on_shorten'      => 'on_shorten',
                'reminder_days'   => 'reminder_days',
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

        $merged = self::overlay( self::get_default_settings(), self::sanitize_output( $saved ) );

        $merged['schema_version'] = isset( $saved['schema_version'] )
            ? (int) $saved['schema_version']
            : self::SCHEMA_VERSION;

        return $merged;
    }

    /** The merchant's point label for $points: singular at exactly 1, plural otherwise. */
    public static function points_label( int $points ): string {
        $settings = self::get_settings();

        return 1 === $points ? $settings['points_label_singular'] : $settings['points_label_plural'];
    }

    /** The merchant's plural label, for a sentence with no count: "View your Points". */
    public static function points_label_plural(): string {
        return self::get_settings()['points_label_plural'];
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
     * keep following the site language.
     */
    public static function update_settings( array $settings ): array {
        $saved  = get_option( self::OPTION_SETTINGS, [] );
        $stored = self::sanitize_output( is_array( $saved ) ? $saved : [] );

        $stored = self::overlay( $stored, self::validate( $settings ) );

        $stored['schema_version'] = self::SCHEMA_VERSION;

        update_option( self::OPTION_SETTINGS, $stored );

        return self::get_settings();
    }

    /**
     * $clean over $base: a group's fields are replaced one by one, and any other value whole. Not recursive,
     * so a stored list replaces the default list instead of splicing into it by index, and a list can be emptied.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $clean Output of apply_schema().
     * @return array<string, mixed>
     */
    private static function overlay( array $base, array $clean ): array {
        $schema = self::schema();

        foreach ( $clean as $key => $value ) {
            $base[ $key ] = is_array( $schema[ $key ] ) ? array_replace( $base[ $key ] ?? [], $value ) : $value;
        }

        return $base;
    }

    /**
     * Whether every `limit` field in $input is null or a whole number from 1, every `expiry_window` is a
     * whole number from 1 to EXPIRY_WINDOW_MAX, every `reminder_days` a whole number from 1 to REMINDER_DAYS_MAX,
     * and every mode, spend order and on_shorten choice is known. The controllers answer 400 on
     * false; coerce() alone would read a 0 or a fraction as "no limit" without saying so. Batch expiry
     * is one-way: once batch is stored, switching back to inactivity is refused. The reminder must go
     * out fewer days ahead than the window the saved expiry settings run on.
     *
     * @param array<string, mixed> $input
     */
    public static function is_valid( array $input ): bool {
        $saved       = get_option( self::OPTION_SETTINGS, [] );
        $stored_mode = is_array( $saved ) ? ( $saved['expiry']['mode'] ?? null ) : null;
        $next_mode   = is_array( $input['expiry'] ?? null ) ? ( $input['expiry']['mode'] ?? null ) : null;

        if ( ExpiryMode::BATCH === $stored_mode && null !== $next_mode && ExpiryMode::BATCH !== $next_mode ) {
            return false;
        }

        if ( ! self::fields_valid( $input, self::schema() ) ) {
            return false;
        }

        if ( ! is_array( $input['expiry'] ?? null ) ) {
            return true;
        }

        $policy = new ExpiryPolicy( array_merge( self::get_settings()['expiry'], $input['expiry'] ) );

        return $policy->reminder_days() < $policy->window_days();
    }

    /**
     * @param array<string, mixed>                        $input
     * @param array<string, string|array<string, string>> $schema
     */
    private static function fields_valid( array $input, array $schema ): bool {
        foreach ( $schema as $key => $type ) {
            if ( ! array_key_exists( $key, $input ) ) {
                continue;
            }

            if ( is_array( $type ) ) {
                if ( is_array( $input[ $key ] ) && ! self::fields_valid( $input[ $key ], $type ) ) {
                    return false;
                }
            } elseif ( ! self::is_valid_value( $input[ $key ], $type ) ) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $value */
    private static function is_valid_value( $value, string $type ): bool {
        switch ( $type ) {
            case 'limit':
                return null === $value || ConfigSpec::is_limit( $value );

            case 'expiry_window':
                return ConfigSpec::is_limit( $value ) && (float) $value <= self::EXPIRY_WINDOW_MAX;

            case 'reminder_days':
                return ConfigSpec::is_limit( $value ) && (float) $value <= self::REMINDER_DAYS_MAX;

            case 'expiry_mode':
                return ExpiryMode::is_known( $value );

            case 'spend_order':
                return in_array( $value, [ ExpiryPolicy::SPEND_OLDEST, ExpiryPolicy::SPEND_NEWEST ], true );

            case 'on_shorten':
                return in_array( $value, [ ExpiryPolicy::ON_SHORTEN_KEEP, ExpiryPolicy::ON_SHORTEN_APPLY ], true );

            default:
                return true;
        }
    }

    /**
     * Whitelists and coerces incoming settings.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate( array $input ): array {
        return self::apply_schema( $input, self::schema() );
    }

    /**
     * Whitelists and coerces stored settings on the way out, with validate()'s rules.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function sanitize_output( array $stored ): array {
        return self::apply_schema( $stored, self::schema() );
    }

    /**
     * @param array<string, mixed>                        $data
     * @param array<string, string|array<string, string>> $schema
     * @return array<string, mixed>
     */
    private static function apply_schema( array $data, array $schema ): array {
        $clean = [];

        foreach ( $schema as $key => $type ) {
            if ( ! array_key_exists( $key, $data ) ) {
                continue;
            }

            if ( is_array( $type ) ) {
                if ( is_array( $data[ $key ] ) ) {
                    $clean[ $key ] = self::apply_schema( $data[ $key ], $type );
                }
                continue;
            }

            $value = self::coerce( $data[ $key ], $type );

            // An empty label, or an unreadable value of any type but `limit` (where null is "no limit"), is never kept:
            // on read the default stands, on write the stored value does.
            if ( ( 'points_label' === $type && '' === $value ) || ( null === $value && 'limit' !== $type ) ) {
                continue;
            }

            $clean[ $key ] = $value;
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
                return ConfigSpec::is_limit( $value ) ? (int) $value : null;

            case 'expiry_window':
            case 'reminder_days':
                return self::is_valid_value( $value, $type ) ? (int) $value : null;

            case 'expiry_mode':
            case 'spend_order':
            case 'on_shorten':
                return self::is_valid_value( $value, $type ) ? $value : null;

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
