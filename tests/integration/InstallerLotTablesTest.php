<?php
namespace YayLoyalty\Tests\Integration;

use YayLoyalty\Database\Installer;
use YayLoyalty\Utils\Table;

/**
 * The lot tables are core: install() creates them with nothing hooked on yayloyalty_table_schemas, as on a
 * Lite site. The real site's version stamp is restored (snapshot_option()).
 */
final class InstallerLotTablesTest extends IntegrationTestCase {

    /** @var \WP_Hook|null What was hooked on yayloyalty_table_schemas before the test. */
    private $saved_schema_hook = null;

    protected function setUp(): void {
        parent::setUp();

        $this->snapshot_option( Installer::OPTION_DB_VERSION );
        $this->saved_schema_hook = $GLOBALS['wp_filter']['yayloyalty_table_schemas'] ?? null;
    }

    protected function tearDown(): void {
        if ( null !== $this->saved_schema_hook ) {
            $GLOBALS['wp_filter']['yayloyalty_table_schemas'] = $this->saved_schema_hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restoring the hook this test detached.
        }

        // Later tests truncate every registered table, so each must exist again.
        foreach ( Installer::schemas() as $schema ) {
            if ( ! $this->table_exists( $schema->slug() ) ) {
                $GLOBALS['wpdb']->query( $schema->definition() ); // phpcs:ignore WordPress.DB -- the plugin's own CREATE TABLE statement.
            }
        }

        parent::tearDown();
    }

    public function test_install_creates_both_lot_tables_without_any_schema_filter(): void {
        remove_all_filters( 'yayloyalty_table_schemas' );
        $this->drop_scratch_table( 'lots' );
        $this->drop_scratch_table( 'lot_allocations' );

        Installer::install();

        $this->assertTrue( $this->table_exists( 'lots' ) );
        $this->assertTrue( $this->table_exists( 'lot_allocations' ) );
        $this->assertSame( [], Installer::verify() );
    }

    private function table_exists( string $slug ): bool {
        global $wpdb;

        return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Table::name( $slug ) ) ) ); // phpcs:ignore WordPress.DB -- a schema check on a scratch table; the pattern is prepared.
    }

    private function drop_scratch_table( string $slug ): void {
        global $wpdb;

        $table = Table::name( $slug );

        // The prefix check is the only thing standing between this DROP and real data.
        $this->assertStringStartsWith( YAYLOYALTY_TEST_PREFIX, $table );
        $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB -- dropping a scratch table has no WordPress API; the name is escaped.
    }
}
