<?php
namespace YayTests\Integration\Pro\Social;

use WP_UnitTestCase;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\Social\SocialClaim;
use YayLoyalty\Pro\Social\SocialEarnRow;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;

/**
 * A social claim pays once per customer per campaign, only with a valid nonce, and always sends the
 * customer to the campaign's stored URL.
 *
 * @group P-7
 */
final class SocialClaimTest extends WP_UnitTestCase {

	private const URL = 'https://instagram.com/yay-shop';

	/** @var int */
	private $user_id;

	/** @var int */
	private $campaign_id;

	/** @var int The new customer's balance before any claim: the signup campaign may already have paid. */
	private $before;

	public function set_up() {
		parent::set_up();
		SettingsHelper::update_settings( [ 'max_points_per_day' => null ] );
		$this->user_id     = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->campaign_id = $this->campaign( Status::ACTIVE );
		wp_set_current_user( $this->user_id );
		$this->before = $this->balance();
	}

	public function test_a_claim_pays_once_and_redirects_to_the_stored_url() {
		$this->assertSame( self::URL, $this->claim() );
		$this->assertSame( self::URL, $this->claim() );

		$this->assertSame( 20, $this->balance() );
	}

	public function test_a_bad_nonce_pays_nothing_but_still_redirects() {
		$this->assertSame( self::URL, SocialClaim::claim( $this->campaign_id, 'not-a-nonce', $this->user_id ) );

		$this->assertSame( 0, $this->balance() );
	}

	public function test_a_guest_earns_nothing() {
		$this->assertSame( self::URL, SocialClaim::claim( $this->campaign_id, wp_create_nonce( 'yayloyalty_social_' . $this->campaign_id ), 0 ) );

		$this->assertSame( 0, $this->balance() );
	}

	public function test_an_opted_out_customer_earns_nothing() {
		update_user_meta( $this->user_id, ProgramGate::META_OPTED_OUT, 1 );

		$this->claim();

		$this->assertSame( 0, $this->balance() );
	}

	public function test_a_paused_campaign_pays_nothing_and_goes_home() {
		$paused = $this->campaign( Status::PAUSED );

		$this->assertSame( home_url( '/' ), SocialClaim::claim( $paused, wp_create_nonce( 'yayloyalty_social_' . $paused ), $this->user_id ) );
		$this->assertSame( 0, $this->balance() );
	}

	public function test_the_daily_limit_clamps_the_claim() {
		SettingsHelper::update_settings( [ 'max_points_per_day' => $this->before + 5 ] );

		$this->claim();

		$this->assertSame( 5, $this->balance() );
	}

	public function test_the_row_links_the_claim_until_it_is_earned() {
		$row = new SocialEarnRow();

		$before = $this->own_row( $row->add_row( [], [], $this->user_id ) );
		$this->assertStringContainsString( SocialClaim::QUERY_ARG . '=' . $this->campaign_id, $before['url'] );
		$this->assertSame( 'Follow us on Instagram', $before['label'] );

		$this->claim();

		$after = $this->own_row( $row->add_row( [], [], $this->user_id ) );
		$this->assertSame( self::URL, $after['url'] );
		$this->assertSame( 'Earned', $after['description'] );

		$this->assertSame( self::URL, $this->own_row( $row->add_row( [], [], 0 ) )['url'] );
	}

	private function claim(): string {
		return SocialClaim::claim( $this->campaign_id, wp_create_nonce( 'yayloyalty_social_' . $this->campaign_id ), $this->user_id );
	}

	/** @param array[] $rows */
	private function own_row( array $rows ): array {
		$this->assertCount( 1, $rows );
		$this->assertSame( CampaignTrigger::SOCIAL, $rows[0]['trigger'] );

		return $rows[0];
	}

	private function balance(): int {
		$customer = ( new CustomerRepository() )->find_by_user_id( $this->user_id );

		return ( null === $customer ? 0 : $customer->get_balance() ) - (int) $this->before;
	}

	private function campaign( string $status ): int {
		$now = Dates::now();

		return ( new CampaignRepository() )->insert(
			new Campaign(
				null,
				'Follow ' . $status,
				'follow-' . $status . '-' . wp_rand(),
				CampaignTrigger::SOCIAL,
				$status,
				0,
				[
					'network' => 'instagram',
					'action'  => 'follow',
					'url'     => self::URL,
					'points'  => 20,
				],
				[],
				null,
				null,
				false,
				null,
				$now,
				$now
			)
		);
	}
}
