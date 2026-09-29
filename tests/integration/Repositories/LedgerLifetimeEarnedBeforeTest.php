<?php
namespace YayLoyalty\Tests\Integration\Repositories;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** lifetime_earned as of a moment: the lifetime_earned rule over rows created strictly before it. */
final class LedgerLifetimeEarnedBeforeTest extends IntegrationTestCase {

    private const USER   = 41;
    private const BEFORE = '2026-06-15 12:00:00';

    public function test_sums_lifetime_earned_types_created_strictly_before(): void {
        $this->row( LedgerType::EARN, 100, '2026-06-01 10:00:00' );
        $this->row( LedgerType::ADJUST, -30, '2026-06-02 10:00:00' );
        $this->row( LedgerType::REFUND_EARN, -20, '2026-06-03 10:00:00' );
        $this->row( LedgerType::EARN, 500, self::BEFORE );
        $this->row( LedgerType::EARN, 700, '2026-06-20 10:00:00' );

        $this->assertSame( 50, ( new LedgerRepository() )->lifetime_earned_before( self::USER, self::BEFORE ) );
    }

    public function test_spending_and_corrections_never_count(): void {
        $this->row( LedgerType::EARN, 100, '2026-06-01 10:00:00' );
        $this->row( LedgerType::REDEEM, -60, '2026-06-02 10:00:00' );
        $this->row( LedgerType::EXPIRE, -10, '2026-06-03 10:00:00' );
        $this->row( LedgerType::CORRECTION, 25, '2026-06-04 10:00:00' );
        $this->row( LedgerType::EARN, 999, '2026-06-05 10:00:00', self::USER + 1 );

        $this->assertSame( 100, ( new LedgerRepository() )->lifetime_earned_before( self::USER, self::BEFORE ) );
    }

    public function test_a_user_with_no_rows_has_zero(): void {
        $this->assertSame( 0, ( new LedgerRepository() )->lifetime_earned_before( self::USER, self::BEFORE ) );
    }

    private function row( string $type, int $points, string $created_at, int $user_id = self::USER ): void {
        ( new LedgerRepository() )->insert(
            new LedgerEntry( null, $user_id, $points, 0, $type, LedgerSource::MANUAL, null, null, null, null, null, null, null, $created_at )
        );
    }
}
