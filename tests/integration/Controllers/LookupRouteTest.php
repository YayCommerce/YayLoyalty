<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * The picker lookups answer by search term and by id, with the label a
 * merchant recognises, for each entity the condition editors name.
 */
final class LookupRouteTest extends IntegrationTestCase {

    private int $product_id = 0;

    private int $category_id = 0;

    private int $user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->act_as_admin();

        $product = new \WC_Product_Simple();
        $product->set_name( 'Lookup Test Enamel Mug' );
        $product->set_regular_price( '12' );
        $this->product_id = (int) $product->save();

        $term              = wp_insert_term( 'Lookup Test Drinkware', 'product_cat' );
        $this->category_id = is_wp_error( $term ) ? 0 : (int) $term['term_id'];

        $this->user_id = (int) wp_insert_user(
            [
                'user_login'   => 'lookup-test-member',
                'user_email'   => 'lookup-test-member@example.com',
                'user_pass'    => wp_generate_password(),
                'display_name' => 'Lookup Test Member',
                'role'         => 'customer',
            ]
        );
    }

    protected function tearDown(): void {
        if ( $this->product_id > 0 ) {
            wp_delete_post( $this->product_id, true );
        }

        if ( $this->category_id > 0 ) {
            wp_delete_term( $this->category_id, 'product_cat' );
        }

        if ( $this->user_id > 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $this->user_id );
        }

        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_products_are_found_by_name_and_labelled(): void {
        $items = $this->lookup( 'products', [ 'search' => 'Lookup Test Enamel' ] );

        $this->assertSame( [ $this->product_id ], array_column( $items, 'id' ) );
        $this->assertStringContainsString( 'Lookup Test Enamel Mug', $items[0]['label'] );
    }

    public function test_ids_resolve_to_labels_for_a_stored_selection(): void {
        $items = $this->lookup( 'products', [ 'ids' => (string) $this->product_id ] );

        $this->assertCount( 1, $items );
        $this->assertSame( $this->product_id, $items[0]['id'] );
        $this->assertStringContainsString( 'Lookup Test Enamel Mug', $items[0]['label'] );
    }

    public function test_categories_are_found_by_name(): void {
        $items = $this->lookup( 'categories', [ 'search' => 'Lookup Test Drink' ] );

        $this->assertSame(
            [
                [
                    'id'    => $this->category_id,
                    'label' => 'Lookup Test Drinkware',
                ],
            ],
            $items
        );
    }

    public function test_customers_are_found_by_email_fragment_and_labelled_with_it(): void {
        $items = $this->lookup( 'customers', [ 'search' => 'lookup-test-member@' ] );

        $this->assertSame( [ $this->user_id ], array_column( $items, 'id' ) );
        $this->assertSame( 'Lookup Test Member (lookup-test-member@example.com)', $items[0]['label'] );
    }

    public function test_an_unknown_entity_has_no_route(): void {
        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/lookups/orders' ) );

        $this->assertSame( 404, $response->get_status() );
    }

    /**
     * @param array<string, string> $params
     * @return array<int, array{id: int, label: string}>
     */
    private function lookup( string $entity, array $params ): array {
        $request = new \WP_REST_Request( 'GET', "/yayloyalty/v1/lookups/{$entity}" );
        $request->set_query_params( $params );

        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );

        return $response->get_data()['items'];
    }
}
