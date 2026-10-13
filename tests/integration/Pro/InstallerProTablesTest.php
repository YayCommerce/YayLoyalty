<?php
namespace YayLoyalty\Tests\Integration\Pro;

use YayLoyalty\Database\Installer;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Table;
use YayLoyalty\YayLoyalty;

/**
 * Pro registers its tables through yayloyalty_table_schemas, which is not attached during activation, so
 * the version alone cannot tell whether they were installed. The installer also tracks installed slugs:
 * a registered slug missing from yayloyalty_db_tables re-installs at an unchanged version (the Lite to
 * Pro swap), and a matching list does not. Options are not moved by the scratch prefix, so the real
 * site's values are restored after each test.
 */
final class InstallerProTablesTest extends IntegrationTestCase {

    private const OPTIONS = [ Installer::OPTION_DB_VERSION, Installer::OPTION_DB_TABLES ];

    /** @var array<string, mixed> Option values before the test; null means absent. */
    private array $saved_options = [];

    protected function setUp(): void {
        parent::setUp();

        if ( ! YayLoyalty::is_pro() ) {
            $this->markTestSkipped( 'The Pro tables exist only in the Pro build.' );
        }

        foreach ( self::OPTIONS as $option ) {
            $this->saved_options[ $option ] = get_option( $option, null );
        }
    }

    protected function tearDown(): void {
        foreach ( $this->saved_options as $option => $value ) {
            if ( null === $value ) {
                delete_option( $option );
            } else {
                update_option( $option, $value );
            }
        }

        // Later tests truncate every registered table, so each must exist again.
        foreach ( Installer::schemas() as $schema ) {
            if ( ! $this->table_exists( $schema->slug() ) ) {
                $GLOBALS['wpdb']->query( $schema->definition() ); // phpcs:ignore WordPress.DB -- the plugin's own CREATE TABLE statement.
            }
        }

        parent::tearDown();
    }

    public function test_install_creates_both_pro_tables_with_their_indexes(): void {
        $this->drop_scratch_table( 'lots' );
        $this->drop_scratch_table( 'lot_allocations' );

        Installer::install();

        $problems = Installer::verify();
        $this->assertTrue( $this->table_exists( 'lots' ) );
        $this->assertTrue( $this->table_exists( 'lot_allocations' ) );
        $this->assertArrayNotHasKey( Table::name( 'lots' ), $problems );
        $this->assertArrayNotHasKey( Table::name( 'lot_allocations' ), $problems );
        $this->assertSame( [], array_diff( [ 'lots', 'lot_allocations' ], (array) get_option( Installer::OPTION_DB_TABLES ) ) );
    }

    public function test_maybe_upgrade_installs_a_registered_table_missing_at_the_same_version(): void {
        update_option( Installer::OPTION_DB_VERSION, YAYLOYALTY_VERSION );
        update_option( Installer::OPTION_DB_TABLES, [ 'customers', 'ledger', 'campaigns', 'rewards', 'customer_meta' ] );
        $this->drop_scratch_table( 'lots' );

        Installer::maybe_upgrade();

        $this->assertTrue( $this->table_exists( 'lots' ) );
        $this->assertContains( 'lots', (array) get_option( Installer::OPTION_DB_TABLES ) );
    }

    public function test_maybe_upgrade_does_nothing_when_version_and_slugs_match(): void {
        Installer::install();
        $this->drop_scratch_table( 'lots' ); // Recorded as installed, so maybe_upgrade must not notice.

        Installer::maybe_upgrade();

        $this->assertFalse( $this->table_exists( 'lots' ) );
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
