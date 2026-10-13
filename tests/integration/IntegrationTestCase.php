<?php
namespace YayLoyalty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;
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

    /** @var array<string, mixed> Options snapshot_option() saved, restored in tearDown(); null means absent. */
    private $option_snapshots = [];

    protected function setUp(): void {
        parent::setUp();

        $this->truncate_tables();

        // The campaign list is object-cached; a truncated table would still be served from the previous test.
        ( new CampaignRepository() )->flush_cache();
    }

    protected function tearDown(): void {
        foreach ( $this->option_snapshots as $name => $value ) {
            if ( null === $value ) {
                delete_option( $name );
            } else {
                update_option( $name, $value );
            }
        }

        $this->option_snapshots = [];

        parent::tearDown();
    }

    /** Restores $name after the test. Options are not moved by the scratch prefix, so a test that writes one must. */
    protected function snapshot_option( string $name ): void {
        if ( ! array_key_exists( $name, $this->option_snapshots ) ) {
            $this->option_snapshots[ $name ] = get_option( $name, null );
        }
    }

    protected function ledger_service(): LedgerService {
        return new LedgerService( new LedgerRepository(), new CustomerRepository(), new CampaignRepository() );
    }

    /** A purchase EARN draft for one order, keyed as EarningService::award() keys it, written at $created_at ('' for now). */
    protected function earn_draft( int $user_id, int $order_id, int $points, string $created_at = '', ?int $campaign_id = null ): LedgerEntry {
        return new LedgerEntry(
            null,
            $user_id,
            $points,
            0,
            LedgerType::EARN,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            $order_id,
            $campaign_id,
            null,
            $order_id,
            null,
            null,
            '' === $created_at ? Dates::now() : $created_at,
            IdempotencyKey::compose( LedgerType::EARN, $user_id, ReferenceType::ORDER, $order_id, CampaignTrigger::PURCHASE )
        );
    }

    protected function insert_campaign( string $name, string $trigger, string $status, int $priority ): int {
        $now = gmdate( 'Y-m-d H:i:s' );

        return ( new CampaignRepository() )->insert(
            new Campaign( null, $name, sanitize_title( $name ), $trigger, $status, $priority, Campaign::default_config( $trigger ), [], null, null, false, null, $now, $now )
        );
    }

    /** @return array<string, mixed> The customer's cached aggregates. */
    protected function customer_row( int $user_id ): array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB -- tests read the table directly, past any cache; the table name is escaped.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( \YayLoyalty\Utils\Table::name( 'customers' ) ) . '` WHERE user_id = %d',
                $user_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : [];
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

        return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB -- tests read the table directly, past any cache; the table name is escaped.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( \YayLoyalty\Utils\Table::name( 'ledger' ) ) . '` WHERE user_id = %d ORDER BY id ASC',
                $user_id
            ),
            ARRAY_A
        );
    }

    /** @param array<string, mixed> $body */
    protected function send( string $method, string $route, array $body = [] ): \WP_REST_Response {
        $request = new \WP_REST_Request( $method, $route );

        if ( [] !== $body ) {
            $request->set_header( 'Content-Type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }

        return rest_do_request( $request );
    }

    protected function act_as_admin(): void {
        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $this->assertNotEmpty( $admins, 'The site needs one administrator to act as the caller.' );

        wp_set_current_user( (int) $admins[0] );
    }

    private function truncate_tables(): void {
        global $wpdb;

        foreach ( yayloyalty_test_tables() as $table ) {
            $name = esc_sql( $table );

            // TRUNCATE rebuilds the table even when empty; with every site table mirrored, that dominated the run.
            if ( null !== $wpdb->get_var( "SELECT 1 FROM `{$name}` LIMIT 1" ) ) { // phpcs:ignore WordPress.DB -- a scratch table; the name is escaped.
                $wpdb->query( "TRUNCATE TABLE `{$name}`" ); // phpcs:ignore WordPress.DB -- a scratch table; the name is escaped.
            }
        }
    }
}
