<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage — single option, validated on the way in and on the way out.
 *
 * Both directions matter. update_settings() previously wrote whatever arrived,
 * and get_settings() still merged unvalidated stored data straight into the
 * page via LocalizeHelper. Sanitising only the write path leaves anything
 * already in the option — or written by an older version, or by another plugin
 * touching the same option name — echoed into the admin screen.
 *
 * Every stored blob carries a schema_version so the shape can evolve without a
 * table migration. A blob without one is version 0 and gets stamped on its next
 * save, so no backfill is needed.
 */
class SettingsHelper {

    public const OPTION_SETTINGS = 'yayloyalty_settings';
    public const OPTION_REVIEWED = 'yayloyalty_reviewed';

    /** Current settings shape. Bump when a migration changes the structure. */
    public const SCHEMA_VERSION = 1;

    public static function get_default_settings(): array {
        return [
            'general'  => [
                'enabled'               => true,
                'points_label_singular' => __( 'Point', 'yayloyalty' ),
                'points_label_plural'   => __( 'Points', 'yayloyalty' ),
            ],
            'advanced' => [
                // Largest manual adjustment a non-administrator may make in
                // one call. 0 = unlimited. Read via Capabilities::ceiling().
                'adjustment_ceiling' => 0,
            ],
        ];
    }

    /**
     * Type map used by both validate() and sanitize_output().
     *
     * Keyed by group, then field. Anything absent here is not a setting and is
     * dropped in both directions.
     *
     * @return array<string, array<string, string>>
     */
    private static function schema(): array {
        return [
            'general'  => [
                'enabled'               => 'bool',
                'points_label_singular' => 'text',
                'points_label_plural'   => 'text',
            ],
            'advanced' => [
                'adjustment_ceiling' => 'int',
            ],
        ];
    }

    /**
     * Stored settings merged over defaults, sanitised for output.
     */
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

    /**
     * Validate, merge over defaults, stamp the version, save.
     *
     * Unknown keys are dropped rather than persisted.
     */
    public static function update_settings( array $settings ): array {
        $clean = self::validate( $settings );

        $to_store                   = array_replace_recursive( self::get_default_settings(), $clean );
        $to_store['schema_version'] = self::SCHEMA_VERSION;

        update_option( self::OPTION_SETTINGS, $to_store );

        return self::get_settings();
    }

    /**
     * Whitelist and coerce incoming settings.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validate( array $input ): array {
        return self::apply_schema( $input );
    }

    /**
     * Whitelist and coerce stored settings on the way out.
     *
     * Same rules as validate(); named separately because the intent differs and
     * the read path is easy to forget.
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

            case 'text':
            default:
                return sanitize_text_field( (string) $value );
        }
    }
}
