<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * "Earn 20 Points with this purchase" under the price on a single product page.
 * A member's figure goes through the earning gates; a guest's is the base rate, so cached pages hold nothing personal.
 */
final class ProductEarnMessage {

    public const CSS_CLASS = 'yayloyalty-product-earn';

    private const SCRIPT_PATH = 'assets/storefront/product-earn.js';

    /** @var EarningService|null */
    private static $service;

    /** @var PointsEngine|null */
    private static $engine;

    /** @var bool */
    private static $printed = false;

    /** @var array<int, string> Sentences by product id, for this request. */
    private static $sentences = [];

    public static function register(): void {
        add_action( 'woocommerce_single_product_summary', [ self::class, 'render_summary' ], 15 );
        add_filter( 'render_block_woocommerce/product-price', [ self::class, 'append_to_price_block' ], 10, 3 );
        add_filter( 'woocommerce_available_variation', [ self::class, 'add_variation_sentence' ], 10, 3 );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    /** Classic themes: between the price (10) and the excerpt (20). */
    public static function render_summary(): void {
        global $product;

        if ( $product instanceof \WC_Product ) {
            echo self::markup( self::sentence( $product ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes every part.
        }
    }

    /** Block themes: only the main product's own price, never a related-products or collection card. */
    public static function append_to_price_block( string $content, array $block, \WP_Block $instance ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter passes $block before $instance.
        if ( ! is_product() || (int) ( $instance->context['postId'] ?? 0 ) !== get_queried_object_id() ) {
            return $content;
        }

        $product = wc_get_product( get_queried_object_id() );

        return $product ? $content . self::markup( self::sentence( $product ) ) : $content;
    }

    /** Each variation's sentence in WooCommerce's variation data, which product-earn.js swaps in when it is picked. */
    public static function add_variation_sentence( array $data, \WC_Product $product, \WC_Product_Variation $variation ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter passes the parent before the variation.
        $data['yayloyalty_earn'] = self::sentence( $variation );

        return $data;
    }

    /** Only on a variable product page whose line shows. */
    public static function enqueue(): void {
        if ( ! is_product() ) {
            return;
        }

        $product = wc_get_product( get_queried_object_id() );

        if ( ! $product || ! $product->is_type( 'variable' ) || '' === self::sentence( $product ) ) {
            return;
        }

        wp_enqueue_script( ScriptName::PRODUCT_EARN_SCRIPT, YAYLOYALTY_PLUGIN_URL . self::SCRIPT_PATH, [ 'jquery' ], Launcher::asset_version( self::SCRIPT_PATH ), [ 'in_footer' => true ] );
    }

    /**
     * The filled sentence for one of this product at its current price, or '' when nothing should show.
     * A variable product speaks for its cheapest variation that earns, until the shopper picks one.
     */
    public static function sentence( \WC_Product $product ): string {
        $id = (int) $product->get_id();

        if ( ! isset( self::$sentences[ $id ] ) ) {
            self::$sentences[ $id ] = $product->is_type( 'variable' ) ? self::cheapest_variation_sentence( $product ) : self::compute( $product );
        }

        return self::$sentences[ $id ];
    }

    private static function cheapest_variation_sentence( \WC_Product $product ): string {
        $variations = array_filter(
            $product->get_available_variations( 'objects' ),
            function ( \WC_Product $variation ): bool {
                return '' !== $variation->get_price();
            }
        );

        usort(
            $variations,
            function ( \WC_Product $a, \WC_Product $b ): int {
                return (float) $a->get_price() <=> (float) $b->get_price();
            }
        );

        foreach ( $variations as $variation ) {
            $sentence = self::sentence( $variation );

            if ( '' !== $sentence ) {
                return $sentence;
            }
        }

        return '';
    }

    private static function compute( \WC_Product $product ): string {
        if ( ! ProgramGate::program_enabled() || ! self::enabled() || $product->is_type( [ 'grouped', 'external' ] ) || '' === $product->get_price() ) {
            return '';
        }

        try {
            $points = self::points( $product );
        } catch ( \Throwable $e ) {
            // A broken estimate must never take the product page down with it.
            Log::warning( 'ProductEarnMessage', 'estimate failed: ' . $e->getMessage() );

            return '';
        }

        // The raw design value: storefront_text() would fill {points_label} with the plural before the number is known.
        return $points > 0 ? LauncherCopy::product_earn( DesignHelper::get_design()['storefront_text']['product_earn_line'], $points ) : '';
    }

    private static function enabled(): bool {
        return ! empty( DesignHelper::get_design()['visibility']['product_message'] );
    }

    private static function points( \WC_Product $product ): int {
        $user_id = get_current_user_id();

        if ( $user_id > 0 ) {
            if ( null === self::$service ) {
                self::$service = EarningService::build();
            }

            return self::$service->preview( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product, 1, $user_id ) )->get_points();
        }

        return self::engine()->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product ) )->get_points();
    }

    private static function engine(): PointsEngine {
        if ( null === self::$engine ) {
            self::$engine = PointsEngine::build( new CampaignRepository() );
        }

        return self::$engine;
    }

    private static function markup( string $sentence ): string {
        // WooCommerce filters the price block's output more than once per render; the line goes in once.
        if ( '' === $sentence || self::$printed ) {
            return '';
        }

        self::$printed = true;

        return sprintf(
            '<div class="%1$s" data-default="%2$s" style="margin:0.5em 0 1em"><p class="%1$s__line" style="margin:0">%3$s</p>%4$s</div>',
            esc_attr( self::CSS_CLASS ),
            esc_attr( $sentence ),
            esc_html( $sentence ),
            0 === get_current_user_id() ? self::guest_line() : ''
        );
    }

    /** "Sign in to start earning — or create an account and get 100 Points", the clause only while signup pays. */
    private static function guest_line(): string {
        $sign_in = sprintf( '<a href="%s">%s</a>', esc_url( (string) wc_get_page_permalink( 'myaccount' ) ), esc_html__( 'Sign in to start earning', 'yayloyalty' ) );
        $route   = LauncherPayload::register_route();
        $signup  = null === $route ? null : self::signup_points();

        if ( null === $signup ) {
            return sprintf( '<p class="%s__guest" style="margin:0">%s</p>', esc_attr( self::CSS_CLASS ), $sign_in );
        }

        $register_url = 'checkout' === $route ? wc_get_checkout_url() : wc_get_page_permalink( 'myaccount' );
        /* translators: %s: points with the store's label, as "100 Points". */
        $register = sprintf( '<a href="%s">%s</a>', esc_url( (string) $register_url ), esc_html( sprintf( __( 'create an account and get %s', 'yayloyalty' ), SettingsHelper::points_text( $signup ) ) ) );

        /* translators: 1: "Sign in to start earning" link, 2: "create an account and get 100 Points" link. */
        return sprintf( '<p class="%s__guest" style="margin:0">%s</p>', esc_attr( self::CSS_CLASS ), sprintf( esc_html__( '%1$s — or %2$s', 'yayloyalty' ), $sign_in, $register ) );
    }

    private static function signup_points(): ?int {
        try {
            $advert = self::engine()->advertised( CampaignTrigger::SIGNUP, ContextFactory::for_advert( Dates::now() ) );
        } catch ( \Throwable $e ) {
            Log::warning( 'ProductEarnMessage', 'signup row failed: ' . $e->getMessage() );

            return null;
        }

        return null === $advert ? null : (int) $advert['value'];
    }
}
