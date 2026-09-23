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
                /*
                 * TODO(F01-4): 'award_statuses' => [ 'processing', 'completed' ] as a
                 * slug_list, read only by Engine\Earning\Listeners\OrderListener. Paid is
                 * a set: a fully virtual order never passes through processing, and many
                 * physical stores stop there. The UI offers payment received (both),
                 * order completed (completed only) and a custom multi-select built from
                 * wc_get_order_statuses() at render time, so statuses another plugin
                 * registers appear without a release here. Also reserve 'hold_days' => 0
                 * for a holding period before pending is released — shape only, nothing
                 * reads it.
                 */
            ],
            'advanced' => [
                // 0 = unlimited. Read via Capabilities::ceiling().
                'adjustment_ceiling' => 0,
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
        ];
    }

    /** Stored settings merged over defaults, sanitised for output. */
    public static function get_settings(): array {
        $saved = get_option( self::OPTION_SETTINGS, [] );

        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        $merged = array_replace_recursive( self::get_default_settings(), self::sanitize_output( $saved ) );

        $merged['schema_version'] = isset( $saved['schema_version'] )
            ? (int) $saved['schema_version']
            : self::SCHEMA_VERSION;

        return $merged;
    }

    /** Validates, merges over defaults, stamps the version, saves. */
    public static function update_settings( array $settings ): array {
        $clean = self::validate( $settings );

        $to_store                   = array_replace_recursive( self::get_default_settings(), $clean );
        $to_store['schema_version'] = self::SCHEMA_VERSION;

        update_option( self::OPTION_SETTINGS, $to_store );

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
                return (bool) $value;

            case 'int':
                return (int) $value;

            case 'slug_list':
                return array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );

            case 'text':
            default:
                return sanitize_text_field( (string) $value );
        }
    }
}
