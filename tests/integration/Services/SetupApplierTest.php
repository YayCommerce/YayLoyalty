<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Database\Installer;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Helpers\SetupHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\SetupApplier;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Table;

/** The rates the wizard writes into the built-ins, and that a failed apply leaves nothing behind. */
final class SetupApplierTest extends IntegrationTestCase {

    /** Site options the tests overwrite. wpdb's options table is the site's own, not a scratch one. */
    private const OPTIONS = [
        SettingsHelper::OPTION_SETTINGS,
        DesignHelper::OPTION_DESIGN,
        SetupHelper::OPTION_SETUP,
    ];

    /** @var array<string, mixed> Option => stored value, null when it was absent. */
    private $saved = [];

    protected function setUp(): void {
        parent::setUp();

        foreach ( self::OPTIONS as $option ) {
            $this->saved[ $option ] = get_option( $option, null );
        }

        // The parent emptied every table, built-ins included.
        Installer::install();
    }

    protected function tearDown(): void {
        foreach ( $this->saved as $option => $value ) {
            if ( null === $value ) {
                delete_option( $option );
            } else {
                update_option( $option, $value );
            }
        }

        parent::tearDown();
    }

    public function test_apply_writes_the_rates_and_turns_the_program_on(): void {
        $this->applier()->apply( $this->draft() );

        $campaigns = new CampaignRepository();
        $campaigns->flush_cache();
        $purchase = $campaigns->find_by_slug( CampaignRepository::DEFAULT_PURCHASE_SLUG );
        $this->assertEqualsWithDelta( 1.5, (float) $purchase->get_config()['points_per_unit'], 1e-9 );
        $this->assertSame( Status::ACTIVE, $purchase->get_status() );
        $this->assertSame( Status::PAUSED, $campaigns->find_by_slug( 'signup' )->get_status() );
        $this->assertSame( Status::ACTIVE, $campaigns->find_by_slug( 'review' )->get_status() );
        $this->assertSame( 50, (int) $campaigns->find_by_slug( 'review' )->get_config()['points'] );

        $reward = ( new RewardRepository() )->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );
        $this->assertEqualsWithDelta( 200.0, (float) $reward->get_config()['points_per_currency_unit'], 1e-9 );
        $this->assertSame( 200, (int) $reward->get_config()['min_points'] );
        $this->assertNull( $reward->get_config()['max_percent_of_subtotal'] );
        $this->assertSame( Status::ACTIVE, $reward->get_status() );

        $settings = SettingsHelper::get_settings();
        $this->assertSame( 'Star', $settings['points_label_singular'] );
        $this->assertSame( 'Stars', $settings['points_label_plural'] );
        $this->assertTrue( $settings['enabled'] );
        $this->assertSame( 'bottom_left', DesignHelper::get_design()['launcher']['position'] );
        $this->assertSame( '#aa3300', strtolower( DesignHelper::get_design()['launcher']['brand_color'] ) );
    }

    public function test_a_cap_below_100_percent_is_stored(): void {
        $draft                          = $this->draft();
        $draft['reward']['max_percent'] = 50;

        $this->applier()->apply( $draft );

        $reward = ( new RewardRepository() )->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );
        $this->assertEqualsWithDelta( 50.0, (float) $reward->get_config()['max_percent_of_subtotal'], 1e-9 );
    }

    public function test_a_missing_builtin_changes_nothing(): void {
        SettingsHelper::update_settings( [ 'enabled' => false ] );
        $settings_before = get_option( SettingsHelper::OPTION_SETTINGS );
        $design_before   = get_option( DesignHelper::OPTION_DESIGN );
        $GLOBALS['wpdb']->delete( Table::name( 'campaigns' ), [ 'slug' => 'review' ] );
        ( new CampaignRepository() )->flush_cache();

        try {
            $this->applier()->apply( $this->draft() );
            $this->fail( 'apply() should throw when a built-in is missing.' );
        } catch ( \RuntimeException $e ) {
            // Expected.
            unset( $e );
        }

        $campaigns = new CampaignRepository();
        $campaigns->flush_cache();
        $this->assertEqualsWithDelta( 1.0, (float) $campaigns->find_by_slug( CampaignRepository::DEFAULT_PURCHASE_SLUG )->get_config()['points_per_unit'], 1e-9 );
        $this->assertSame( $settings_before, get_option( SettingsHelper::OPTION_SETTINGS ) );
        $this->assertSame( $design_before, get_option( DesignHelper::OPTION_DESIGN ) );
    }

    private function applier(): SetupApplier {
        return new SetupApplier( new CampaignRepository(), new RewardRepository() );
    }

    private function draft(): array {
        return [
            'points_label_singular' => 'Star',
            'points_label_plural'   => 'Stars',
            'earn'                  => [
                'points' => 3,
                'amount' => '2',
            ],
            'signup'                => [
                'enabled' => false,
                'points'  => 100,
            ],
            'review'                => [
                'enabled' => true,
                'points'  => 50,
            ],
            'reward'                => [
                'points'      => 200,
                'amount'      => '1',
                'min_points'  => 200,
                'max_percent' => 100,
            ],
            'launcher'              => [
                'position'    => 'bottom_left',
                'brand_color' => '#aa3300',
            ],
        ];
    }
}
