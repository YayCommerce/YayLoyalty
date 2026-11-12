<?php
namespace YayLoyalty\Access;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's capabilities, their role defaults and the SPA's bootstrap payload.
 * They are checked at the HTTP boundary only. Domain services take an actor id instead,
 * so cron and WP-CLI paths keep working. They are primitive caps, granted on existing roles only.
 */
final class Capabilities {

    public const VIEW             = 'yayloyalty_view';
    public const MANAGE_POINTS    = 'yayloyalty_manage_points';
    public const MANAGE_CAMPAIGNS = 'yayloyalty_manage_campaigns';
    public const MANAGE_REWARDS   = 'yayloyalty_manage_rewards';
    public const MANAGE_TIERS     = 'yayloyalty_manage_tiers';
    public const MANAGE_SETTINGS  = 'yayloyalty_manage_settings';
    public const RUN_TOOLS        = 'yayloyalty_run_tools';

    /** Bump when a capability is added or removed, then teach upgrade_roles() the change. */
    public const VERSION        = 3;
    public const VERSION_OPTION = 'yayloyalty_caps_version';

    /** @return string[] In display order. */
    public static function all(): array {
        return [
            self::VIEW,
            self::MANAGE_POINTS,
            self::MANAGE_CAMPAIGNS,
            self::MANAGE_REWARDS,
            self::MANAGE_TIERS,
            self::MANAGE_SETTINGS,
            self::RUN_TOOLS,
        ];
    }

    /**
     * Role slug => capabilities granted on first install. Shop manager gets
     * neither settings (the licence) nor tools ("reset program" is irreversible).
     *
     * @return array<string, string[]>
     */
    public static function defaults(): array {
        return [
            'administrator' => self::all(),
            'shop_manager'  => [
                self::VIEW,
                self::MANAGE_POINTS,
                self::MANAGE_CAMPAIGNS,
                self::MANAGE_REWARDS,
                self::MANAGE_TIERS,
            ],
        ];
    }

    /**
     * Upgrades roles from the stored version, grants the defaults, then stamps the version. Idempotent.
     * Every caller passes through here, Installer::install() included, so no path stamps past an upgrade.
     */
    public static function install(): void {
        self::upgrade_roles( (int) get_option( self::VERSION_OPTION, 0 ) );

        foreach ( self::defaults() as $role_slug => $caps ) {
            $role = get_role( $role_slug );

            if ( ! $role ) {
                continue;
            }

            foreach ( $caps as $cap ) {
                $role->add_cap( $cap );
            }
        }

        update_option( self::VERSION_OPTION, self::VERSION );
    }

    /** Brings roles up to the current capability set when the stored version is behind. Hooked on plugins_loaded. */
    public static function migrate(): void {
        if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
            return;
        }

        self::install();
    }

    /**
     * One block per VERSION bump, for changes beyond the defaults. A new capability must be
     * granted to every role holding the related one, iterating wp_roles(), or merchants'
     * custom roles silently lose access:
     *
     *   if ( $stored < 3 ) {
     *       foreach ( wp_roles()->role_objects as $role ) {
     *           if ( $role->has_cap( self::MANAGE_CAMPAIGNS ) ) {
     *               $role->add_cap( self::NEW_CAP );
     *           }
     *       }
     *   }
     */
    private static function upgrade_roles( int $stored ): void {
        // 2: tiers were cut from v1.0, and their capability with them.
        if ( $stored < 2 ) {
            foreach ( wp_roles()->role_objects as $role ) {
                $role->remove_cap( 'yayloyalty_manage_tiers' );
            }
        }

        // 3: tiers are back. Whoever runs campaigns runs tiers, custom roles included.
        if ( $stored < 3 ) {
            foreach ( wp_roles()->role_objects as $role ) {
                if ( $role->has_cap( self::MANAGE_CAMPAIGNS ) ) {
                    $role->add_cap( self::MANAGE_TIERS );
                }
            }
        }
    }

    /** Removes every capability from every role. Uninstall only: deactivation must not lock out a shop manager. */
    public static function uninstall(): void {
        foreach ( wp_roles()->role_objects as $role ) {
            foreach ( self::all() as $cap ) {
                $role->remove_cap( $cap );
            }
        }

        delete_option( self::VERSION_OPTION );
    }

    public static function can( string $cap ): bool {
        return current_user_can( $cap );
    }

    /**
     * Bootstrap payload for the SPA. Localised, not an endpoint: a round trip would flash the wrong UI.
     *
     * @return array{capabilities: string[]}
     */
    public static function payload(): array {
        return [
            'capabilities' => array_values( array_filter( self::all(), [ self::class, 'can' ] ) ),
        ];
    }
}
