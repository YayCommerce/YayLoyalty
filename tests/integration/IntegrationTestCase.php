<?php
namespace YayLoyalty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;

/**
 * A test with a database under it. Every table is emptied before each test,
 * rather than each test running inside a rolled-back transaction: the code
 * under test opens its own transactions, and an outer one would quietly turn
 * every COMMIT into a savepoint release and hide whether it truly committed.
 */
abstract class IntegrationTestCase extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        $this->truncate_tables();
    }

    protected function ledger_service(): LedgerService {
        return new LedgerService( new LedgerRepository(), new CustomerRepository() );
    }

    /**
     * @return array<string, mixed> The customer's cached aggregates.
     */
    protected function customer_row( int $user_id ): array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( \YayLoyalty\Utils\Table::name( 'customers' ) ) . '` WHERE user_id = %d',
                $user_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : [];
    }

    /** Ledger rows for one customer, oldest first. */
    protected function ledger_rows( int $user_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( \YayLoyalty\Utils\Table::name( 'ledger' ) ) . '` WHERE user_id = %d ORDER BY id ASC',
                $user_id
            ),
            ARRAY_A
        );
    }

    private function truncate_tables(): void {
        global $wpdb;

        foreach ( yayloyalty_test_tables() as $table ) {
            $wpdb->query( 'TRUNCATE TABLE `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB
        }
    }
}
