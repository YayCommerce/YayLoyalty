<?php
namespace YayLoyalty\Tests\Unit\Earning;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Tests\Unit\EngineTestCase;

/** Each rule returns its reason; the first failing rule wins in the documented order. */
final class ProgramGateTest extends EngineTestCase {

    protected function setUp(): void {
        $GLOBALS['yayloyalty_test_options']   = [];
        $GLOBALS['yayloyalty_test_user_meta'] = [];
    }

    private function check( ?int $user_id = 7 ): ?string {
        return ( new ProgramGate() )->check( CampaignTrigger::PURCHASE, $this->context( [], 0.0, [], 1.0, 0, $user_id ) );
    }

    private function set_general( array $general ): void {
        $GLOBALS['yayloyalty_test_options'][ SettingsHelper::OPTION_SETTINGS ] = [ 'general' => $general ];
    }

    public function test_paused_program_returns_program_paused(): void {
        $this->set_general( [ 'enabled' => false ] );

        // Guest too: paused is checked before any customer rule.
        $this->assertSame( ProgramGate::REASON_PROGRAM_PAUSED, $this->check( null ) );
    }

    public function test_guest_context_returns_no_customer(): void {
        $this->assertSame( ProgramGate::REASON_NO_CUSTOMER, $this->check( null ) );
    }

    public function test_opted_out_customer_returns_opted_out(): void {
        $GLOBALS['yayloyalty_test_user_meta'][7][ ProgramGate::META_OPTED_OUT ] = '1';

        $this->assertSame( ProgramGate::REASON_OPTED_OUT, $this->check() );
    }

    public function test_excluded_role_returns_excluded_role(): void {
        $this->assertNull( $this->check() );

        $this->set_general( [ 'excluded_roles' => [ 'customer' ] ] );

        $this->assertSame( ProgramGate::REASON_EXCLUDED_ROLE, $this->check() );
    }

    public function test_opted_out_is_checked_before_excluded_role(): void {
        $GLOBALS['yayloyalty_test_user_meta'][7][ ProgramGate::META_OPTED_OUT ] = '1';
        $this->set_general( [ 'excluded_roles' => [ 'customer' ] ] );

        $this->assertSame( ProgramGate::REASON_OPTED_OUT, $this->check() );
    }
}
