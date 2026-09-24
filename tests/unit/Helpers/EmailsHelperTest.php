<?php
namespace YayLoyalty\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Helpers\EmailsHelper;
use YayLoyalty\Helpers\EmailsMetadata;

/**
 * EmailsHelper persists a flat bool map keyed by EmailsMetadata::all_email_ids() (all 12 — Free
 * and Pro share one store). Tests verify: GET merges over defaults, unknown keys dropped, POST
 * patches (not wipes) for both Free and Pro-only ids, WooCommerce-inactive safe, get_response()
 * groups shape is correct with isFree computed per item.
 */
class EmailsHelperTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yayloyalty_test_options']         = [];
        $GLOBALS['yayloyalty_test_current_user_id'] = 1;
        unset( $GLOBALS['yayloyalty_test_blogname'] );
    }

    public function test_get_emails_merges_over_defaults(): void {
        update_option( EmailsHelper::OPTION_EMAILS, [ 'emails' => [ 'points_earned' => false ] ] );

        $result = EmailsHelper::get_emails();

        $this->assertFalse( $result['points_earned'] );
        $this->assertTrue( $result['points_expiring_soon'] ); // default true
        $this->assertFalse( $result['points_expired'] ); // default false
    }

    public function test_get_emails_covers_all_twelve_ids(): void {
        $result = EmailsHelper::get_emails();

        $this->assertCount( 12, $result );
        $this->assertEqualsCanonicalizing( EmailsMetadata::all_email_ids(), array_keys( $result ) );
    }

    public function test_get_emails_corrupt_option_reverts_to_defaults(): void {
        update_option( EmailsHelper::OPTION_EMAILS, 'corrupted' );

        $result = EmailsHelper::get_emails();

        $this->assertEquals( EmailsMetadata::all_defaults(), $result );
    }

    public function test_get_emails_drops_unknown_keys(): void {
        update_option(
            EmailsHelper::OPTION_EMAILS,
            [
                'emails' => [
                    'points_earned' => true,
                    'fake_key'      => true,
                ],
            ]
        );

        $result = EmailsHelper::get_emails();

        $this->assertArrayNotHasKey( 'fake_key', $result );
    }

    public function test_get_emails_casts_truthy_values_to_bool(): void {
        update_option(
            EmailsHelper::OPTION_EMAILS,
            [
                'emails' => [
                    'points_earned'  => 1,
                    'points_expired' => 0,
                ],
            ]
        );

        $result = EmailsHelper::get_emails();

        $this->assertSame( true, $result['points_earned'] );
        $this->assertSame( false, $result['points_expired'] );
    }

    public function test_update_emails_persists_a_free_id(): void {
        EmailsHelper::update_emails( [ 'points_expired' => true ] );

        $this->assertTrue( EmailsHelper::get_emails()['points_expired'] );
    }

    public function test_update_emails_persists_a_pro_only_id(): void {
        EmailsHelper::update_emails( [ 'reward_claimed' => false ] );

        $this->assertFalse( EmailsHelper::get_emails()['reward_claimed'] );
    }

    public function test_update_emails_is_a_patch_not_a_wipe(): void {
        EmailsHelper::update_emails( [ 'points_earned' => false ] );
        EmailsHelper::update_emails( [ 'points_expired' => true ] );

        $result = EmailsHelper::get_emails();

        // The first call's change must survive the second, unrelated call.
        $this->assertFalse( $result['points_earned'] );
        $this->assertTrue( $result['points_expired'] );
    }

    public function test_update_emails_drops_unknown_keys(): void {
        EmailsHelper::update_emails( [ 'evil_key' => true ] );

        $stored = get_option( EmailsHelper::OPTION_EMAILS );

        $this->assertArrayNotHasKey( 'evil_key', $stored['emails'] );
    }

    public function test_update_emails_casts_to_bool(): void {
        EmailsHelper::update_emails( [ 'points_earned' => 0 ] );

        $this->assertSame( false, EmailsHelper::get_emails()['points_earned'] );
    }

    public function test_update_emails_returns_full_get_emails_shape(): void {
        $result = EmailsHelper::update_emails( [ 'points_earned' => false ] );

        $this->assertEquals( EmailsHelper::get_emails(), $result );
    }

    public function test_sending_reads_woocommerce_options(): void {
        update_option( 'woocommerce_email_from_name', 'My Shop' );
        update_option( 'woocommerce_email_from_address', 'shop@example.com' );

        $sending = EmailsHelper::sending();

        $this->assertSame( 'My Shop', $sending['from_name'] );
        $this->assertSame( 'shop@example.com', $sending['from_address'] );
        $this->assertStringContainsString( 'page=wc-settings', $sending['wc_emails_url'] );
        $this->assertStringContainsString( 'tab=email', $sending['wc_emails_url'] );
    }

    public function test_sending_is_woocommerce_inactive_safe(): void {
        // No woocommerce_* options stored and no site title set — must not fatal, blank strings only.
        $sending = EmailsHelper::sending();

        $this->assertSame( '', $sending['from_name'] );
        $this->assertSame( '', $sending['from_address'] );
    }

    /**
     * Mirrors WC_Emails::get_from_name(): when the merchant never saved
     * woocommerce_email_from_name, WooCommerce's own runtime falls back to the site title rather
     * than blank — EmailsHelper must show the same value WooCommerce actually sends mail with.
     */
    public function test_sending_from_name_falls_back_to_site_title_when_unsaved(): void {
        $GLOBALS['yayloyalty_test_blogname'] = 'My Store';

        $sending = EmailsHelper::sending();

        $this->assertSame( 'My Store', $sending['from_name'] );
    }

    public function test_sending_from_address_has_no_fallback_when_unsaved(): void {
        // Unlike from_name, WC_Emails::get_from_address() has no default — stays blank.
        $GLOBALS['yayloyalty_test_blogname'] = 'My Store';

        $sending = EmailsHelper::sending();

        $this->assertSame( '', $sending['from_address'] );
    }

    public function test_sending_from_name_prefers_saved_option_over_site_title(): void {
        $GLOBALS['yayloyalty_test_blogname'] = 'My Store';
        update_option( 'woocommerce_email_from_name', 'Explicit Name' );

        $sending = EmailsHelper::sending();

        $this->assertSame( 'Explicit Name', $sending['from_name'] );
    }

    public function test_get_throttle_per_week_defaults_to_three(): void {
        $this->assertSame( 3, EmailsHelper::get_throttle_per_week() );
    }

    public function test_update_throttle_per_week_persists(): void {
        EmailsHelper::update_throttle_per_week( 10 );

        $this->assertSame( 10, EmailsHelper::get_throttle_per_week() );
    }

    public function test_update_throttle_per_week_clamps_negative_to_zero(): void {
        EmailsHelper::update_throttle_per_week( -5 );

        $this->assertSame( 0, EmailsHelper::get_throttle_per_week() );
    }

    /** Both live in the same wp_option — this is the point of the merge. */
    public function test_emails_and_throttle_share_one_option(): void {
        EmailsHelper::update_emails( [ 'points_earned' => false ] );
        EmailsHelper::update_throttle_per_week( 5 );

        $raw = get_option( EmailsHelper::OPTION_EMAILS );

        $this->assertArrayHasKey( 'emails', $raw );
        $this->assertArrayHasKey( 'throttle_per_week', $raw );
        $this->assertFalse( $raw['emails']['points_earned'] );
        $this->assertSame( 5, $raw['throttle_per_week'] );
    }

    public function test_update_emails_does_not_reset_throttle_per_week(): void {
        EmailsHelper::update_throttle_per_week( 8 );
        EmailsHelper::update_emails( [ 'points_earned' => false ] );

        $this->assertSame( 8, EmailsHelper::get_throttle_per_week() );
    }

    public function test_update_throttle_per_week_does_not_reset_emails(): void {
        EmailsHelper::update_emails( [ 'points_expired' => true ] );
        EmailsHelper::update_throttle_per_week( 8 );

        $this->assertTrue( EmailsHelper::get_emails()['points_expired'] );
    }

    public function test_get_response_has_five_groups(): void {
        $response = EmailsHelper::get_response();

        $this->assertArrayHasKey( 'groups', $response );
        $this->assertArrayHasKey( 'sending', $response );
        $this->assertArrayHasKey( 'throttlePerWeek', $response );
        $this->assertCount( 5, $response['groups'] );
        $this->assertEqualsCanonicalizing(
            [ 'points', 'rewards', 'referral', 'tiers', 'birthday' ],
            array_column( $response['groups'], 'group' )
        );
    }

    public function test_get_response_has_twelve_items_total(): void {
        $response = EmailsHelper::get_response();

        $total = 0;
        foreach ( $response['groups'] as $group ) {
            $total += count( $group['items'] );
        }

        $this->assertSame( 12, $total );
    }

    public function test_get_response_item_shape(): void {
        $response = EmailsHelper::get_response();
        $points   = $this->find_group( $response, 'points' );
        $item     = $points['items'][0];

        $this->assertArrayHasKey( 'id', $item );
        $this->assertArrayHasKey( 'label', $item );
        $this->assertArrayHasKey( 'description', $item );
        $this->assertArrayHasKey( 'recipient', $item );
        $this->assertArrayHasKey( 'enabled', $item );
        $this->assertArrayHasKey( 'isFree', $item );
        $this->assertArrayNotHasKey( 'default_enabled', $item );
    }

    public function test_get_response_marks_free_ids_correctly(): void {
        $response = EmailsHelper::get_response();
        $points   = $this->find_group( $response, 'points' );

        foreach ( $points['items'] as $item ) {
            $expected = in_array( $item['id'], EmailsMetadata::FREE_EMAIL_IDS, true );
            $this->assertSame( $expected, $item['isFree'], "isFree mismatch for {$item['id']}" );
        }
    }

    public function test_get_response_enabled_reflects_persisted_state_for_free_id(): void {
        EmailsHelper::update_emails( [ 'points_expired' => true ] );

        $response = EmailsHelper::get_response();
        $points   = $this->find_group( $response, 'points' );
        $item     = $this->find_item( $points, 'points_expired' );

        $this->assertTrue( $item['enabled'] );
    }

    public function test_get_response_enabled_reflects_persisted_state_for_pro_only_id(): void {
        EmailsHelper::update_emails( [ 'reward_claimed' => false ] );

        $response = EmailsHelper::get_response();
        $rewards  = $this->find_group( $response, 'rewards' );
        $item     = $this->find_item( $rewards, 'reward_claimed' );

        $this->assertFalse( $item['enabled'] );
    }

    public function test_get_response_throttle_reflects_persisted_value(): void {
        EmailsHelper::update_throttle_per_week( 7 );

        $this->assertSame( 7, EmailsHelper::get_response()['throttlePerWeek'] );
    }

    public function test_get_response_empty_option_uses_defaults(): void {
        $response = EmailsHelper::get_response();
        $points   = $this->find_group( $response, 'points' );

        $this->assertTrue( $this->find_item( $points, 'points_earned' )['enabled'] );
        $this->assertFalse( $this->find_item( $points, 'points_expired' )['enabled'] );
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function find_group( array $response, string $group ): array {
        foreach ( $response['groups'] as $entry ) {
            if ( $group === $entry['group'] ) {
                return $entry;
            }
        }

        $this->fail( "group not found: {$group}" );
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function find_item( array $group, string $id ): array {
        foreach ( $group['items'] as $item ) {
            if ( $id === $item['id'] ) {
                return $item;
            }
        }

        $this->fail( "item not found: {$id}" );
    }
}
