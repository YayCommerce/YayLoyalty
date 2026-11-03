<?php
namespace YayLoyalty\Tests\Integration\Storefront;

use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\Birthday\BirthdayEarnRow;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** The birthday row in "Ways to earn" speaks to the viewer the rows are for, never to whoever is signed in. */
final class BirthdayEarnRowTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private const POINTS = 150;

    /** @var int */
    private $previous_user = 0;

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( YayLoyaltyPro::class ) ) {
            $this->markTestSkipped( 'Pro is not booted on this site.' );
        }

        $this->require_woocommerce();
        $this->pin_settings();
        $this->reset_row_caches();
        $this->previous_user = get_current_user_id();

        ( new CampaignRepository() )->insert(
            new Campaign(
                null,
                'Birthday row test',
                'birthday-row-test',
                CampaignTrigger::BIRTHDAY,
                Status::ACTIVE,
                0,
                [
                    'points'      => self::POINTS,
                    'days_before' => 0,
                ],
                []
            )
        );
    }

    protected function tearDown(): void {
        wp_set_current_user( $this->previous_user );
        $this->cleanup_orders();

        if ( class_exists( BirthdayEarnRow::class ) ) {
            $this->reset_row_caches();
        }

        parent::tearDown();
    }

    public function test_guest_rows_ignore_the_signed_in_member(): void {
        wp_set_current_user( $this->customer_with_birthday() );

        $row = $this->birthday_row( LauncherEarnRules::guest_rows( LauncherPayload::for_user( DesignHelper::get_design(), 0 ) ) );

        $this->assertSame( 'Create an account and add your birthday to get a birthday bonus.', $row['description'] );
        $this->assertSame( '', $row['url'] );
    }

    public function test_member_without_birthday_is_linked_to_account_details(): void {
        $user_id = $this->create_customer();

        $row = $this->birthday_row( $this->rows_for_member( $user_id ) );

        $this->assertSame( 'Tell us your birthday to get a birthday bonus.', $row['description'] );
        $this->assertSame( wc_customer_edit_account_url(), $row['url'] );
    }

    public function test_member_with_birthday_is_promised_the_points(): void {
        $user_id = $this->customer_with_birthday();

        $row = $this->birthday_row( $this->rows_for_member( $user_id ) );

        $this->assertSame( sprintf( 'You\'ll receive %s on your next birthday.', SettingsHelper::points_text( self::POINTS ) ), $row['description'] );
        $this->assertSame( '', $row['url'] );
    }

    /** @return array[] */
    private function rows_for_member( int $user_id ): array {
        return LauncherEarnRules::for_viewer( LauncherPayload::for_user( DesignHelper::get_design(), $user_id ), $user_id )['rows'];
    }

    /** @param array[] $rows */
    private function birthday_row( array $rows ): array {
        foreach ( $rows as $row ) {
            if ( CampaignTrigger::BIRTHDAY === $row['trigger'] ) {
                return $row;
            }
        }

        $this->fail( 'No birthday row in "Ways to earn".' );
    }

    private function customer_with_birthday(): int {
        $user_id = $this->create_customer();
        ( new CustomerRepository() )->set_birthday( $user_id, 6, 15 );

        return $user_id;
    }

    /** The row caches its points and each viewer's birthday for the request; a test is a new request. */
    private function reset_row_caches(): void {
        $points = new \ReflectionProperty( BirthdayEarnRow::class, 'points' );
        $points->setAccessible( true );
        $points->setValue( null, false );

        $missing = new \ReflectionProperty( BirthdayEarnRow::class, 'missing_birthday' );
        $missing->setAccessible( true );
        $missing->setValue( null, [] );
    }
}
