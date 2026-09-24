<?php
namespace YayLoyalty\Tests\Integration\Emails;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Pro\Emails\BirthdayPointsEmail;
use YayLoyalty\Pro\Emails\CouponIssuedEmail;
use YayLoyalty\Pro\Emails\ExpiringFinalReminderEmail;
use YayLoyalty\Pro\Emails\InviteSentEmail;
use YayLoyalty\Pro\Emails\ReferralRewardEarnedEmail;
use YayLoyalty\Pro\Emails\RewardClaimedEmail;
use YayLoyalty\Pro\Emails\TierEndingSoonEmail;
use YayLoyalty\Pro\Emails\TierLostEmail;
use YayLoyalty\Pro\Emails\TierReachedEmail;
use YayLoyalty\Tests\Integration\WooCommerceEmailsFixture;
use YayLoyalty\YayLoyalty;

/**
 * Pro adds nine loyalty emails through WooCommerce's own filters, on top of
 * Lite's three. Default on/off is read from a fresh instance with its
 * WooCommerce option removed, so the site's saved choice never decides it.
 */
final class ProEmailsRegistrationTest extends TestCase {

    use WooCommerceEmailsFixture;

    private const EMAILS = [
        'yayloyalty_expiring_final_reminder' => [
            'class' => ExpiringFinalReminderEmail::class,
            'group' => 'yayloyalty_points',
        ],
        'yayloyalty_reward_claimed'          => [
            'class' => RewardClaimedEmail::class,
            'group' => 'yayloyalty_rewards',
        ],
        'yayloyalty_coupon_issued'           => [
            'class' => CouponIssuedEmail::class,
            'group' => 'yayloyalty_rewards',
        ],
        'yayloyalty_invite_sent'             => [
            'class' => InviteSentEmail::class,
            'group' => 'yayloyalty_referral',
        ],
        'yayloyalty_referral_reward_earned'  => [
            'class' => ReferralRewardEarnedEmail::class,
            'group' => 'yayloyalty_referral',
        ],
        'yayloyalty_tier_reached'            => [
            'class' => TierReachedEmail::class,
            'group' => 'yayloyalty_tiers',
        ],
        'yayloyalty_tier_ending_soon'        => [
            'class' => TierEndingSoonEmail::class,
            'group' => 'yayloyalty_tiers',
        ],
        'yayloyalty_tier_lost'               => [
            'class' => TierLostEmail::class,
            'group' => 'yayloyalty_tiers',
        ],
        'yayloyalty_birthday_points'         => [
            'class' => BirthdayPointsEmail::class,
            'group' => 'yayloyalty_birthday',
        ],
    ];

    private const GROUP_TITLES = [
        'yayloyalty_points'   => 'Loyalty: Points',
        'yayloyalty_rewards'  => 'Loyalty: Rewards',
        'yayloyalty_referral' => 'Loyalty: Referral',
        'yayloyalty_tiers'    => 'Loyalty: Tiers',
        'yayloyalty_birthday' => 'Loyalty: Birthday',
    ];

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce_mailer();

        if ( ! YayLoyalty::is_pro() ) {
            $this->markTestSkipped( 'Lite build: Pro emails are not shipped.' );
        }

        foreach ( array_keys( self::EMAILS ) as $id ) {
            $this->snapshot_options( "woocommerce_{$id}_settings" );
            delete_option( "woocommerce_{$id}_settings" );
        }
    }

    protected function tearDown(): void {
        $this->restore_options();

        parent::tearDown();
    }

    public function test_each_pro_email_is_registered_under_its_id_and_group(): void {
        $emails = WC()->mailer()->get_emails();

        foreach ( self::EMAILS as $id => $expected ) {
            $this->assertArrayHasKey( $id, $emails );
            $this->assertInstanceOf( $expected['class'], $emails[ $id ] );
            $this->assertSame( $id, $emails[ $id ]->id );
            $this->assertSame( $expected['group'], $emails[ $id ]->email_group );
            $this->assertTrue( $emails[ $id ]->is_customer_email() );
        }
    }

    /** yayloyalty_points is Lite's group; Pro's reminder joins it rather than adding a second one. */
    public function test_each_pro_email_shows_its_group_title(): void {
        $emails = WC()->mailer()->get_emails();

        foreach ( self::EMAILS as $id => $expected ) {
            $this->assertSame( self::GROUP_TITLES[ $expected['group'] ], $emails[ $id ]->get_email_group_title(), $id );
        }
    }

    public function test_pro_and_lite_together_register_twelve_loyalty_emails(): void {
        $loyalty_emails = array_filter(
            WC()->mailer()->get_emails(),
            static function ( $email ) {
                return $email instanceof LoyaltyEmail;
            }
        );

        $this->assertCount( 12, $loyalty_emails );
    }

    public function test_only_tier_lost_is_off_by_default(): void {
        foreach ( self::EMAILS as $expected ) {
            $is_enabled_by_default = TierLostEmail::class !== $expected['class'];

            $this->assertSame( $is_enabled_by_default, ( new $expected['class']() )->is_enabled(), $expected['class'] );
        }
    }

    /** Heading in both proves the Pro template folder resolves; no tags proves plain is not the HTML one. */
    public function test_each_pro_email_renders_html_and_plain_from_the_pro_templates(): void {
        foreach ( self::EMAILS as $expected ) {
            $email = new $expected['class']();
            $plain = $email->get_content_plain();

            $this->assertStringContainsString( $email->get_default_heading(), $email->get_content_html(), $expected['class'] );
            $this->assertStringContainsString( $email->get_default_heading(), $plain, $expected['class'] );
            $this->assertStringNotContainsString( '<p>', $plain, $expected['class'] );
        }
    }
}
