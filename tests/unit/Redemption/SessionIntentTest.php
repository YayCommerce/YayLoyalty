<?php
namespace YayLoyalty\Tests\Unit\Redemption;

use PHPUnit\Framework\TestCase;

/** The one integer. Needs WC()->session — specification only until a wp-phpunit harness exists. */
final class SessionIntentTest extends TestCase {

    public function test_only_the_requested_points_integer_is_stored(): void {
        $this->markTestIncomplete( 'After set( 500 ) the session holds exactly one key, yayloyalty_redeem_points = 500; no value, no currency, no quote.' );
    }

    public function test_set_does_not_clamp(): void {
        $this->markTestIncomplete( 'set( 99999 ) with balance 100 stores 99999; the clamp runs on the next quote, so a later balance change is never hidden by a stale ceiling.' );
    }

    public function test_get_is_zero_without_a_session_and_never_negative(): void {
        $this->markTestIncomplete( 'No WC session → 0; set( -5 ) → get() 0.' );
    }

    public function test_clear_removes_the_key(): void {
        $this->markTestIncomplete( 'clear() then get() → 0 and the key is absent from the session.' );
    }
}
