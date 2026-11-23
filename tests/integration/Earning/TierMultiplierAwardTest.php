<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/**
 * The tier multiplier on a real award: EarningService writes the multiplied points and records the
 * tier and factor on the EARN row; tierless customers and tiers off write NULLs, and the Pro daily
 * limit cuts the multiplied figure without dropping the columns.
 */
final class TierMultiplierAwardTest extends IntegrationTestCase {

    private const SIGNUP_POINTS = 101;

    /** @var bool The pinned `tiers_enabled`. */
    private $tiers_enabled = true;

    /** @var int|null The pinned `max_points_per_day`. */
    private $cap = null;

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
            'schema_version'     => SettingsHelper::SCHEMA_VERSION,
            'tiers_enabled'      => $this->tiers_enabled,
            'max_points_per_day' => $this->cap,
        ];
    }

    public function test_silver_award_writes_multiplied_points_and_tier_columns(): void {
        $user = $this->customer_in( 'Silver' );
        $this->award_signup( $user );

        $row = $this->only_earn_row( $user );
        $this->assertSame( 126, (int) $row['points'] );
        $this->assertSame( $this->tier_id( 'Silver' ), (int) $row['tier_id'] );
        $this->assertSame( '1.25', $row['tier_multiplier'] );
        $this->assertSame( 'signup', $row['source'] );
        $this->assertNotNull( $row['campaign_id'] );
        $this->assertSame( 126, (int) $this->customer_row( $user )['lifetime_earned'] );
    }

    public function test_tierless_award_writes_nulls(): void {
        $user = $this->customer_in( null );
        $this->award_signup( $user );

        $row = $this->only_earn_row( $user );
        $this->assertSame( self::SIGNUP_POINTS, (int) $row['points'] );
        $this->assertNull( $row['tier_id'] );
        $this->assertNull( $row['tier_multiplier'] );
    }

    public function test_tiers_off_is_neutral(): void {
        $user                = $this->customer_in( 'Silver' );
        $this->tiers_enabled = false;
        $this->award_signup( $user );

        $row = $this->only_earn_row( $user );
        $this->assertSame( self::SIGNUP_POINTS, (int) $row['points'] );
        $this->assertNull( $row['tier_multiplier'] );
    }

    public function test_daily_limit_cuts_the_multiplied_points(): void {
        $user      = $this->customer_in( 'Silver' );
        $this->cap = 100;
        $this->award_signup( $user );

        $row = $this->only_earn_row( $user );
        $this->assertSame( 100, (int) $row['points'] );
        $this->assertSame( $this->tier_id( 'Silver' ), (int) $row['tier_id'] );
        $this->assertSame( '1.25', $row['tier_multiplier'] );
    }

    /**
     * A new customer holding the named tier, or none. The user exists before the signup campaign, so
     * registering doesn't award it.
     */
    private function customer_in( ?string $tier ): int {
        $user_id = wp_insert_user(
            [
                'user_login' => 'multiplier_' . wp_generate_password( 8, false ),
                'user_pass'  => wp_generate_password(),
                'role'       => 'customer',
            ]
        );

        $this->assertIsInt( $user_id );
        $this->users[] = $user_id;

        if ( null !== $tier ) {
            $customers = new CustomerRepository();
            $customers->ensure_row( $user_id );
            $customers->set_tier_state( $user_id, [ 'tier_id' => $this->tier_id( $tier ) ] );
        }

        return $user_id;
    }

    /** Creates the live signup campaign paying SIGNUP_POINTS, then awards it to the customer. */
    private function award_signup( int $user_id ): void {
        $now = Dates::now();

        ( new CampaignRepository() )->insert(
            new Campaign(
                null,
                'Signup multiplier',
                'signup-multiplier',
                CampaignTrigger::SIGNUP,
                Status::ACTIVE,
                0,
                array_replace( Campaign::default_config( CampaignTrigger::SIGNUP ), [ 'points' => self::SIGNUP_POINTS ] ),
                [],
                null,
                null,
                false,
                null,
                $now,
                $now
            )
        );

        EarningService::build()->award( CampaignTrigger::SIGNUP, ContextFactory::from_user( $user_id, $now ) );
    }

    /** @return array<string, mixed> The customer's only EARN row. */
    private function only_earn_row( int $user_id ): array {
        $earn = array_values(
            array_filter(
                $this->ledger_rows( $user_id ),
                static function ( array $row ): bool {
                    return 'earn' === $row['type'];
                }
            )
        );

        $this->assertCount( 1, $earn );

        return $earn[0];
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
