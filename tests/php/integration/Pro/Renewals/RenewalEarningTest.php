<?php
namespace YayTests\Integration\Pro\Renewals;

use WP_UnitTestCase;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

require_once __DIR__ . '/wcs-renewal-stub.php';

/**
 * A renewal order earns its campaign's flat points once, on top of or instead of order points, and a
 * refund claws them back. WooCommerce Subscriptions isn't installed here: wcs-renewal-stub.php stands in for it.
 *
 * @group P-7
 */
final class RenewalEarningTest extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	public function set_up() {
		parent::set_up();
		SettingsHelper::update_settings( [ 'max_points_per_day' => null ] );
		$this->user_id = self::factory()->user->create( [ 'role' => 'customer' ] );
	}

	public function test_a_renewal_earns_once_on_top_of_order_points() {
		$this->campaign( 50, false );
		$order_id = $this->order( true );

		wc_get_order( $order_id )->update_status( 'processing' );
		wc_get_order( $order_id )->update_status( 'completed' );

		$this->assertSame( 50, $this->row( $order_id, LedgerSource::RENEWAL )->get_points() );
		$this->assertSame( 60, $this->row( $order_id, LedgerSource::ORDER )->get_points() );
		$this->assertCount( 2, ( new LedgerRepository() )->earn_rows_for_order( $order_id ) );
	}

	public function test_replace_purchase_drops_order_points() {
		$this->campaign( 50, true );
		$order_id = $this->order( true );

		$this->assertSame( 50, $this->row( $order_id, LedgerSource::RENEWAL )->get_points() );
		$this->assertNull( $this->row( $order_id, LedgerSource::ORDER ) );
	}

	public function test_replace_purchase_keeps_order_points_when_the_campaign_pays_nothing() {
		$this->campaign( 0, true );
		$order_id = $this->order( true );

		$this->assertNull( $this->row( $order_id, LedgerSource::RENEWAL ) );
		$this->assertSame( 60, $this->row( $order_id, LedgerSource::ORDER )->get_points() );
	}

	public function test_a_non_renewal_order_earns_as_before() {
		$this->campaign( 50, true );
		$order_id = $this->order( false );

		$this->assertNull( $this->row( $order_id, LedgerSource::RENEWAL ) );
		$this->assertSame( 60, $this->row( $order_id, LedgerSource::ORDER )->get_points() );
	}

	public function test_a_full_refund_claws_back_the_renewal_points() {
		$this->campaign( 50, false );
		$order_id = $this->order( true );

		$this->refund( $order_id, (float) wc_get_order( $order_id )->get_total() );

		$ledger = new LedgerRepository();
		$this->assertSame( 50, $ledger->sum_refunded_for( $this->row( $order_id, LedgerSource::RENEWAL ), false ) );
	}

	public function test_a_partial_refund_claws_back_pro_rata() {
		$this->campaign( 50, false );
		$order_id = $this->order( true );

		$this->refund( $order_id, 30.0 );

		$ledger = new LedgerRepository();
		$this->assertSame( 25, $ledger->sum_refunded_for( $this->row( $order_id, LedgerSource::RENEWAL ), false ) );
	}

	private function row( int $order_id, string $source ): ?LedgerEntry {
		foreach ( ( new LedgerRepository() )->earn_rows_for_order( $order_id ) as $row ) {
			if ( $source === $row->get_source() ) {
				return $row;
			}
		}

		return null;
	}

	/** Refunds $amount off the order's one line, so the clawback has a refunded base to scale by. */
	private function refund( int $order_id, float $amount ): void {
		$items = wc_get_order( $order_id )->get_items();

		wc_create_refund(
			[
				'order_id'   => $order_id,
				'amount'     => $amount,
				'line_items' => [
					(int) key( $items ) => [
						'qty'          => 0,
						'refund_total' => $amount,
					],
				],
			]
		);
	}

	/** A completed $60 order for the customer; a renewal one carries WooCommerce Subscriptions' renewal meta. */
	private function order( bool $is_renewal ): int {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '60' );
		$product->save();

		$order = wc_create_order( [ 'customer_id' => $this->user_id ] );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_date_created( time() - MINUTE_IN_SECONDS );

		if ( $is_renewal ) {
			$order->update_meta_data( '_subscription_renewal', 123 );
		}

		$order->save();
		$order->update_status( 'completed' );

		return (int) $order->get_id();
	}

	private function campaign( int $points, bool $replace_purchase ): int {
		$now = Dates::now();

		return ( new CampaignRepository() )->insert(
			new Campaign(
				null,
				'Renewal',
				'renewal-' . wp_rand(),
				CampaignTrigger::RENEWAL,
				Status::ACTIVE,
				0,
				[
					'points'           => $points,
					'replace_purchase' => $replace_purchase,
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
