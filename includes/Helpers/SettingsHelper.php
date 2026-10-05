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

    /** Bump when a migration changes the stored shape. */
    public const SCHEMA_VERSION = 1;

    public static function get_default_settings(): array {
        return [
            'general'  => [
                'enabled'               => true,
                'points_label_singular' => __( 'Point', 'yayloyalty' ),
                'points_label_plural'   => __( 'Points', 'yayloyalty' ),
                // Role slugs that never earn; read by Engine\Earning\ProgramGate.
                'excluded_roles'        => [],
            ],
            'advanced' => [
                // 0 = unlimited. Read via Capabilities::ceiling().
                'adjustment_ceiling' => 0,
            ],
            'emails'   => [
                // Weekly per-customer cap on loyalty emails; 0 = unlimited. Read via Engine\Emails\EmailThrottle.
                'throttle_per_week' => 3,
            ],
        ];
    }

    /**
     * Type map by group then field; anything absent is dropped in both directions.
     *
     * @return array<string, array<string, string>>
     */
    private static function schema(): array {
        return [
            'general'  => [
                'enabled'               => 'bool',
                'points_label_singular' => 'text',
                'points_label_plural'   => 'text',
                'excluded_roles'        => 'slug_list',
            ],
            'advanced' => [
                'adjustment_ceiling' => 'int',
            ],
            'emails'   => [
                'throttle_per_week' => 'non_negative_int',
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
        $general = self::get_settings()['general'];

        return 1 === $points ? $general['points_label_singular'] : $general['points_label_plural'];
    }

    /** "50 Points": the amount in the site's number format with the merchant's label, in an order translators set. */
    public static function points_text( int $points ): string {
        /* translators: 1: number of points, 2: the store's points label, e.g. "Points". */
        return sprintf( _x( '%1$s %2$s', 'points amount', 'yayloyalty' ), number_format_i18n( $points ), self::points_label( $points ) );
    }

    /**
     * Saves only the fields sent, over what is stored; returns the merged settings.
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
     * Whitelists and coerces incoming settings.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate( array $input ): array {
        return self::apply_schema( $input );
    }

    /**
     * Whitelists and coerces stored settings on the way out. Same rules as validate(); named so the read path is not forgotten.
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

                $clean[ $group ][ $field ] = self::coerce( $data[ $group ][ $field ], $type );
            }
        }

        return $clean;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function coerce( $value, string $type ) {
        switch ( $type ) {
            case 'bool':
                return rest_sanitize_boolean( $value ); // a plain cast would store the string "false" as true

            case 'int':
                return (int) $value;

            case 'non_negative_int':
                return max( 0, (int) $value );

            case 'slug_list':
                return array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );

            case 'text':
            default:
                return sanitize_text_field( (string) $value );
        }
    }
}
