<?php
/**
 * Seeds the wp-env store the specs expect; safe to rerun. Run through `wp eval-file` by setup.sh.
 */

use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\SetupService;

wp_set_current_user( 1 );

// The setup wizard's defaults, with signup and review switched on.
$setup = new SetupService( new CampaignRepository(), new RewardRepository() );
$input = [
    'points_label_singular' => 'Point',
    'points_label_plural'   => 'Points',
    'purchase'              => [ 'points_per_unit' => 1 ],
    'signup'                => [
        'enabled' => true,
        'points'  => 100,
    ],
    'review'                => [
        'enabled' => true,
        'points'  => 50,
    ],
    'cart_discount'         => [
        'points_per_currency_unit' => 100,
        'min_points'               => 100,
    ],
    'brand_color'           => '#3858e9',
];
$error = $setup->validation_error( $input );

if ( null !== $error ) {
    WP_CLI::error( $error );
}

$setup->complete( $input );

foreach ( [ 'e2e-widget' => [ 'E2E Widget', '20' ], 'e2e-gadget' => [ 'E2E Gadget', '10' ] ] as $sku => list( $name, $price ) ) {
    $product_id = wc_get_product_id_by_sku( $sku );
    $product    = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();
    $product->set_name( $name );
    $product->set_slug( $sku );
    $product->set_sku( $sku );
    $product->set_regular_price( $price );
    $product->set_status( 'publish' );
    $product->set_reviews_allowed( true );
    $product->save();
}

// A role the gate spec excludes from earning.
if ( null === get_role( 'e2e_excluded' ) ) {
    add_role( 'e2e_excluded', 'E2E excluded', [ 'read' => true ] );
}

WP_CLI::success( 'YayLoyalty seeded.' );
