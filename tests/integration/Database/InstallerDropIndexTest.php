<?php
namespace YayLoyalty\Tests\Integration\Database;

use YayLoyalty\Database\Installer;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Table;

/** The upgrade step that drops a superseded index: drops it once, then is a no-op. */
final class InstallerDropIndexTest extends IntegrationTestCase {

    private const INDEX = 'yl_test_superseded';

    public function test_drops_a_present_index_and_ignores_an_absent_one(): void {
        global $wpdb;

        $table = Table::name( 'ledger' );
        $wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD INDEX `' . self::INDEX . '` (source)' ); // phpcs:ignore WordPress.DB

        $drop = new \ReflectionMethod( Installer::class, 'drop_index_if_exists' );
        $drop->setAccessible( true );

        $drop->invoke( null, 'ledger', self::INDEX );
        $drop->invoke( null, 'ledger', self::INDEX );

        $this->assertNull(
            $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM `' . esc_sql( $table ) . '` WHERE Key_name = %s', self::INDEX ) ) // phpcs:ignore WordPress.DB
        );
    }
}
