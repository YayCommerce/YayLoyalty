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
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
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

    /** A slider REDEEM draft for one order, keyed as CommitWriter keys it, written at $created_at ('' for now). */
    protected function redeem_draft( int $user_id, int $order_id, int $points, string $created_at = '' ): LedgerEntry {
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
            '' === $created_at ? Dates::now() : $created_at,
            IdempotencyKey::compose( LedgerType::REDEEM, $user_id, ReferenceType::ORDER, $order_id, RewardType::FLEXIBLE_DISCOUNT )
        );
    }

    /** A REFUND_REDEEM draft returning $points of the redemption on $order_id, keyed on the order. */
    protected function refund_redeem_draft( int $user_id, int $order_id, int $points ): LedgerEntry {
        return new LedgerEntry(
            null,
            $user_id,
            $points,
            0,
            LedgerType::REFUND_REDEEM,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            $order_id,
            null,
            null,
            $order_id,
            null,
            null,
            Dates::now(),
            IdempotencyKey::compose( LedgerType::REFUND_REDEEM, $user_id, ReferenceType::ORDER, $order_id, RewardType::FLEXIBLE_DISCOUNT )
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

    /** The slider reward as a stored row, so the reversal can read its refund_policy. */
    protected function seeded_reward( array $config = [] ): Reward {
        $now     = Dates::now();
        $rewards = new RewardRepository();
        $id      = $rewards->insert( new Reward( null, 'Cart discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, Status::ACTIVE, 0, null, array_merge( Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ), $config ), [], null, null, true, null, null, $now, $now ) );

        return $rewards->find_reward( $id );
    }

    /** An order with one line and the slider's coupon line under $code, as checkout leaves it. */
    protected function order_with_points_discount( int $user_id, float $subtotal, float $discount, string $code ): \WC_Order {
        $order = wc_create_order( [ 'customer_id' => $user_id ] );

        $line = new \WC_Order_Item_Product();
        $line->set_name( 'Thing' );
        $line->set_quantity( 1 );
        $line->set_subtotal( $subtotal );
        $line->set_total( $subtotal - $discount );
        $order->add_item( $line );

        $coupon = new \WC_Order_Item_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount( $discount );
        $order->add_item( $coupon );

        $order->save();

        return $order;
    }

    /** A live Pro reward of $type as a stored row, so commit and reversal read it by id. */
    protected function pro_reward( string $type, int $cost, array $config ): Reward {
        $now     = Dates::now();
        $rewards = new RewardRepository();
        $id      = $rewards->insert( new Reward( null, $type . ' ' . $cost, $type . '-' . $cost, $type, Status::ACTIVE, 0, $cost, array_merge( [ 'currency' => 'USD' ], $config ), [], null, null, false, null, null, $now, $now ) );

        return $rewards->find_reward( $id );
    }

    /**
     * An order with one line and a coupon line per reward code, as checkout leaves it.
     *
     * @param array<string, float> $lines Coupon code => its discount.
     */
    protected function order_with_reward_lines( int $user_id, float $subtotal, array $lines ): \WC_Order {
        $order = wc_create_order( [ 'customer_id' => $user_id ] );

        $line = new \WC_Order_Item_Product();
        $line->set_name( 'Thing' );
        $line->set_quantity( 1 );
        $line->set_subtotal( $subtotal );
        $line->set_total( $subtotal - array_sum( $lines ) );
        $order->add_item( $line );

        foreach ( $lines as $code => $discount ) {
            $coupon = new \WC_Order_Item_Coupon();
            $coupon->set_code( $code );
            $coupon->set_discount( $discount );
            $order->add_item( $coupon );
        }

        $order->save();

        return $order;
    }

    /**
     * WooCommerce's cart holding one untaxed product at $price, and nothing else: what the cart-time quotes read.
     * The product is never saved, since the scratch tables do not mirror core's postmeta; the quotes read only
     * each item's price and quantity.
     */
    protected function cart_for( float $price ): \WC_Cart {
        wc_load_cart();

        $product = new \WC_Product_Simple();
        $product->set_name( 'Thing' );
        $product->set_regular_price( (string) $price );
        $product->set_price( (string) $price );
        $product->set_tax_status( 'none' );

        WC()->cart->set_applied_coupons( [] );
        WC()->cart->set_cart_contents(
            [
                'thing' => [
                    'key'          => 'thing',
                    'product_id'   => 0,
                    'variation_id' => 0,
                    'variation'    => [],
                    'quantity'     => 1,
                    'data'         => $product,
                    'data_hash'    => '',
                ],
            ]
        );

        return WC()->cart;
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
