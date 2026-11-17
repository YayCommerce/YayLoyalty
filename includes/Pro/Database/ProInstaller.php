<?php
namespace YayLoyalty\Pro\Database;

use YayLoyalty\Database\Installer;
use YayLoyalty\Database\Schema\AbstractTableSchema;

defined( 'ABSPATH' ) || exit;

/** Installs Pro's own tables under Pro's own version stamp. */
final class ProInstaller {

    public const OPTION_DB_VERSION = 'yayloyalty_pro_db_version';

    /** Set while a failed install waits to be retried, so a host that can't create the table isn't re-installed on every request. */
    private const RETRY_TRANSIENT = 'yayloyalty_pro_install_retry';

    /** Whether this request already ran Installer::install() with Pro's schemas attached. */
    private static $installed_this_request = false;

    /** Hooked on plugins_loaded. */
    public static function register(): void {
        add_filter( 'yayloyalty_table_schemas', [ self::class, 'add_schemas' ] );
        add_action( 'plugins_loaded', [ self::class, 'maybe_install' ], 20 );
        add_action( 'yayloyalty_run_migrations', [ self::class, 'note_install' ] );
    }

    /** Called inside Installer::install(), which runs with this class's schema filter attached. */
    public static function note_install(): void {
        self::$installed_this_request = true;
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
        if ( get_option( self::OPTION_DB_VERSION ) === YAYLOYALTY_VERSION || get_transient( self::RETRY_TRANSIENT ) ) {
            return;
        }

        // Lite's maybe_upgrade() may have just installed with Pro's schemas; installing again would repeat it.
        $ran = self::$installed_this_request
            || Installer::install_locked( static fn(): bool => get_option( self::OPTION_DB_VERSION ) !== YAYLOYALTY_VERSION );

        if ( ! $ran ) {
            return;
        }

        // Stamped only once the table is there; a failed install is retried after a pause, not on every request.
        if ( ( new ReferralsTable() )->exists() ) {
            update_option( self::OPTION_DB_VERSION, YAYLOYALTY_VERSION );
        } else {
            set_transient( self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
        }
    }
}
