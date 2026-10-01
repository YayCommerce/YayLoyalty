<?php
namespace YayLoyalty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;

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

        // The campaign list is object-cached; a truncated table would still be served from the previous test.
        ( new CampaignRepository() )->flush_cache();
    }

    /**
     * Signs in the site's first administrator and registers the REST routes; returns the admin's id.
     * rest_get_server() fires rest_api_init, which is what registers the plugin's routes.
     */
    protected function act_as_admin(): int {
        $admin_id = $this->first_admin_id();
        wp_set_current_user( $admin_id );
        rest_get_server();

        return $admin_id;
    }

    /** The site's first administrator; the test is skipped on a site without one. */
    protected function first_admin_id(): int {
        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );

        if ( empty( $admins ) ) {
            $this->markTestSkipped( 'The site has no administrator to act as.' );
        }

        return (int) $admins[0];
    }

    protected function ledger_service(): LedgerService {
        return new LedgerService( new LedgerRepository(), new CustomerRepository() );
    }

    /** A purchase EARN draft for one order, keyed as EarningService::award() keys it. */
    protected function earn_draft( int $user_id, int $order_id, int $points ): LedgerEntry {
        return new LedgerEntry(
            null,
            $user_id,
            $points,
            0,
            LedgerType::EARN,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            $order_id,
            null,
            null,
            $order_id,
            null,
            null,
            Dates::now(),
            IdempotencyKey::compose( LedgerType::EARN, $user_id, ReferenceType::ORDER, $order_id, CampaignTrigger::PURCHASE )
        );
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

    /** Sets a customer's cached balance directly, creating the row if needed. */
    protected function set_balance( int $user_id, int $balance ): void {
        global $wpdb;

        ( new CustomerRepository() )->ensure_row( $user_id );
        $wpdb->update( \YayLoyalty\Utils\Table::name( 'customers' ), [ 'balance' => $balance ], [ 'user_id' => $user_id ], [ '%d' ], [ '%d' ] ); // phpcs:ignore WordPress.DB
    }

    /**
     * Signed points of one customer's ledger rows, oldest first.
     *
     * @return int[]
     */
    protected function ledger_points( int $user_id ): array {
        return array_map( 'intval', array_column( $this->ledger_rows( $user_id ), 'points' ) );
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
            $name = esc_sql( $table );

            // TRUNCATE rebuilds the table even when empty; with every site table mirrored, that dominated the run.
            if ( null !== $wpdb->get_var( "SELECT 1 FROM `{$name}` LIMIT 1" ) ) { // phpcs:ignore WordPress.DB
                $wpdb->query( "TRUNCATE TABLE `{$name}`" ); // phpcs:ignore WordPress.DB
            }
        }
    }
}
