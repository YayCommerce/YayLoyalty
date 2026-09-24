<?php
namespace YayLoyalty\Tests\Unit\Redemption;

use PHPUnit\Framework\TestCase;

/** Two thin adapters over one service. Need WooCommerce — specification only until a wp-phpunit harness exists. */
final class AdaptersTest extends TestCase {

    public function test_affordability_is_validated_at_checkout_submission_before_totals_freeze(): void {
        $this->markTestIncomplete( 'woocommerce_after_checkout_validation (classic) and woocommerce_store_api_cart_errors (blocks) both add an error from checkout_error() and no order is created.' );
    }

    public function test_the_order_processed_hook_commits_exactly_once(): void {
        $this->markTestIncomplete( 'woocommerce_checkout_order_processed and woocommerce_store_api_checkout_order_processed each call RedemptionService::commit( order ) once.' );
    }

    public function test_adapters_contain_no_rule_of_their_own(): void {
        $this->markTestIncomplete( 'grep of Adapters/ finds no Clamp, no balance, no min/max, no rate: every number comes from quote().' );
    }

    public function test_store_api_exposes_numbers_only(): void {
        $this->markTestIncomplete( 'extensions.yayloyalty carries requested, applied, value, currency, bound_by, reason — never the Reward or the rate snapshot.' );
    }

    public function test_intent_arrives_unclamped_from_either_adapter(): void {
        $this->markTestIncomplete( 'A posted 99999 reaches SessionIntent::set( 99999 ) from the classic form and from the Store API update callback alike.' );
    }
}
