<?php
namespace YayLoyalty\Pro\Database;

use YayLoyalty\Database\Installer;
use YayLoyalty\Database\Schema\AbstractTableSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Installs Pro's own tables. Lite's install runs on activation before Pro boots, and skips while
 * `yayloyalty_db_version` is current, so a fresh Pro activation or a Lite → Pro switch at the same
 * version would never create them. Pro keeps its own stamp and runs the idempotent install once.
 */
final class ProInstaller {

    public const OPTION_DB_VERSION = 'yayloyalty_pro_db_version';

    /** Hooked on plugins_loaded. */
    public static function register(): void {
        add_filter( 'yayloyalty_table_schemas', [ self::class, 'add_schemas' ] );
        add_action( 'plugins_loaded', [ self::class, 'maybe_install' ], 20 );
    }

    /**
     * @param AbstractTableSchema[] $schemas
     * @return AbstractTableSchema[]
     */
    public static function add_schemas( array $schemas ): array {
        $schemas[] = new ReferralsTable();

        return $schemas;
    }

    /** Runs after Installer::maybe_upgrade(), once per version, so a Pro schema change also reaches a site whose Lite stamp is current. */
    public static function maybe_install(): void {
        if ( get_option( self::OPTION_DB_VERSION ) === YAYLOYALTY_VERSION ) {
            return;
        }

        $installed = Installer::install_locked( static fn(): bool => get_option( self::OPTION_DB_VERSION ) !== YAYLOYALTY_VERSION );

        // Stamped only once the table is there, so a failed install retries on the next request.
        if ( $installed && self::tables_exist() ) {
            update_option( self::OPTION_DB_VERSION, YAYLOYALTY_VERSION );
        }
    }

    private static function tables_exist(): bool {
        global $wpdb;

        $table = ( new ReferralsTable() )->table_name();
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a schema probe; WordPress has no API for it.

        return $table === $found;
    }
}
