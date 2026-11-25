<?php
namespace YayTests\Integration\Pro\Storefront;

use WP_UnitTestCase;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Pro\Storefront\RewardsPanel;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;

/**
 * "Ways to redeem": a member sees only the rewards whose conditions they meet, through the same conditions filter
 * the claim applies; a guest sees every reward, a conditional one with the one fixed line.
 *
 * @group P-12
 */
final class RewardsPanelTest extends WP_UnitTestCase {

	private const GUEST_LINE = 'For selected customers';

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( YayLoyaltyPro::class ) ) {
			$this->markTestSkipped( 'Pro is not booted on this site.' );
		}

		add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

		$tiers = new TierRepository();
		$tiers->seed_defaults( Dates::now() );
		$tiers->rerank();
	}

	public function tear_down() {
		remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
		parent::tear_down();
	}

	/** @return array<string, mixed> */
	public function pinned_settings(): array {
		return [
			'schema_version' => SettingsHelper::SCHEMA_VERSION,
			'tiers_enabled'  => true,
		];
	}

	public function test_member_sees_only_rewards_whose_conditions_hold() {
		$member  = $this->customer( 'Bronze', 600 );
		$other   = $this->customer( 'Bronze' );
		$open    = $this->reward( 'Open', [] );
		$admins  = $this->reward( 'Admins', [ $this->condition( 'user_role', 'in', [ 'administrator' ] ) ] );
		$listed  = $this->reward( 'Listed', [ $this->condition( 'customer_ids', 'in', [ $member ] ) ] );
		$veteran = $this->reward( 'Veteran', [ $this->condition( 'lifetime_points', 'gte', 500 ) ] );

		$for_member = $this->names( $member );
		$for_other  = $this->names( $other );

		$this->assertSame( [ $open, $listed, $veteran ], $for_member );
		$this->assertSame( [ $open ], $for_other );
		$this->assertNotContains( $admins, $for_member );
	}

	public function test_tier_gated_reward_follows_member_tier() {
		$silver = $this->reward( 'Silver up', [ $this->condition( 'tier', 'gte', $this->tier_id( 'Silver' ) ) ] );

		$this->assertSame( [ $silver ], $this->names( $this->customer( 'Gold' ) ) );
		$this->assertSame( [], $this->names( $this->customer( 'Bronze' ) ) );
	}

	public function test_guest_sees_conditional_rewards_labelled() {
		$open  = $this->reward( 'Open', [] );
		$gated = $this->reward( 'Gated', [ $this->condition( 'user_role', 'in', [ 'administrator' ] ) ] );

		$first  = $this->offers( null );
		$second = $this->offers( null );
		$texts  = array_column( $first, 'text', 'name' );

		$this->assertSame( [ $open, $gated ], array_column( $first, 'name' ) );
		$this->assertSame( self::GUEST_LINE, $texts[ $gated ] );
		$this->assertNotSame( self::GUEST_LINE, $texts[ $open ] );
		$this->assertSame( $first, $second );
	}

	/**
	 * This test's rows only, so rewards other tests left behind can't move the assertions.
	 *
	 * @return array<int, array{name: string, cost: int, text: string}>
	 */
	private function offers( ?int $user_id ): array {
		// A minute ahead, so the seeded points count as earned before it.
		$offers = ( new RewardsPanel() )->redeem_offers( [], Dates::offset( MINUTE_IN_SECONDS ), $user_id );

		return array_values(
			array_filter(
				$offers,
				static function ( array $offer ): bool {
					return 0 === strpos( $offer['name'], 'P12 ' );
				}
			)
		);
	}

	/** @return string[] */
	private function names( int $user_id ): array {
		return array_column( $this->offers( $user_id ), 'name' );
	}

	/** @return array{type: string, operator: string, value: mixed} */
	private function condition( string $type, string $operator, $value ): array {
		return [
			'type'     => $type,
			'operator' => $operator,
			'value'    => $value,
		];
	}

	/**
	 * A live 100-point fixed_coupon with $conditions, as a stored row; returns its name.
	 *
	 * @param array<int, array<string, mixed>> $conditions
	 */
	private function reward( string $name, array $conditions ): string {
		$now     = Dates::now();
		$label   = 'P12 ' . $name;
		$rewards = new RewardRepository();
		$rewards->insert( new Reward( null, $label, 'p12-' . wp_generate_password( 8, false ), RewardType::FIXED_COUPON, Status::ACTIVE, 0, 100, [ 'currency' => get_woocommerce_currency(), 'amount' => 5 ], $conditions, null, null, false, null, null, $now, $now ) );

		return $label;
	}

	/** A customer holding the named tier, with $earned points earned. */
	private function customer( string $tier, int $earned = 0 ): int {
		// Created by an admin, so the built-in signup campaign (self-registration only) awards nothing.
		wp_set_current_user( 1 );
		$user_id   = self::factory()->user->create( [ 'role' => 'customer' ] );
		$customers = new CustomerRepository();
		$customers->ensure_row( $user_id );

		if ( $earned > 0 ) {
			( new LedgerService( new LedgerRepository(), $customers, new CampaignRepository() ) )->adjust( $user_id, $earned, 'seed', 1 );
		}

		$customers->set_tier_state( $user_id, [ 'tier_id' => $this->tier_id( $tier ) ] );

		return $user_id;
	}

	private function tier_id( string $name ): int {
		foreach ( ( new TierRepository() )->live() as $tier ) {
			if ( $name === $tier->get_name() ) {
				return (int) $tier->get_id();
			}
		}

		$this->fail( 'No live tier named ' . $name );
	}
}
