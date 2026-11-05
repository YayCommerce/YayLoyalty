<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Redemption\Quoter;
use YayLoyalty\Engine\Redemption\RewardGate;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Pro\Services\RewardTierCondition;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/**
 * A reward's "Minimum tier" condition: Silver and above may claim it; Bronze, tierless customers,
 * guests and everyone while tiers are off may not; a reward without the entry is unaffected, and a
 * deleted required tier refuses. The last two tests run the claim gate the Quoter calls.
 */
final class RewardTierConditionTest extends IntegrationTestCase {

    /** @var bool The pinned `tiers_enabled`. */
    private $tiers_enabled = true;

    /** @var int[] Users this test created, deleted in tearDown(). */
    private $users = [];

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( YayLoyaltyPro::class ) ) {
            $this->markTestSkipped( 'Pro is not booted on this site.' );
        }

        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

        $tiers = new TierRepository();
        $tiers->seed_defaults( Dates::now() );
        $tiers->rerank();
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

        require_once ABSPATH . 'wp-admin/includes/user.php';

        foreach ( $this->users as $user_id ) {
            wp_delete_user( $user_id );
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [
            'schema_version' => SettingsHelper::SCHEMA_VERSION,
            'tiers_enabled'  => $this->tiers_enabled,
        ];
    }

    public function test_silver_and_above_are_allowed(): void {
        $reward = $this->silver_reward();

        $this->assertTrue( $this->allows( $reward, $this->customer_in( 'Silver' ) ) );
        $this->assertTrue( $this->allows( $reward, $this->customer_in( 'Gold' ) ) );
    }

    public function test_below_silver_tierless_and_guest_are_refused(): void {
        $reward = $this->silver_reward();

        $this->assertFalse( $this->allows( $reward, $this->customer_in( 'Bronze' ) ) );
        $this->assertFalse( $this->allows( $reward, $this->customer_in( null ) ) );
        $this->assertFalse( $this->allows( $reward, null ) );
    }

    public function test_tiers_off_refuses_everyone(): void {
        $reward              = $this->silver_reward();
        $gold                = $this->customer_in( 'Gold' );
        $this->tiers_enabled = false;

        $this->assertFalse( $this->allows( $reward, $gold ) );
    }

    public function test_reward_without_a_tier_entry_passes(): void {
        $reward = $this->reward_with(
            [
                [
                    'type'     => 'user_role',
                    'operator' => 'in',
                    'value'    => [ 'administrator' ],
                ],
            ]
        );

        $this->assertTrue( $this->allows( $reward, $this->customer_in( 'Bronze' ) ) );
    }

    public function test_deleted_required_tier_refuses(): void {
        $reward = $this->silver_reward();
        $gold   = $this->customer_in( 'Gold' );
        ( new TierRepository() )->soft_delete( $this->tier_id( 'Silver' ), Dates::now() );

        $this->assertFalse( $this->allows( $reward, $gold ) );
    }

    /** With no points too: the conditions are checked before the cost, so the listings can drop the reward. */
    public function test_gate_refuses_bronze_with_conditions_not_met(): void {
        $quote = $this->quote( $this->silver_reward(), $this->customer_in( 'Bronze' ) );

        $this->assertSame( Result::REASON_CONDITIONS_NOT_MET, $quote->get_reason() );
    }

    public function test_gate_allows_silver(): void {
        $quote = $this->quote( $this->silver_reward(), $this->customer_in( 'Silver', 500 ) );

        $this->assertTrue( $quote->is_allowed() );
    }

    private function allows( Reward $reward, ?int $user_id ): bool {
        $facts = ( new ContextFactory( new CustomerRepository() ) )->facts_for( $user_id );

        return ( new RewardTierCondition() )->allows( $reward, $facts );
    }

    /** The cart-time quote through RewardGate alone, which applies the conditions filter Pro answers. */
    private function quote( Reward $reward, int $user_id ): Result {
        $ctx    = ( new ContextFactory( new CustomerRepository() ) )->from_cart( $this->cart_for( 50.0 ), $user_id, null );
        $quoter = new Quoter( new RewardEngine( Registry::with_lite_handlers() ), [ new RewardGate() ] );

        return $quoter->quote( $reward, $ctx );
    }

    /** A 100-point fixed_coupon worth 5 that requires Silver. */
    private function silver_reward(): Reward {
        return $this->reward_with(
            [
                [
                    'type'     => 'tier',
                    'operator' => 'gte',
                    'value'    => $this->tier_id( 'Silver' ),
                ],
            ]
        );
    }

    /** @param array<int, array<string, mixed>> $conditions */
    private function reward_with( array $conditions ): Reward {
        $reward = $this->pro_reward( RewardType::FIXED_COUPON, 100, [ 'amount' => 5 ] );
        $reward->set_conditions( $conditions );

        $rewards = new RewardRepository();
        $update  = $reward->to_update_row();
        $rewards->update( (int) $reward->get_id(), $update['data'], $update['format'] );

        return $rewards->find_reward( (int) $reward->get_id() );
    }

    /**
     * A new customer with a row, holding the named tier or none, and $balance spendable points.
     * The balance is set before the tier, so the tier evaluator can't move it afterwards.
     */
    private function customer_in( ?string $tier, int $balance = 0 ): int {
        $user_id = wp_insert_user(
            [
                'user_login' => 'gated_' . wp_generate_password( 8, false ),
                'user_pass'  => wp_generate_password(),
                'role'       => 'customer',
            ]
        );

        $this->assertIsInt( $user_id );
        $this->users[] = $user_id;

        $customers = new CustomerRepository();
        $customers->ensure_row( $user_id );

        if ( $balance > 0 ) {
            $this->ledger_service()->adjust( $user_id, $balance, 'seed', 1 );
        }

        $customers->set_tier_state( $user_id, [ 'tier_id' => null === $tier ? null : $this->tier_id( $tier ) ] );

        return $user_id;
    }

    private function tier_id( string $name ): int {
        foreach ( ( new TierRepository() )->live() as $tier ) {
            if ( $name === $tier->get_name() ) {
                return (int) $tier->get_id();
            }
        }

        $this->fail( 'No live tier named ' . $name );
    }
}
