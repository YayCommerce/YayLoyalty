<?php
namespace YayLoyalty\Engine\Storefront;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * "You'll earn 300 Points with this order" on the cart and checkout, classic and block, with the bonuses the order
 * could earn more from under it (OfferLines). A member's figure goes through the earning gates, as the award will;
 * a guest's is what campaigns pay any guest for this cart. Writes nothing.
 *
 * Classic pages print it as a totals row. The blocks read the same markup from the Store API cart response
 * (`extensions.yayloyalty-earn-preview.html`), which assets/storefront/cart-earn-blocks.js puts in the order
 * summary; the Store API answers every cart change with it, so the row follows the cart without a reload.
 */
final class CartEarnPreview {

    public const CSS_CLASS = 'yayloyalty-cart-earn';

    /** Store API namespace: `extensions.yayloyalty-earn-preview` on the cart response. */
    public const STORE_API_NAMESPACE = 'yayloyalty-earn-preview';

    private const SCRIPT_PATH = 'assets/storefront/cart-earn-blocks.js';

    /** @var PointsEngine|null */
    private static $engine;

    /** @var EarningService|null */
    private static $service;

    public static function register(): void {
        add_action( 'woocommerce_cart_totals_after_order_total', [ self::class, 'render_row' ] );
        add_action( 'woocommerce_review_order_after_order_total', [ self::class, 'render_row' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_style' ] );

        if ( did_action( 'woocommerce_blocks_loaded' ) ) {
            self::attach();

            return;
        }

        add_action( 'woocommerce_blocks_loaded', [ self::class, 'attach' ] );
    }

    /** The Store API cart data and the blocks' script. Public for the hook only; a no-op without the Store API. */
    public static function attach(): void {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(
            [
                'endpoint'        => CartSchema::IDENTIFIER,
                'namespace'       => self::STORE_API_NAMESPACE,
                'data_callback'   => [ self::class, 'cart_data' ],
                'schema_callback' => [ self::class, 'cart_schema' ],
                'schema_type'     => ARRAY_A,
            ]
        );

        add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', [ self::class, 'enqueue_blocks' ] );
        add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', [ self::class, 'enqueue_blocks' ] );
    }

    /**
     * The row's markup for the shopper's cart, '' without a cart.
     *
     * @return array{html: string}
     */
    public static function cart_data(): array {
        $cart = function_exists( 'WC' ) ? WC()->cart : null;

        return [ 'html' => $cart instanceof \WC_Cart ? self::markup( $cart, get_current_user_id() ) : '' ];
    }

    /** @return array<string, array> */
    public static function cart_schema(): array {
        return [
            'html' => [
                'description' => __( 'The points this order will earn, as HTML; empty when nothing shows.', 'yayloyalty' ),
                'type'        => 'string',
                'context'     => [ 'view', 'edit' ],
                'readonly'    => true,
            ],
        ];
    }

    /** Classic cart and checkout: a totals row, since both hooks fire inside a `<table>`. */
    public static function render_row(): void {
        $cart   = function_exists( 'WC' ) ? WC()->cart : null;
        $markup = $cart instanceof \WC_Cart ? self::markup( $cart, get_current_user_id() ) : '';

        if ( '' !== $markup ) {
            echo '<tr class="yayloyalty-cart-earn-row"><td colspan="2">' . $markup . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
        }
    }

    /**
     * The card for $cart as $user_id sees it (0 for a guest), or '' when it is switched off, the cart is empty, or
     * the order earns nothing and no bonus would pay more.
     */
    public static function markup( \WC_Cart $cart, int $user_id ): string {
        $design = DesignHelper::get_design();

        if ( ! DesignHelper::surface_enabled( $design, 'show_cart_earn_preview' ) || $cart->is_empty() ) {
            return '';
        }

        try {
            $ctx        = ContextFactory::from_cart( $cart, $user_id > 0 ? $user_id : null );
            $left_today = $user_id > 0 ? EarningService::points_left_today( $user_id ) : null;
            $figure     = self::figure( $ctx, $user_id, $left_today );
        } catch ( \Throwable $e ) {
            // A broken estimate must never take the cart down with it.
            Log::warning( 'CartEarnPreview', 'estimate failed: ' . $e->getMessage() );

            return '';
        }

        $bonuses = self::bonuses( $ctx, $user_id, $figure, $left_today );

        if ( $figure <= 0 && [] === $bonuses ) {
            return '';
        }

        $yayloyalty_cart_earn = [
            'line'    => $figure > 0 ? LauncherCopy::points_sentence_html( (string) $design['storefront_text']['cart_earn_line'], $figure ) : '',
            'bonuses' => $bonuses,
            'guest'   => $user_id > 0 ? '' : ProductEarnMessage::guest_line( self::CSS_CLASS ),
        ];

        return (string) OutputCapture::capture(
            static function () use ( $yayloyalty_cart_earn ): void {
                include __DIR__ . '/templates/cart-earn-row.php';
            },
            'CartEarnPreview'
        );
    }

    /** Points the order earns: a member's through the earning gates and the daily limit, a guest's from the campaigns alone. */
    private static function figure( Context $ctx, int $user_id, ?int $left_today ): int {
        if ( $user_id < 1 ) {
            return self::engine()->evaluate( CampaignTrigger::PURCHASE, $ctx )->get_points();
        }

        self::$service = self::$service ?? EarningService::build();

        return EarningService::cap_today( self::$service->preview( CampaignTrigger::PURCHASE, $ctx )->get_points(), $left_today );
    }

    /**
     * Each bonus that would pay more than $figure on this cart, as escaped HTML. A guest's come from the advert,
     * so they depend on the cart alone.
     *
     * @return string[]
     */
    private static function bonuses( Context $ctx, int $user_id, int $figure, ?int $left_today ): array {
        try {
            $lines = [];

            foreach ( OfferLines::quoted_bonuses( self::engine(), ContextFactory::for_advert( Dates::now() ), $user_id > 0 ? $ctx : null, $ctx, $figure, $left_today ) as $bonus ) {
                $lines[] = LauncherCopy::product_bonus( $bonus['points'], $bonus['teaser'] );
            }

            return $lines;
        } catch ( \Throwable $e ) {
            // Only the bonus lines go; the figure stays.
            Log::warning( 'CartEarnPreview', 'bonus lines failed: ' . $e->getMessage() );

            return [];
        }
    }

    /** The card's stylesheet on the cart and checkout pages, classic or block. */
    public static function enqueue_style(): void {
        if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
            self::enqueue_style_file();
        }
    }

    /** On a page drawing the Cart or Checkout block: the stylesheet and the slot fill that shows the card. */
    public static function enqueue_blocks(): void {
        self::enqueue_style_file();

        wp_enqueue_script(
            ScriptName::CART_EARN_BLOCKS_SCRIPT,
            YAYLOYALTY_PLUGIN_URL . self::SCRIPT_PATH,
            [ 'wp-element', 'wp-plugins', 'wc-blocks-checkout' ],
            Launcher::asset_version( self::SCRIPT_PATH ),
            [ 'in_footer' => true ]
        );
        wp_add_inline_script(
            ScriptName::CART_EARN_BLOCKS_SCRIPT,
            'window.yayloyaltyCartEarnBlocks = ' . wp_json_encode( [ 'namespace' => self::STORE_API_NAMESPACE ] ) . ';',
            'before'
        );
    }

    private static function enqueue_style_file(): void {
        ProductEarnMessage::enqueue_card_style();
    }

    private static function engine(): PointsEngine {
        self::$engine = self::$engine ?? PointsEngine::build( new CampaignRepository() );

        return self::$engine;
    }
}
