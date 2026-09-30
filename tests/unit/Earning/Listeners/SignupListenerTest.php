<?php
namespace YayLoyalty\Tests\Unit\Earning\Listeners;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\Listeners\SignupListener;

/** Only a visitor registering themself earns the signup campaign. WP_CLI is a constant, so its branch is not reachable here. */
final class SignupListenerTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['yayloyalty_test_current_user'], $GLOBALS['yayloyalty_test_doing_cron'], $GLOBALS['yayloyalty_test_doing_actions'] );

        parent::tearDown();
    }

    public function test_a_logged_out_request_is_self_registration(): void {
        $this->assertTrue( SignupListener::is_self_registration() );
    }

    public function test_an_acting_user_is_not(): void {
        $GLOBALS['yayloyalty_test_current_user'] = 1;

        $this->assertFalse( SignupListener::is_self_registration() );
    }

    public function test_cron_is_not(): void {
        $GLOBALS['yayloyalty_test_doing_cron'] = true;

        $this->assertFalse( SignupListener::is_self_registration() );
    }

    public function test_an_action_scheduler_run_is_not(): void {
        $GLOBALS['yayloyalty_test_doing_actions'] = [ 'action_scheduler_run_queue' ];

        $this->assertFalse( SignupListener::is_self_registration() );
    }
}
