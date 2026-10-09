<?php
namespace YayLoyalty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Redemption\CommitWriter;
use YayLoyalty\Engine\Redemption\Quoter;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
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

    /** A slider REDEEM draft for one order, keyed as CommitWriter keys it. */
    protected function redeem_draft( int $user_id, int $order_id, int $points ): LedgerEntry {
        return new LedgerEntry(
            null,
            $user_id,
            -$points,
            0,
            LedgerType::REDEEM,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            $order_id,
            null,
            null,
            $order_id,
            null,
            null,
            Dates::now(),
            IdempotencyKey::compose( LedgerType::REDEEM, $user_id, ReferenceType::ORDER, $order_id, RewardType::FLEXIBLE_DISCOUNT )
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

    protected function commit_writer(): CommitWriter {
        $customers = new CustomerRepository();

        return new CommitWriter(
            $this->ledger_service(),
            new RedemptionRepository(),
            $customers,
            new ContextFactory( $customers ),
            new Quoter( new RewardEngine( Registry::with_lite_handlers() ), [] )
        );
    }

    /** An order with one line and our coupon line, as checkout leaves it. */
    protected function order_with_points_discount( int $user_id, float $subtotal, float $discount ): \WC_Order {
        $order = wc_create_order( [ 'customer_id' => $user_id ] );

        $line = new \WC_Order_Item_Product();
        $line->set_name( 'Thing' );
        $line->set_quantity( 1 );
        $line->set_subtotal( $subtotal );
        $line->set_total( $subtotal - $discount );
        $order->add_item( $line );

        $coupon = new \WC_Order_Item_Coupon();
        $coupon->set_code( 'yayloyalty-redeem' );
        $coupon->set_discount( $discount );
        $order->add_item( $coupon );

        $order->save();

        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    protected function redemption_rows( int $user_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( ( new RedemptionRepository() )->table() ) . '` WHERE user_id = %d ORDER BY id ASC', $user_id ),
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
