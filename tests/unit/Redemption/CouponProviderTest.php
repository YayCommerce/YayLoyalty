<?php
namespace YayLoyalty\Tests\Unit\Redemption;

use PHPUnit\Framework\TestCase;

/** The virtual coupon. Needs WC_Coupon / WC_Cart — specification only until a wp-phpunit harness exists. */
final class CouponProviderTest extends TestCase {

    public function test_the_discount_is_a_virtual_coupon_not_a_persisted_post(): void {
        $this->markTestIncomplete( 'woocommerce_get_shop_coupon_data for yayloyalty-redeem returns the fixed_cart array; no shop_coupon post exists before or after.' );
    }

    public function test_the_virtual_coupon_stacks_with_real_coupons(): void {
        $this->markTestIncomplete( 'individual_use is false in the returned data; a cart with another coupon keeps both.' );
    }

    public function test_the_virtual_coupon_applies_after_every_real_coupon(): void {
        $this->markTestIncomplete( 'woocommerce_coupon_sort moves yayloyalty-redeem to the end; the quote\'s cart ceiling equals the subtotal the other coupons left.' );
    }

    public function test_the_amount_is_requoted_on_every_totals_run(): void {
        $this->markTestIncomplete( 'Two calculate_totals() with a cart change in between produce two different coupon amounts from the same session intent.' );
    }

    public function test_other_coupon_codes_are_untouched(): void {
        $this->markTestIncomplete( 'virtual_coupon_data( $data, "summer" ) returns $data unchanged.' );
    }

    public function test_the_coupon_line_follows_the_intent(): void {
        $this->markTestIncomplete( 'sync_cart applies the code when intent > 0 and removes it when intent is 0; other coupons are never touched.' );
    }

    public function test_the_virtual_coupon_carries_no_free_shipping(): void {
        $this->markTestIncomplete( 'free_shipping is false; free shipping is a Pro reward type, not a property of the flexible discount.' );
    }
}
