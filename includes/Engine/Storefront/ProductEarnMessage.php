<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * "Earn 20 Points with this purchase" under the price on a single product page, and under it the bonuses this
 * product could earn more from ("Earn 40 Points for VIP members", OfferLines).
 * A member's figure goes through the earning gates; a guest's is what campaigns pay any guest, so cached pages hold nothing personal.
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

    /** @var array<int, array{sentence: string, bonuses: string}> lines() by product id, for this request. */
    private static $lines = [];

    /** @var Context|null The advert the bonuses are read at, once per request. */
    private static $advert;

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
            echo self::markup( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes every part.
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

        return $product ? $content . self::markup( $product ) : $content;
    }

    /** Each variation's sentence in WooCommerce's variation data, which product-earn.js swaps in when it is picked. */
    public static function add_variation_sentence( array $data, \WC_Product $product, \WC_Product_Variation $variation ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter passes the parent before the variation.
        $data['yayloyalty_earn']         = self::sentence( $variation );
        $data['yayloyalty_earn_bonuses'] = self::bonus_lines( $variation );

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
        return self::lines( $product )['sentence'];
    }

    /**
     * The bonus lines under sentence(), as escaped HTML paragraphs, or '' with none: the bonuses this product would
     * pay more than the sentence's figure. A variable product's are its cheapest earning variation's.
     */
    public static function bonus_lines( \WC_Product $product ): string {
        return self::lines( $product )['bonuses'];
    }

    /** @return array{sentence: string, bonuses: string} */
    private static function lines( \WC_Product $product ): array {
        if ( ! DesignHelper::surface_enabled( self::design(), 'show_product_message' ) ) {
            return [
                'sentence' => '',
                'bonuses'  => '',
            ];
        }

        $id = $product->get_id();

        if ( ! isset( self::$lines[ $id ] ) ) {
            self::$lines[ $id ] = $product->is_type( 'variable' ) ? self::cheapest_variation_lines( $product ) : self::compute( $product );
        }

        return self::$lines[ $id ];
    }

    /**
     * WooCommerce keeps variation prices cached and sorted cheapest first.
     *
     * @return array{sentence: string, bonuses: string}
     */
    private static function cheapest_variation_lines( \WC_Product_Variable $product ): array {
        foreach ( array_keys( $product->get_variation_prices( true )['price'] ) as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            $lines     = $variation ? self::lines( $variation ) : null;

            if ( null !== $lines && '' !== $lines['sentence'] ) {
                return $lines;
            }
        }

        return [
            'sentence' => '',
            'bonuses'  => '',
        ];
    }

    /** @return array{sentence: string, bonuses: string} */
    private static function compute( \WC_Product $product ): array {
        $none = [
            'sentence' => '',
            'bonuses'  => '',
        ];

        if ( $product->is_type( [ 'grouped', 'external' ] ) || '' === $product->get_price() ) {
            return $none;
        }

        $points = get_current_user_id() > 0 ? self::member_points( $product ) : self::guest_points( $product );

        if ( $points <= 0 ) {
            return $none;
        }

        return [
            /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
            'sentence' => LauncherCopy::points_sentence_html( __( 'Earn {points} {points_label} with this purchase', 'yayloyalty' ), $points ),
            'bonuses'  => self::bonuses( $product, $points ),
        ];
    }

    /**
     * Each bonus that pays more than $figure on one of this product, as a paragraph. The viewer is the product's own
     * Context, for a guest too: a bonus whose product or category condition this line fails is dropped, since
     * buying it can't earn that. A guest's Context carries no customer, so it depends on nothing personal.
     */
    private static function bonuses( \WC_Product $product, int $figure ): string {
        try {
            $user_id = get_current_user_id();
            $viewer  = ContextFactory::from_product( $product, 1, $user_id > 0 ? $user_id : null );
            $lines   = '';

            foreach ( OfferLines::for_trigger( self::engine(), CampaignTrigger::PURCHASE, self::advert(), $viewer )['bonuses'] as $bonus ) {
                if ( self::fails_on_this_product( $bonus['results'] ?? [] ) ) {
                    continue;
                }

                $points = self::engine()->quote_campaign( $bonus['campaign'], $viewer );
                // The member's daily limit caps a bonus as it caps the figure (member_points() has read it by now).
                $points = $user_id > 0 && is_int( self::$left_today ) ? min( $points, self::$left_today ) : $points;

                if ( $points > $figure ) {
                    $lines .= sprintf( '<p class="%s__bonus">%s</p>', esc_attr( self::CSS_CLASS ), LauncherCopy::product_bonus( $points, $bonus['teaser'] ) );
                }
            }

            return $lines;
        } catch ( \Throwable $e ) {
            // Only the bonus lines go; the figure above them stays.
            Log::warning( 'ProductEarnMessage', 'bonus lines failed: ' . $e->getMessage() );

            return '';
        }
    }

    /**
     * Whether the evaluator found this product's own line failing a product or category condition.
     *
     * @param array<int, array<string, mixed>> $results PointsEngine::offers()' explain() rows for one bonus.
     */
    private static function fails_on_this_product( array $results ): bool {
        foreach ( $results as $result ) {
            $about_lines = in_array( $result['type'] ?? '', [ CampaignConditionType::PRODUCT_IN_CART, CampaignConditionType::CATEGORY_IN_CART ], true );

            // The product's Context has a line, so these are always compared: a miss is this product's.
            if ( $about_lines && empty( $result['matched'] ) ) {
                return true;
            }
        }

        return false;
    }

    private static function advert(): Context {
        self::$advert = self::$advert ?? ContextFactory::for_advert( Dates::now() );

        return self::$advert;
    }

    /** What one of this product earns a guest: only campaigns that pay a shopper with no account or history. 0 on failure. */
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

    /**
     * Styled by assets/storefront/product-earn.css. The bonus area is printed even when empty, hidden, so
     * product-earn.js can fill it for a variation.
     */
    private static function markup( \WC_Product $product ): string {
        $sentence = self::sentence( $product );

        if ( '' === $sentence ) {
            return '';
        }

        $bonuses = self::bonus_lines( $product );
        $guest   = 0 === get_current_user_id() ? self::guest_line() : '';

        return sprintf(
            '<div class="%1$s" data-default="%2$s"><p class="%1$s__line"><span class="%1$s__icon">%3$s</span><span class="%1$s__text">%4$s</span></p><div class="%1$s__bonuses" data-default="%5$s"%6$s>%7$s</div>%8$s</div>',
            esc_attr( self::CSS_CLASS ),
            esc_attr( $sentence ),
            AccountMyPointsIcon::get( 'gift' ),
            $sentence,
            esc_attr( $bonuses ),
            '' === $bonuses ? ' hidden' : '',
            $bonuses,
            $guest
        );
    }

    /**
     * "Sign in to earn Points, or join free & get 100 Points.", the clause only while signup pays. Also under the cart row.
     *
     * @param string $css_class The card's class; the paragraph is its `__guest`.
     */
    public static function guest_line( string $css_class = self::CSS_CLASS ): string {
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

        return sprintf( '<p class="%s__guest">%s</p>', esc_attr( $css_class ), $line );
    }
}
