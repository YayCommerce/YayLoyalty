<?php
namespace YayLoyalty\Tests\Integration\Engine;

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * The member's "worth $X" and the guest's "N points = $1" come from the cart-discount reward row,
 * and the rate copy never rounds a real rate down to a misleading number.
 */
final class LauncherMemberWorthAndRedeemRateTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private int $user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->user_id = $this->create_customer();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();
        parent::tearDown();
    }

    /** Test case D7: the seed redeems in 100-point steps, so 1,250 points take $12 off, never a rounded-up $13. */
    public function test_worth_is_what_checkout_would_grant_for_the_balance(): void {
        $this->seed_reward( Status::ACTIVE, 100 );
        $this->set_balance( $this->user_id, 1250 );

        $member = $this->build( $this->user_id )['member'];

        $this->assertSame( 1250, $member['balance'] );
        $this->assertSame( 12.0, $member['worth'] );
    }

    public function test_worth_never_exceeds_the_exact_value(): void {
        $this->seed_reward( Status::ACTIVE, 100, null, [ 'increment' => 1, 'min_points' => 0 ] );
        $this->set_balance( $this->user_id, 1249 );

        $this->assertLessThanOrEqual( 1249 / 100, $this->build( $this->user_id )['member']['worth'] );
    }

    public function test_balance_below_reward_minimum_has_no_worth(): void {
        $this->seed_reward( Status::ACTIVE, 100 );
        $this->set_balance( $this->user_id, 99 );

        $this->assertNull( $this->build( $this->user_id )['member']['worth'] );
    }

    public function test_negative_balance_has_no_worth(): void {
        $this->seed_reward( Status::ACTIVE, 100 );
        $this->set_balance( $this->user_id, -120 );

        $member = $this->build( $this->user_id )['member'];

        $this->assertSame( -120, $member['balance'] );
        $this->assertNull( $member['worth'] );
    }

    public function test_guest_redeem_rule_reads_the_reward_rate(): void {
        $this->seed_reward( Status::ACTIVE, 50 );

        $this->assertSame( [ 'points_per_unit' => 50.0 ], $this->build( null )['redeem'] );
    }

    public function test_guest_redeem_rule_hidden_when_reward_paused_or_ended(): void {
        $this->seed_reward( Status::PAUSED, 100 );
        $this->assertNull( $this->build( null )['redeem'], 'paused' );

        $this->truncate_rewards();
        $this->seed_reward( Status::ACTIVE, 100, Dates::offset( -60 ) );
        $this->assertNull( $this->build( null )['redeem'], 'ended' );
    }

    public function test_guest_redeem_rule_hidden_without_reward_row(): void {
        $this->assertNull( $this->build( null )['redeem'] );
    }

    /** Test case RDM-04 / D2: the guest panel hides redeeming then, so the member must not see a worth. */
    public function test_member_worth_hidden_when_reward_not_live(): void {
        $this->seed_reward( Status::PAUSED, 100 );
        $this->set_balance( $this->user_id, 1250 );

        $this->assertNull( $this->build( $this->user_id )['member']['worth'], 'Worth is shown for a reward the shopper cannot redeem.' );
    }

    public function test_rate_copy_keeps_meaningful_decimals(): void {
        $this->assertSame( number_format_i18n( 1 ), LauncherCopy::rate( 1.0 ) );
        $this->assertSame( number_format_i18n( 1.5, 1 ), LauncherCopy::rate( 1.5 ) );
        $this->assertSame( number_format_i18n( 1.25, 2 ), LauncherCopy::rate( 1.25 ) );
    }

    /** Test case FMT-05 / D3: a positive rate must never read as zero. */
    public function test_small_positive_rate_never_reads_as_zero(): void {
        $this->assertSame( number_format_i18n( 0.004, 3 ), LauncherCopy::rate( 0.004 ) );
        $this->assertSame( number_format_i18n( 0.0045, 4 ), LauncherCopy::rate( 0.0045 ) );
        $this->assertSame( number_format_i18n( 0.333, 3 ), LauncherCopy::rate( 0.333 ) );
    }

    /** Test case D4: an order under the campaign minimum earns nothing, so the line says so. */
    public function test_earn_rate_states_campaign_minimum(): void {
        $line = LauncherCopy::earn_rate( 'Earn {points} points per {amount} spent', 1.0, 50.0 );

        $this->assertStringEndsWith( 'on orders of ' . LauncherCopy::money( 50.0 ) . ' or more', $line );
        $this->assertStringNotContainsString( 'or more', LauncherCopy::earn_rate( 'Earn {points} points per {amount} spent', 1.0 ) );
    }

    private function build( ?int $user_id ): array {
        return ( new LauncherPayload( new CampaignRepository(), new RewardRepository(), new CustomerRepository() ) )->build(
            [
                'launcher'        => [],
                'storefront_text' => [],
                'visibility'      => [],
            ],
            $user_id
        );
    }

    private function seed_reward( string $status, float $rate, ?string $ends_at = null, array $config = [] ): void {
        $now = Dates::now();

        ( new RewardRepository() )->insert(
            new Reward(
                null,
                'Cart discount',
                RewardRepository::BUILTIN_DISCOUNT_SLUG,
                RewardType::FLEXIBLE_DISCOUNT,
                $status,
                0,
                null,
                array_replace( Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ), [ 'points_per_currency_unit' => $rate ], $config ),
                [],
                null,
                $ends_at,
                true,
                null,
                null,
                $now,
                $now
            )
        );
    }

    private function truncate_rewards(): void {
        global $wpdb;

        $wpdb->query( 'TRUNCATE TABLE `' . esc_sql( Table::name( 'rewards' ) ) . '`' ); // phpcs:ignore WordPress.DB
    }
}
