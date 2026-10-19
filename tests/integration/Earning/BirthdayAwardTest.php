<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\Birthday\BirthdayRunner;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** The daily birthday run: once per birthday year, and only inside the paying campaign's window. Every run is pinned to a mid-year site day. */
final class BirthdayAwardTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private const DAY = '2026-06-15';

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( YayLoyaltyPro::class ) ) {
            $this->markTestSkipped( 'Pro is not booted on this site.' );
        }

        $this->require_woocommerce();
        $this->pin_settings();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_pays_once_per_birthday_year(): void {
        $user_id = $this->customer_with_birthday( 6, 15 );
        $this->live_birthday_campaign( 150, 0 );

        $this->run_on( self::DAY );
        $this->run_on( self::DAY );

        $rows = $this->birthday_rows( $user_id );
        $this->assertCount( 1, $rows );
        $this->assertSame( 150, (int) $rows[0]['points'] );
        $this->assertSame( 2026, (int) $rows[0]['reference_id'] );
        $this->assertSame( 150, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_birthday_moved_later_after_payment_pays_nothing_more(): void {
        $user_id = $this->customer_with_birthday( 6, 15 );
        $this->live_birthday_campaign( 150, 0 );

        $this->run_on( self::DAY );
        ( new CustomerRepository() )->set_birthday( $user_id, 6, 16 );
        $this->run_on( '2026-06-16' );

        $this->assertCount( 1, $this->birthday_rows( $user_id ) );
    }

    public function test_outside_the_window_pays_nothing(): void {
        $user_id = $this->customer_with_birthday( 6, 18 );
        $this->live_birthday_campaign( 150, 2 );

        $this->run_on( self::DAY );

        $this->assertSame( [], $this->birthday_rows( $user_id ) );
    }

    public function test_campaign_pays_only_inside_its_own_window(): void {
        $user_id = $this->customer_with_birthday( 6, 18 );
        // The engine tries the higher priority first; its 0-day window excludes a birthday 3 days away.
        $this->live_birthday_campaign( 150, 0, 20 );
        $this->live_birthday_campaign( 40, 5, 10 );

        $this->run_on( self::DAY );

        $rows = $this->birthday_rows( $user_id );
        $this->assertCount( 1, $rows );
        $this->assertSame( 40, (int) $rows[0]['points'] );
    }

    public function test_no_live_campaign_pays_nothing(): void {
        $user_id = $this->customer_with_birthday( 6, 15 );

        $this->run_on( self::DAY );

        $this->assertSame( [], $this->birthday_rows( $user_id ) );
    }

    private function run_on( string $ymd ): void {
        ( new BirthdayRunner( new \DateTimeImmutable( $ymd, wp_timezone() ) ) )->run();
    }

    private function customer_with_birthday( int $month, int $day ): int {
        $user_id = $this->create_customer();
        ( new CustomerRepository() )->set_birthday( $user_id, $month, $day );

        return $user_id;
    }

    private function live_birthday_campaign( int $points, int $days_before, int $priority = 0 ): int {
        static $sequence = 0;
        ++$sequence;

        return ( new CampaignRepository() )->insert(
            new Campaign(
                null,
                'Birthday test ' . $sequence,
                'birthday-test-' . $sequence,
                CampaignTrigger::BIRTHDAY,
                Status::ACTIVE,
                $priority,
                [
                    'points'      => $points,
                    'days_before' => $days_before,
                ],
                []
            )
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function birthday_rows( int $user_id ): array {
        return array_values(
            array_filter(
                $this->ledger_rows( $user_id ),
                static fn( array $row ): bool => LedgerSource::BIRTHDAY === $row['source']
            )
        );
    }
}
