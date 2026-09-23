<?php
namespace YayLoyalty\Tests\Unit\Earning;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\RewardType;

/** One string, trigger included, fits the column: the ledger's whole idempotency story. */
final class IdempotencyKeyTest extends TestCase {

    public function test_composes_type_user_reference_and_trigger_as_one_string(): void {
        $this->assertSame(
            'earn:7:order:1234:purchase',
            IdempotencyKey::compose( LedgerType::EARN, 7, ReferenceType::ORDER, 1234, CampaignTrigger::PURCHASE )
        );
    }

    public function test_a_redeem_row_composes_with_the_reward_type_as_its_qualifier(): void {
        $this->assertSame(
            'redeem:7:redemption:88:flexible_discount',
            IdempotencyKey::compose( LedgerType::REDEEM, 7, ReferenceType::REDEMPTION, 88, RewardType::FLEXIBLE_DISCOUNT )
        );
    }

    public function test_a_redeem_row_rejects_a_campaign_trigger_as_qualifier(): void {
        $this->expectException( \InvalidArgumentException::class );

        IdempotencyKey::compose( LedgerType::REDEEM, 7, ReferenceType::REDEMPTION, 88, CampaignTrigger::PURCHASE );
    }

    public function test_one_order_earns_a_distinct_key_per_trigger(): void {
        $purchase    = IdempotencyKey::compose( LedgerType::EARN, 7, ReferenceType::ORDER, 1234, CampaignTrigger::PURCHASE );
        $first_order = IdempotencyKey::compose( LedgerType::EARN, 7, ReferenceType::ORDER, 1234, CampaignTrigger::FIRST_ORDER );

        $this->assertNotSame( $purchase, $first_order );
    }

    public function test_one_reference_earns_a_distinct_key_per_user(): void {
        $advocate = IdempotencyKey::compose( LedgerType::EARN, 7, ReferenceType::ORDER, 1234, CampaignTrigger::REFERRAL );
        $friend   = IdempotencyKey::compose( LedgerType::EARN, 8, ReferenceType::ORDER, 1234, CampaignTrigger::REFERRAL );

        $this->assertNotSame( $advocate, $friend );
    }

    public function test_a_pro_trigger_composes_in_any_build(): void {
        $this->assertSame(
            'earn:7:birthday:2026:birthday',
            IdempotencyKey::compose( LedgerType::EARN, 7, ReferenceType::BIRTHDAY, 2026, CampaignTrigger::BIRTHDAY )
        );
    }

    public function test_longest_vocabulary_fits_the_column(): void {
        $key = IdempotencyKey::compose( LedgerType::REFUND_REDEEM, PHP_INT_MAX, ReferenceType::REDEMPTION, PHP_INT_MAX, RewardType::FLEXIBLE_DISCOUNT );

        $this->assertLessThanOrEqual( IdempotencyKey::MAX_LENGTH, strlen( $key ) );
    }

    public function test_rejects_an_unknown_trigger(): void {
        $this->expectException( \InvalidArgumentException::class );

        IdempotencyKey::compose( LedgerType::EARN, 7, ReferenceType::ORDER, 1, 'lottery' );
    }

    public function test_rejects_a_non_positive_reference_id(): void {
        $this->expectException( \InvalidArgumentException::class );

        IdempotencyKey::compose( LedgerType::EARN, 7, ReferenceType::ORDER, 0, CampaignTrigger::PURCHASE );
    }
}
