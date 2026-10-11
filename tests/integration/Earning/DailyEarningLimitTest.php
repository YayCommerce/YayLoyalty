<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/** The Pro daily limit's remaining-today figure, which the storefront shows in place of the rates. */
final class DailyEarningLimitTest extends IntegrationTestCase {

    /** @var int|null The pinned `max_points_per_day`. */
    private $cap = null;

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( YayLoyaltyPro::class ) ) {
            $this->markTestSkipped( 'Pro is not booted on this site.' );
        }

        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [
            'schema_version'     => SettingsHelper::SCHEMA_VERSION,
            'max_points_per_day' => $this->cap,
        ];
    }

    public function test_points_left_today_counts_todays_earn_rows(): void {
        $user_id = 515151;

        $this->assertNull( EarningService::points_left_today( $user_id ), 'No cap is no limit.' );

        $this->cap = 100;
        $this->earn( $user_id, 1, 60, Dates::now() );
        $this->earn( $user_id, 2, 500, Dates::offset( -DAY_IN_SECONDS ) ); // yesterday
        $this->ledger_service()->adjust( $user_id, 500, 'goodwill', 1 );   // manual, never capped
        $this->assertSame( 40, EarningService::points_left_today( $user_id ) );

        $this->earn( $user_id, 3, 60, Dates::now() );
        $this->assertSame( 0, EarningService::points_left_today( $user_id ) );
    }

    /** A purchase EARN row for one order, written at $created_at. */
    private function earn( int $user_id, int $order_id, int $points, string $created_at ): void {
        $this->ledger_service()->earn( $this->earn_draft( $user_id, $order_id, $points, $created_at ) );
    }
}
