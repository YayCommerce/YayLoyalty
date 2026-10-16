<?php
namespace YayLoyalty\Tests\Integration\Engine\Expiry;

use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/** Shortening the inactivity window keeps stored dates, or moves them to the new window with no grace, as the admin chose. */
final class ExpiryShortenTest extends IntegrationTestCase {

    private const USER_ID = 525252;

    /** @var int */
    private $days = 365;

    /** @var string */
    private $on_shorten = ExpiryPolicy::ON_SHORTEN_KEEP;

    protected function setUp(): void {
        parent::setUp();

        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        delete_option( ExpiryRunner::OPTION_APPLIED );

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [
            'schema_version' => SettingsHelper::SCHEMA_VERSION,
            'expiry'         => [
                'enabled'         => true,
                'mode'            => ExpiryMode::INACTIVITY,
                'inactivity_days' => $this->days,
                'on_shorten'      => $this->on_shorten,
            ],
        ];
    }

    public function test_keep_leaves_the_stored_date(): void {
        $earned_at = $this->earn_under_365_then_shorten_to_30( 40, ExpiryPolicy::ON_SHORTEN_KEEP );

        $this->assertSame( ExpiryPolicy::inactivity_expires_at( $earned_at, 365 ), $this->expires_at() );
        $this->assertSame( [ 300 ], $this->ledger_points( self::USER_ID ), 'Keeping dates must not expire anything.' );
    }

    public function test_apply_moves_the_date_to_the_new_window_without_grace(): void {
        $earned_at = $this->earn_under_365_then_shorten_to_30( 10, ExpiryPolicy::ON_SHORTEN_APPLY );

        // Earned + 30 is 20 days away, sooner than the 30-day grace floor, and stands.
        $this->assertSame( ExpiryPolicy::inactivity_expires_at( $earned_at, 30 ), $this->expires_at() );
    }

    public function test_apply_expires_a_date_already_past_on_the_same_run(): void {
        $this->earn_under_365_then_shorten_to_30( 40, ExpiryPolicy::ON_SHORTEN_APPLY );

        $this->assertSame( [ 300, -300 ], $this->ledger_points( self::USER_ID ) );
    }

    public function test_activity_never_moves_a_kept_date_sooner(): void {
        $earned_at = $this->earn_under_365_then_shorten_to_30( 40, ExpiryPolicy::ON_SHORTEN_KEEP );
        $kept      = ExpiryPolicy::inactivity_expires_at( $earned_at, 365 );

        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 2, 50 ) );
        $this->assertSame( $kept, $this->expires_at(), 'An earn under the 30-day window must not move the kept date sooner.' );

        $soon = Dates::offset( 10 * DAY_IN_SECONDS );
        ( new CustomerRepository() )->set_expires_at( self::USER_ID, $soon );

        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 3, 50 ) );
        $this->assertGreaterThan( Dates::to_timestamp( $soon ), Dates::to_timestamp( $this->expires_at() ), 'An earn whose window ends later moves the date later.' );
    }

    /** Earns $days_ago under a 365-day window, then shortens it to 30 and runs the job. @return string The earn's date. */
    private function earn_under_365_then_shorten_to_30( int $days_ago, string $on_shorten ): string {
        $earned_at = Dates::offset( -$days_ago * DAY_IN_SECONDS );

        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 1, 300, $earned_at ) );
        $this->assertSame( ExpiryPolicy::inactivity_expires_at( $earned_at, 365 ), $this->expires_at() );
        update_option( ExpiryRunner::OPTION_APPLIED, ExpiryPolicy::current()->signature(), false );

        $this->days       = 30;
        $this->on_shorten = $on_shorten;
        ( new ExpiryRunner() )->run();

        return $earned_at;
    }

    private function expires_at(): string {
        return (string) $this->customer_row( self::USER_ID )['expires_at'];
    }
}
