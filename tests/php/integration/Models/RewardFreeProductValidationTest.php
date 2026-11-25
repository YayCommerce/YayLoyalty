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

	public function test_a_variable_product_needs_one_of_its_own_variations_with_every_attribute_set() {
		$colour = new \WC_Product_Attribute();
		$colour->set_name( 'colour' );
		$colour->set_options( [ 'red', 'blue' ] );
		$colour->set_variation( true );
		$parent = new \WC_Product_Variable();
		$parent->set_attributes( [ $colour ] );
		$config = $this->config( $parent );
		$other  = $this->config( new \WC_Product_Variable() );

		$this->assertNull( Reward::config_conflict( RewardType::FREE_PRODUCT, $config + [ 'variation_id' => $this->variation( $parent->get_id(), 'red' ) ] ) );
		$this->assertNotNull( Reward::config_conflict( RewardType::FREE_PRODUCT, $config + [ 'variation_id' => $this->variation( $parent->get_id(), '' ) ] ) );
		$this->assertNotNull( Reward::config_conflict( RewardType::FREE_PRODUCT, $other + [ 'variation_id' => $this->variation( $parent->get_id(), 'red' ) ] ) );
	}

	/** A saved variation of $parent_id with its colour set to $colour; '' is "Any colour". */
	private function variation( int $parent_id, string $colour ): int {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'colour' => $colour ] );
		$variation->set_regular_price( '9' );
		$variation->save();

		return $variation->get_id();
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
