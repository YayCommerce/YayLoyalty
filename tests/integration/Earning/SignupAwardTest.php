<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** user_register awards the signup campaign once, and only to a visitor registering themself. */
final class SignupAwardTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->create_campaign( CampaignTrigger::SIGNUP );
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        remove_all_filters( 'pre_option_' . SettingsHelper::OPTION_SETTINGS );
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_a_self_registration_earns_once(): void {
        $user_id = $this->register();

        do_action( 'user_register', $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing core's hook.

        $rows = $this->ledger_rows( $user_id );

        $this->assertCount( 1, $rows );
        $this->assertSame( LedgerSource::SIGNUP, $rows[0]['source'] );
        $this->assertSame( 100, (int) $rows[0]['points'] );
    }

    /** The visitor alongside proves the campaign would pay: only the acting admin makes the difference. */
    public function test_an_account_created_by_an_admin_earns_nothing(): void {
        $this->assertCount( 0, $this->ledger_rows( $this->create_customer() ) );
        $this->assertCount( 1, $this->ledger_rows( $this->register() ) );
    }

    public function test_an_excluded_role_earns_nothing(): void {
        add_filter(
            'pre_option_' . SettingsHelper::OPTION_SETTINGS,
            static function (): array {
                return [ 'general' => [ 'excluded_roles' => [ 'customer' ] ] ];
            }
        );

        $this->assertCount( 0, $this->ledger_rows( $this->register() ) );
        $this->assertCount( 1, $this->ledger_rows( $this->register( 'subscriber' ) ) );
    }

    private function register( string $role = 'customer' ): int {
        return $this->track_user(
            (int) wp_insert_user(
                [
                    'user_login' => 'yl_it_visitor_' . wp_generate_password( 8, false ),
                    'user_pass'  => wp_generate_password(),
                    'role'       => $role,
                ]
            )
        );
    }
}
