<?php
/**
 * Seeds the WordPress.org Live Preview (Playground) with a running loyalty program:
 * the setup wizard's choices, a Rewards page, and customers whose completed orders earned points.
 *
 * Playground can't fetch files from the plugin's SVN assets, so `./scripts/run.sh release lite`
 * inlines this file into blueprint.json's runPHP step and writes the result beside the Lite zip.
 *
 * @package yaycommerce/yayloyalty
 */

require_once '/wordpress/wp-load.php';

// The sandbox has no mail server, and every awarded order queues a points email.
add_filter( 'pre_wp_mail', '__return_true' );

// WooCommerce would otherwise send the landing page to WC Home.
delete_transient( '_wc_activation_redirect' );

wp_set_current_user( 1 );

// Through the wizard's own endpoint, so the values are validated and setup is marked done.
$yayloyalty_setup = new WP_REST_Request( 'POST', '/yayloyalty/v1/setup' );
$yayloyalty_setup->set_header( 'Content-Type', 'application/json' );
$yayloyalty_setup->set_body(
	wp_json_encode(
		array(
			'points_label_singular' => 'point',
			'points_label_plural'   => 'points',
			'purchase'              => array( 'points_per_unit' => 10 ),
			'signup'                => array(
				'enabled' => true,
				'points'  => 100,
			),
			'review'                => array(
				'enabled' => true,
				'points'  => 50,
			),
			'flexible_discount'     => array(
				'points_per_currency_unit'    => 100,
				'min_points'                  => 100,
				'max_percent_of_discountable' => 50,
			),
			'brand_color'           => '#7c3aed',
		)
	)
);
$yayloyalty_response = rest_do_request( $yayloyalty_setup );
if ( $yayloyalty_response->is_error() ) {
	error_log( 'YayLoyalty blueprint: setup failed: ' . wp_json_encode( $yayloyalty_response->get_data() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- shows in Playground's log when the seed breaks.
	// The wizard would otherwise cover every admin screen of the preview.
	update_option( 'yayloyalty_setup_completed', true );
}

// My Account hosts the My Points tab.
WC_Install::create_pages();

wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Rewards',
		'post_name'    => 'rewards',
		'post_content' => "<!-- wp:yayloyalty/points-balance /-->\n\n<!-- wp:yayloyalty/ways-to-earn /-->\n\n<!-- wp:yayloyalty/ways-to-redeem /-->",
	)
);

$yayloyalty_products = wc_get_products(
	array(
		'type'    => 'simple',
		'status'  => 'publish',
		'limit'   => 6,
		'orderby' => 'ID',
		'order'   => 'ASC',
	)
);
if ( ! $yayloyalty_products ) {
	return;
}

$yayloyalty_address = array(
	'address_1' => '123 Demo Street',
	'city'      => 'San Francisco',
	'state'     => 'CA',
	'postcode'  => '94103',
	'country'   => 'US',
);

// first name, last name, completed orders. User 1 is the admin the preview logs in as, given enough points to redeem at checkout.
$yayloyalty_customers = array(
	array( 'Emma', 'Wilson', 3 ),
	array( 'Liam', 'Chen', 2 ),
	array( 'Sofia', 'Rossi', 1 ),
	array( 'Noah', 'Williams', 4 ),
	array( 'Ava', 'Martin', 0 ),
	array( 'Demo', 'Admin', 2, 1 ),
);

$yayloyalty_line = 0;
foreach ( $yayloyalty_customers as $yayloyalty_customer ) {
	list( $yayloyalty_first, $yayloyalty_last, $yayloyalty_orders ) = $yayloyalty_customer;

	$yayloyalty_email   = strtolower( $yayloyalty_first . '.' . $yayloyalty_last ) . '@example.com';
	$yayloyalty_user_id = $yayloyalty_customer[3] ?? wp_insert_user(
		array(
			'user_login' => strtolower( $yayloyalty_first . $yayloyalty_last ),
			'user_pass'  => wp_generate_password(),
			'user_email' => $yayloyalty_email,
			'first_name' => $yayloyalty_first,
			'last_name'  => $yayloyalty_last,
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $yayloyalty_user_id ) ) {
		continue;
	}

	$yayloyalty_billing = $yayloyalty_address + array(
		'first_name' => $yayloyalty_first,
		'last_name'  => $yayloyalty_last,
		'email'      => $yayloyalty_email,
	);

	for ( $yayloyalty_i = 0; $yayloyalty_i < $yayloyalty_orders; $yayloyalty_i++ ) {
		$yayloyalty_order = wc_create_order( array( 'customer_id' => $yayloyalty_user_id ) );
		$yayloyalty_order->add_product( $yayloyalty_products[ $yayloyalty_line % count( $yayloyalty_products ) ], 2 );
		$yayloyalty_order->add_product( $yayloyalty_products[ ( $yayloyalty_line + 1 ) % count( $yayloyalty_products ) ], 1 );
		$yayloyalty_order->set_address( $yayloyalty_billing, 'billing' );
		$yayloyalty_order->set_address( $yayloyalty_billing, 'shipping' );
		$yayloyalty_order->set_payment_method( 'cod' );
		$yayloyalty_order->set_payment_method_title( 'Cash on delivery' );
		$yayloyalty_order->calculate_totals();
		$yayloyalty_order->update_status( 'completed' );
		++$yayloyalty_line;
	}
}
