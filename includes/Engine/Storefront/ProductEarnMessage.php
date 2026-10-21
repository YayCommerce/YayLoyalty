<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\EarningService;
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

    private const STYLE_PATH = 'assets/storefront/product-earn.css';

    /** @var EarningService|null */
    private static $service;

    /** @var PointsEngine|null */
    private static $engine;

    /** @var array|null DesignHelper::get_design(), read once per request. */
    private static $design;

    /** @var array<int, string> Sentences as escaped HTML by product id, for this request. */
    private static $sentences = [];

    /** @var int|null|false EarningService::points_left_today() for the member, read once per request; false until read. */
    private static $left_today = false;

    public static function register(): void {
        add_action( 'woocommerce_single_product_summary', [ self::class, 'render_summary' ], 15 );
        add_filter( 'woocommerce_blocks_hook_compatibility_additional_data', [ self::class, 'unhook_in_block_templates' ] );
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

    /**
     * Block templates replay woocommerce_single_product_summary for classic plugins; the price block already carries the line there.
     *
     * @param array $hooks Callbacks WooCommerce removes in its blockified templates.
     */
    public static function unhook_in_block_templates( $hooks ): array {
        $hooks   = is_array( $hooks ) ? $hooks : [];
        $hooks[] = [
            'hook'     => 'woocommerce_single_product_summary',
            'function' => [ self::class, 'render_summary' ],
            'priority' => 15,
        ];

        return $hooks;
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

    /** The stylesheet on a product page whose line shows; the script only when that product is variable. */
    public static function enqueue(): void {
        if ( ! is_product() ) {
            return;
        }

        $product = wc_get_product( get_queried_object_id() );

        if ( ! $product || '' === self::sentence( $product ) ) {
            return;
        }

        wp_enqueue_style( ScriptName::PRODUCT_EARN_STYLE, YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH, [], Launcher::asset_version( self::STYLE_PATH ) );

        if ( ! $product->is_type( 'variable' ) ) {
            return;
        }

        wp_enqueue_script( ScriptName::PRODUCT_EARN_SCRIPT, YAYLOYALTY_PLUGIN_URL . self::SCRIPT_PATH, [ 'jquery' ], Launcher::asset_version( self::SCRIPT_PATH ), [ 'in_footer' => true ] );
    }

    /**
     * The filled sentence for one of this product at its current price, as escaped HTML with the points in <strong>, or '' when nothing should show.
     * A variable product speaks for its cheapest variation that earns, until the shopper picks one.
     */
    public static function sentence( \WC_Product $product ): string {
        if ( ! DesignHelper::surface_enabled( self::design(), 'product_message' ) ) {
            return '';
        }

        $id = $product->get_id();

        if ( ! isset( self::$sentences[ $id ] ) ) {
            self::$sentences[ $id ] = $product->is_type( 'variable' ) ? self::cheapest_variation_sentence( $product ) : self::compute( $product );
        }

        return self::$sentences[ $id ];
    }

    /** WooCommerce keeps variation prices cached and sorted cheapest first. */
    private static function cheapest_variation_sentence( \WC_Product_Variable $product ): string {
        foreach ( array_keys( $product->get_variation_prices( true )['price'] ) as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            $sentence  = $variation ? self::sentence( $variation ) : '';

            if ( '' !== $sentence ) {
                return $sentence;
            }
        }

        return '';
    }

    private static function compute( \WC_Product $product ): string {
        if ( $product->is_type( [ 'grouped', 'external' ] ) || '' === $product->get_price() ) {
            return '';
        }

        $points = get_current_user_id() > 0 ? self::member_points( $product ) : self::guest_points( $product );

        /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
        return $points > 0 ? LauncherCopy::points_sentence_html( __( 'Earn {points} {points_label} with this purchase', 'yayloyalty' ), $points ) : '';
    }

    /** What one of this product earns a guest, at the base rate: no tier multiplier, since that belongs to members. 0 on failure. */
    public static function guest_points( \WC_Product $product ): int {
        try {
            return self::engine()->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product ) )->get_points();
        } catch ( \Throwable $e ) {
            // A broken estimate must never take the product page down with it.
            Log::warning( 'ProductEarnMessage', 'guest estimate failed: ' . $e->getMessage() );

            return 0;
        }
    }

    /** What one of this product earns the member, no more than the daily limit leaves today. 0 on failure. */
    private static function member_points( \WC_Product $product ): int {
        try {
            if ( false === self::$left_today ) {
                self::$left_today = EarningService::points_left_today( get_current_user_id() );
            }

            if ( 0 === self::$left_today ) {
                return 0;
            }

            $points = self::service()->preview( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product, 1, get_current_user_id() ) )->get_points();

            return null === self::$left_today ? $points : min( $points, self::$left_today );
        } catch ( \Throwable $e ) {
            Log::warning( 'ProductEarnMessage', 'member estimate failed: ' . $e->getMessage() );

            return 0;
        }
    }

    private static function design(): array {
        self::$design = self::$design ?? DesignHelper::get_design();

        return self::$design;
    }

    private static function service(): EarningService {
        self::$service = self::$service ?? EarningService::build();

        return self::$service;
    }

    private static function engine(): PointsEngine {
        self::$engine = self::$engine ?? PointsEngine::build( new CampaignRepository() );

        return self::$engine;
    }

    /** Styled by assets/storefront/product-earn.css; $sentence is sentence()'s escaped HTML. */
    private static function markup( string $sentence ): string {
        if ( '' === $sentence ) {
            return '';
        }

        $guest = 0 === get_current_user_id() ? self::guest_line() : '';

        return sprintf(
            '<div class="%1$s" data-default="%2$s"><p class="%1$s__line"><span class="%1$s__icon">%3$s</span><span class="%1$s__text">%4$s</span></p>%5$s</div>',
            esc_attr( self::CSS_CLASS ),
            esc_attr( $sentence ),
            AccountRewardsIcon::get( 'gift' ),
            $sentence,
            $guest
        );
    }

    /** "Sign in to earn Points, or join free & get 100 Points.", the clause only while signup pays. */
    private static function guest_line(): string {
        $sign_in = sprintf( '<a href="%s">%s</a>', esc_url( (string) wc_get_page_permalink( 'myaccount' ) ), esc_html__( 'Sign in', 'yayloyalty' ) );
        $label   = esc_html( SettingsHelper::points_label_plural() );
        $route   = LauncherPayload::register_route();
        $signup  = null === $route ? null : LauncherEarnRules::advertise( self::engine(), ContextFactory::for_advert( Dates::now() ), true )['earn'][ CampaignTrigger::SIGNUP ];

        if ( null === $signup ) {
            /* translators: 1: "Sign in" link, 2: the store's points label, as "Points". */
            $line = sprintf( esc_html__( '%1$s to earn %2$s.', 'yayloyalty' ), $sign_in, $label );
        } else {
            $join = 'checkout' === $route
                /* translators: %s: points with the store's label, as "100 Points". */
                ? sprintf( __( 'join free at checkout & get %s', 'yayloyalty' ), SettingsHelper::points_text( $signup ) )
                /* translators: %s: points with the store's label, as "100 Points". */
                : sprintf( __( 'join free & get %s', 'yayloyalty' ), SettingsHelper::points_text( $signup ) );
            $join = sprintf( '<a href="%s">%s</a>', esc_url( LauncherPayload::register_url( $route ) ), esc_html( $join ) );

            /* translators: 1: "Sign in" link, 2: the store's points label, as "Points", 3: "join free & get 100 Points" link. */
            $line = sprintf( esc_html__( '%1$s to earn %2$s, or %3$s.', 'yayloyalty' ), $sign_in, $label, $join );
        }

        return sprintf( '<p class="%s__guest">%s</p>', esc_attr( self::CSS_CLASS ), $line );
    }
}
