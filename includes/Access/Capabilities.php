<?php
namespace YayLoyalty\Access;

use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's capabilities: names, role defaults, install/migrate/uninstall,
 * and the bootstrap payload the admin SPA reads.
 *
 * THE ONE RULE. Capabilities are checked at the HTTP boundary only — the menu,
 * the page render callback and REST permission callbacks. Domain services
 * (LedgerService, the earning engine, expiry, tools) take an actor id and are
 * user-unaware: a cap check inside LedgerService::record() would break every
 * cron, order-hook and WP-CLI path the moment there is no current user. The
 * SPA hides and disables; it is never a gate.
 *
 * Caps live on WP_Role and nowhere else — no mirror option, no custom table.
 * No custom role is created; caps are granted to roles that already exist.
 * Primitive caps, not meta caps: there is no map_meta_cap filter.
 *
 * All seven exist in Free. Only the matrix editor and the adjustment ceiling
 * are Pro; retrofitting real caps later would mean revisiting every route.
 */
final class Capabilities {

    public const VIEW             = 'yayloyalty_view';
    public const MANAGE_POINTS    = 'yayloyalty_manage_points';
    public const MANAGE_TIERS     = 'yayloyalty_manage_tiers';
    public const MANAGE_CAMPAIGNS = 'yayloyalty_manage_campaigns';
    public const MANAGE_REWARDS   = 'yayloyalty_manage_rewards';
    public const MANAGE_SETTINGS  = 'yayloyalty_manage_settings';
    public const RUN_TOOLS        = 'yayloyalty_run_tools';

    /** Bump when a capability is ADDED, then teach migrate() who inherits it. */
    public const VERSION        = 1;
    public const VERSION_OPTION = 'yayloyalty_caps_version';

    /**
     * All capabilities, in display order.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::VIEW,
            self::MANAGE_POINTS,
            self::MANAGE_TIERS,
            self::MANAGE_CAMPAIGNS,
            self::MANAGE_REWARDS,
            self::MANAGE_SETTINGS,
            self::RUN_TOOLS,
        ];
    }

    /**
     * Role slug => capabilities granted on first install.
     *
     * Shop manager gets five, not seven: not settings (earning rates and the
     * licence) and not tools (contains "reset program", which is irreversible).
     *
     * Campaigns and rewards are separate caps so a merchant can hand a
     * marketer the earning rules without the spend side, or vice versa.
     *
     * @return array<string, string[]>
     */
    public static function defaults(): array {
        return [
            'administrator' => self::all(),
            'shop_manager'  => [
                self::VIEW,
                self::MANAGE_POINTS,
                self::MANAGE_TIERS,
                self::MANAGE_CAMPAIGNS,
                self::MANAGE_REWARDS,
            ],
        ];
    }

    /**
     * Grant the defaults and stamp the version. Idempotent.
     *
     * Called from Installer::install() — so it runs on activation, on every
     * plugin upgrade and for blogs added to a network — rather than from the
     * activation hook alone, because shop_manager does not exist until
     * WooCommerce has been activated at least once.
     */
    public static function install(): void {
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

    /**
     * Bring roles up to the current capability set.
     *
     * Runs on plugins_loaded; one option read on the common path. When a
     * release adds a capability, roles that already hold the related one must
     * receive it automatically — otherwise every merchant's custom roles
     * (Members, User Role Editor, agency-defined) silently lose access to a
     * feature they had. Iterate the real roles from wp_roles(), never a
     * hardcoded list.
     *
     * Version 0 is a site that has never run install(): grant the defaults.
     * Later versions add a block per bump, e.g. when 1.2 adds MANAGE_TIERS:
     *
     *   if ( $stored < 2 ) {
     *       foreach ( wp_roles()->role_objects as $role ) {
     *           if ( $role->has_cap( self::MANAGE_CAMPAIGNS ) ) {
     *               $role->add_cap( self::MANAGE_TIERS );
     *           }
     *       }
     *   }
     */
    public static function migrate(): void {
        $stored = (int) get_option( self::VERSION_OPTION, 0 );

        if ( $stored >= self::VERSION ) {
            return;
        }

        // install() stamps VERSION, so a fresh site is done here. Later bumps
        // add their inheritance blocks above this line and stamp at the end.
        self::install();
    }

    /**
     * Remove every capability from every role and delete the version option.
     *
     * Only for uninstall-with-delete-data. Deactivation removes nothing: a
     * merchant deactivating to debug a conflict must not come back to a
     * locked-out shop manager.
     */
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
     * Administrators bypass the adjustment ceiling and are the only ones who
     * may edit the permission matrix (Pro) — a shop manager must not be able
     * to grant themselves manage_settings.
     */
    public static function is_administrator(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Largest absolute point value a non-administrator may adjust in one call.
     *
     * A policy value, not a capability: yayloyalty_settings['advanced']
     * ['adjustment_ceiling'], 0 = unlimited. Free defaults to 0; Pro's
     * Advanced screen exposes it.
     */
    public static function ceiling(): int {
        $settings = SettingsHelper::get_settings();

        return isset( $settings['advanced']['adjustment_ceiling'] )
            ? max( 0, (int) $settings['advanced']['adjustment_ceiling'] )
            : 0;
    }

    /**
     * Bootstrap payload for the admin SPA, merged into window.yayLoyaltyAdmin.
     *
     * Known at page render, so there is no /permissions endpoint: a round trip
     * to learn them is a wasted request and a flash of wrong UI.
     *
     * @return array{capabilities: string[], ceiling: int, isAdministrator: bool}
     */
    public static function payload(): array {
        return [
            'capabilities'    => array_values( array_filter( self::all(), [ self::class, 'can' ] ) ),
            'ceiling'         => self::ceiling(),
            'isAdministrator' => self::is_administrator(),
        ];
    }
}
