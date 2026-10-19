<?php
namespace YayLoyalty\Tests\Integration\Emails;

use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\Birthday\BirthdayRunner;
use YayLoyalty\Pro\Emails\BirthdayPointsEmail;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** A birthday award sends the Birthday points email and never Points earned, which keeps every other award. */
final class BirthdayPointsEmailTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private const EMAIL_IDS = [ BirthdayPointsEmail::ID, PointsEarnedEmail::ID ];

    /** @var array<int, array<string, mixed>> wp_mail() arguments, in send order. */
    private $sent = [];

    /** @var string[] Ids of the WooCommerce emails sent, in send order. */
    private $sent_ids = [];

    protected function setUp(): void {
        parent::setUp();

        if ( ! class_exists( YayLoyaltyPro::class ) ) {
            $this->markTestSkipped( 'Pro is not booted on this site.' );
        }

        $this->require_woocommerce();

        // The fixture switches every WooCommerce email off and fails every send; this test sends these two.
        foreach ( self::EMAIL_IDS as $email_id ) {
            remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
        }

        remove_filter( 'pre_wp_mail', '__return_false' );
        add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
        add_action( 'woocommerce_email_sent', [ $this, 'record_email' ], 10, 2 );

        $this->pin_settings(
            [
                'points_label_singular' => 'Point',
                'points_label_plural'   => 'Points',
                'emails'                => [ 'throttle_per_week' => null ],
            ]
        );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
        remove_action( 'woocommerce_email_sent', [ $this, 'record_email' ], 10 );
        remove_filter( 'woocommerce_email_enabled_' . BirthdayPointsEmail::ID, '__return_false' );

        // cleanup_orders() removes the fixture's short-circuit, which setUp() already took off.
        add_filter( 'pre_wp_mail', '__return_false' );
        $this->cleanup_orders();

        parent::tearDown();
    }

    /**
     * Records the mail in place of the transport.
     *
     * @param mixed                $result
     * @param array<string, mixed> $atts
     */
    public function capture_mail( $result, array $atts ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter's signature.
        $this->sent[] = $atts;

        return true;
    }

    /** @param bool $result */
    public function record_email( $result, string $email_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the action's signature.
        $this->sent_ids[] = $email_id;
    }

    public function test_a_birthday_award_sends_the_birthday_email_only(): void {
        $this->award_birthday();

        $this->assertSame( [ BirthdayPointsEmail::ID ], $this->sent_ids );
        $this->assertCount( 1, $this->sent );
        $this->assertStringContainsString( 'Happy birthday', $this->sent[0]['subject'] );
        $this->assertStringContainsString( '150 Points', wp_strip_all_tags( $this->sent[0]['message'] ) );
    }

    public function test_a_disabled_birthday_email_sends_nothing(): void {
        add_filter( 'woocommerce_email_enabled_' . BirthdayPointsEmail::ID, '__return_false' );

        $this->award_birthday();

        $this->assertSame( [], $this->sent_ids );
    }

    public function test_points_earned_still_sends_for_other_awards(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE );
        $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

        $this->assertSame( [ PointsEarnedEmail::ID ], $this->sent_ids );
    }

    /** A customer with an email address and a birthday of June 15, paid 150 by a run on that day. */
    private function award_birthday(): int {
        $user_id = $this->customer_with_email();
        ( new CustomerRepository() )->set_birthday( $user_id, 6, 15 );

        ( new CampaignRepository() )->insert(
            new Campaign(
                null,
                'Birthday email test',
                'birthday-email-test',
                CampaignTrigger::BIRTHDAY,
                Status::ACTIVE,
                0,
                [
                    'points'      => 150,
                    'days_before' => 0,
                ],
                []
            )
        );

        ( new BirthdayRunner( new \DateTimeImmutable( '2026-06-15', wp_timezone() ) ) )->run();

        $this->assertSame( [ 150 ], $this->ledger_points( $user_id ) );

        return $user_id;
    }
}
