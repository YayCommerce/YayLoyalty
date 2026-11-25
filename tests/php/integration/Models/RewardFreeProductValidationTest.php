<?php
namespace YayTests\Integration\Models;

use WP_UnitTestCase;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

/**
 * A free product reward can only name a product that goes in a cart as it is.
 *
 * @group P-11
 */
final class RewardFreeProductValidationTest extends WP_UnitTestCase {

	public function test_a_simple_product_is_accepted() {
		$this->assertNull( Reward::config_conflict( RewardType::FREE_PRODUCT, $this->config( new \WC_Product_Simple() ) ) );
	}

	public function test_a_variable_product_is_refused_for_a_free_product_reward() {
		$this->assertNotNull( Reward::config_conflict( RewardType::FREE_PRODUCT, $this->config( new \WC_Product_Variable() ) ) );
	}

	public function test_a_grouped_or_external_product_is_refused() {
		$this->assertNotNull( Reward::config_conflict( RewardType::FREE_PRODUCT, $this->config( new \WC_Product_Grouped() ) ) );
		$this->assertNotNull( Reward::config_conflict( RewardType::FREE_PRODUCT, $this->config( new \WC_Product_External() ) ) );
	}

	/** A config naming $product, saved and published. */
	private function config( \WC_Product $product ): array {
		$product->set_name( 'P11 product' );
		$product->set_status( 'publish' );
		$product->save();

		return [
			'product_id' => $product->get_id(),
			'qty'        => 1,
		];
	}
}
