<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Redemption\Adapters\StoreApiAdapter;
use YayLoyalty\Engine\Redemption\CouponProvider;
use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerReader;
use YayLoyalty\Utils\Log;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The points card: the slider, plus any panel another build adds as a tab (Pro's rewards), in one template for
 * every place it shows. The shortcode and the action print it anywhere; the classic adapter prints it in the totals tables and the Store API adapter carries the same
 * markup to the Cart and Checkout blocks. It decides nothing: every number is RedemptionService's answer,
 * and its `wc-ajax` endpoint only sets SessionIntent. It has its own plain stylesheet and reads nothing from
 * the Design tab, which styles the launcher only.
 * The balance and a nonce are printed into the page, so the launcher's page-cache caveat applies here too.
 */
final class RedeemCard {

    public const SHORTCODE = 'yayloyalty_redeem';
    public const ACTION    = 'yayloyalty_redeem_card';

    /** Filters the panels drawn as tabs beside the slider: `( array $panels, array $context ): array`. */
    public const PANELS_FILTER = 'yayloyalty_redeem_card_panels';

    /** The slider's own tab key; an added panel cannot take it. */
    public const SLIDER_PANEL = 'points';

    /** The `wc-ajax` endpoint Apply and Remove post to on pages without the blocks, and the nonce action it checks. */
    private const AJAX_ENDPOINT = 'yayloyalty_redeem';
    private const NONCE_ACTION  = 'yayloyalty-redeem';

    /** The clamp bounds the card explains; `step` stays silent because the slider already moves in steps. */
    private const EXPLAINED_BOUNDS = [ Clamp::BOUND_BALANCE, Clamp::BOUND_REWARD_MAX, Clamp::BOUND_CART, Clamp::BOUND_REWARD_MIN ];

    private RedemptionService $service;

    private SessionIntent $intent;

    private CustomerReader $customers;

    public function __construct( RedemptionService $service, SessionIntent $intent, CustomerReader $customers ) {
        $this->service   = $service;
        $this->intent    = $intent;
        $this->customers = $customers;
    }

    /** Called once at boot by the plugin. */
    public function register(): void {
        add_shortcode( self::SHORTCODE, [ $this, 'markup' ] );
        add_action( self::ACTION, [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_on_cart_pages' ] );
        add_action( 'wc_ajax_' . self::AJAX_ENDPOINT, [ $this, 'on_apply' ] );
    }

    /**
     * The card's Apply and Remove on pages without the blocks: POST `security` and either `points` (0 removes)
     * or `rewards`, comma-separated reward ids (present and empty clears them). Postcondition:
     * SessionIntent::set_rewards() or set( points ), unclamped, then one totals run, so the coupon provider puts
     * the coupons on or takes them off, and the card's new HTML is answered. Never clamps and never touches a coupon.
     */
    public function on_apply(): void {
        check_ajax_referer( self::NONCE_ACTION, 'security' );

        $cart = RedemptionService::cart();

        if ( null === $cart ) {
            wp_send_json_error();
        }

        if ( isset( $_POST['rewards'] ) ) {
            $this->intent->set_rewards( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['rewards'] ) ) ) ) ) );
        } else {
            $this->intent->set( isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : 0 );
        }

        $cart->calculate_totals();

        wp_send_json_success( [ 'html' => $this->markup() ] );
    }

    /** The `yayloyalty_redeem_card` action: prints the card where a theme or a page builder calls it. */
    public function render(): void {
        echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
    }

    /**
     * Cart and checkout always carry the card, so its assets load in the head there rather than late from markup().
     * Not on the checkout's order-pay and order-received endpoints, which never draw it.
     */
    public function enqueue_on_cart_pages(): void {
        if ( ! function_exists( 'is_cart' ) ) {
            return;
        }

        if ( is_cart() || ( is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) ) ) {
            self::enqueue_assets();
        }
    }

    /**
     * For a page drawing the Cart or Checkout block: the card's assets and the slot fill that shows it
     * (redeem-blocks.js). The script is handed the Store API names it reads and posts to, the coupon's code and
     * the label its totals row reads on the blocks, so none of them is written a second time in JS.
     */
    public static function enqueue_block_assets(): void {
        self::enqueue_assets();

        wp_enqueue_script(
            ScriptName::STOREFRONT_REDEEM_BLOCKS_SCRIPT,
            YAYLOYALTY_PLUGIN_URL . 'assets/storefront/redeem-blocks.js',
            [ 'wp-element', 'wp-plugins', 'wp-data', 'wc-blocks-checkout' ],
            Launcher::asset_version( 'assets/storefront/redeem-blocks.js' ),
            [ 'in_footer' => true ]
        );
        wp_add_inline_script(
            ScriptName::STOREFRONT_REDEEM_BLOCKS_SCRIPT,
            'window.yayloyaltyRedeemBlocks = ' . wp_json_encode(
                [
                    'namespace' => StoreApiAdapter::EXT_NAMESPACE,
                    'callback'  => StoreApiAdapter::CALLBACK,
                    'code'      => CouponProvider::CODE,
                    'label'     => CouponProvider::display_label(),
                ]
            ) . ';',
            'before'
        );
    }

    /** The card's stylesheet and script, both plain files with no build step. Safe to call more than once. */
    public static function enqueue_assets(): void {
        wp_enqueue_style(
            ScriptName::STOREFRONT_REDEEM_STYLE,
            YAYLOYALTY_PLUGIN_URL . 'assets/storefront/redeem.css',
            [],
            Launcher::asset_version( 'assets/storefront/redeem.css' )
        );
        wp_enqueue_script(
            ScriptName::STOREFRONT_REDEEM_SCRIPT,
            YAYLOYALTY_PLUGIN_URL . 'assets/storefront/redeem.js',
            [],
            Launcher::asset_version( 'assets/storefront/redeem.js' ),
            [
                'in_footer' => true,
                'strategy'  => 'defer',
            ]
        );
    }

    /**
     * The card's HTML for the current shopper and cart, with its assets enqueued. A guest gets the sign-in line
     * alone. A customer gets the slider's view and the panels PANELS_FILTER adds, as tabs when there are two or more.
     * '' when nothing renders: no cart, an empty cart, neither a slider nor a panel to show (a customer the
     * gates refuse, a missing reward), or a failure.
     */
    public function markup(): string {
        $level = ob_get_level();

        try {
            $cart = RedemptionService::cart();

            if ( null === $cart || $cart->is_empty() ) {
                return '';
            }

            $user_id = RedemptionService::shopper_id();

            if ( null === $user_id ) {
                // A guest gets the sign-in line whatever the cart holds, so nothing is quoted and no panel is asked for.
                $guest = Result::denied( ProgramGate::REASON_NO_CUSTOMER );

                self::enqueue_assets();

                return self::template( self::view( null, $guest, $guest ) + self::chrome() + self::tabs( [], false ) );
            }

            $balance    = (int) $this->balance_of( $user_id );
            $slider     = $this->slider_view( $cart, $user_id, $balance );
            $is_applied = null !== $slider && 'applied' === $slider['state'];
            $panels     = $this->panels( $cart, $user_id, $balance, $is_applied );

            if ( null === $slider && [] === $panels ) {
                return '';
            }

            self::enqueue_assets();

            return self::template( ( null === $slider ? [ 'state' => 'none' ] : $slider ) + [ 'heading_balance' => $balance ] + self::chrome() + self::tabs( $panels, $is_applied ) );
        } catch ( \Throwable $e ) {
            // The cart outranks the card: a failure here hides it, never the page.
            Log::warning( 'RedeemCard', 'not rendered: ' . $e->getMessage() );

            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }

            return '';
        }
    }

    /**
     * What the template prints, from the two quotes. Pure: no WordPress or WooCommerce call.
     *
     * @param int|null $balance The shopper's balance; null for a guest.
     * @param Result   $quote   RedemptionService::quote(): what the session's request gets.
     * @param Result   $ceiling RedemptionService::ceiling(): what asking for everything gets.
     * @return array{state: string} `guest` and `hidden` carry nothing else. `applied` adds points, value,
     *                              remaining and bound_by. `form` adds balance, min, step, max,
     *                              max_value, rate, is_disabled and bound_by.
     */
    public static function view( ?int $balance, Result $quote, Result $ceiling ): array {
        if ( null === $balance ) {
            return [ 'state' => 'guest' ];
        }

        $reward = $ceiling->get_reward();

        if ( ! $ceiling->is_allowed() || null === $reward ) {
            return [ 'state' => 'hidden' ];
        }

        $is_lowered = $quote->is_allowed()
            && $quote->get_points_requested() > $quote->get_points()
            && in_array( $quote->get_bound_by(), self::EXPLAINED_BOUNDS, true );
        $bound_by   = $is_lowered ? $quote->get_bound_by() : '';

        if ( $quote->is_allowed() && $quote->get_points() > 0 ) {
            return [
                'state'     => 'applied',
                'points'    => $quote->get_points(),
                'value'     => $quote->get_value(),
                'remaining' => $balance - $quote->get_points(),
                'bound_by'  => $bound_by,
            ];
        }

        $step = Clamp::step_of( $reward );
        // The slider moves in steps from 0, so its left end is the first step at or above the reward's minimum.
        $min  = max( $step, (int) ceil( Clamp::min_of( $reward ) / $step ) * $step );
        $max  = $ceiling->get_points();
        $rate = $ceiling->get_rate_snapshot();

        return [
            'state'       => 'form',
            'balance'     => $balance,
            'min'         => $min,
            'step'        => $step,
            'max'         => $max,
            'max_value'   => $ceiling->get_value(),
            'rate'        => $rate,
            'is_disabled' => $max < $min,
            'bound_by'    => $bound_by,
        ];
    }

    /**
     * The heading's inner HTML, escaped: "Your rewards", then the balance as "9,850 Points" for a customer.
     * Never a money amount. A guest (null) gets the title alone.
     */
    public static function heading( ?int $balance ): string {
        $html = '<span>' . esc_html__( 'Your rewards', 'yayloyalty' ) . '</span>';

        if ( null !== $balance ) {
            $html .= '<span class="yayloyalty-redeem__balance">' . esc_html( SettingsHelper::points_text( $balance ) ) . '</span>';
        }

        return $html;
    }

    /**
     * The slider's view for a customer: RedeemCard::view(), or state `exclusive` while picked rewards are on the
     * cart, since the slider and rewards never apply together. Null when the slider cannot be offered at all.
     */
    private function slider_view( \WC_Cart $cart, int $user_id, int $balance ): ?array {
        $ceiling = $this->service->ceiling( $cart, $user_id );

        if ( ! $ceiling->is_allowed() ) {
            return null;
        }

        $view = self::view( $balance, $this->service->quote( $cart, $user_id ), $ceiling );

        if ( 'hidden' === $view['state'] ) {
            return null;
        }

        return $this->has_applied_rewards( $cart ) ? [ 'state' => 'exclusive' ] : $view;
    }

    /** Whether rewards are picked and at least one of their coupons is on the cart. */
    private function has_applied_rewards( \WC_Cart $cart ): bool {
        return [] !== $this->intent->rewards() && [] !== CouponProvider::applied_reward_ids( $cart );
    }

    /**
     * The panels other code adds beside the slider, in the order added; a malformed one is dropped.
     *
     * @return array<string, array{label: string, html: string, is_active: bool}>
     */
    private function panels( \WC_Cart $cart, int $user_id, int $balance, bool $is_slider_applied ): array {
        /**
         * Panels the card shows as tabs beside the points slider, keyed by a slug; Pro adds `rewards`.
         * Internal seam for Pro, not a public API. Each panel's `html` is printed as given, so it must be escaped.
         *
         * @param array<string, array{label: string, html: string, is_active: bool}> $panels
         * @param array{service: RedemptionService, cart: \WC_Cart, user_id: int, balance: int, is_slider_applied: bool} $context
         */
        $panels = apply_filters(
            self::PANELS_FILTER, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
            [],
            [
                'service'           => $this->service,
                'cart'              => $cart,
                'user_id'           => $user_id,
                'balance'           => $balance,
                'is_slider_applied' => $is_slider_applied,
            ]
        );
        $valid  = [];

        foreach ( is_array( $panels ) ? $panels : [] as $key => $panel ) {
            if ( self::SLIDER_PANEL !== $key && is_array( $panel ) && is_string( $panel['label'] ?? null ) && is_string( $panel['html'] ?? null ) ) {
                $valid[ (string) $key ] = [
                    'label'     => $panel['label'],
                    'html'      => $panel['html'],
                    'is_active' => ! empty( $panel['is_active'] ),
                ];
            }
        }

        return $valid;
    }

    /**
     * The tab bar's data: `panels` as given, and `active`, the key of the open one. The added panels come first,
     * then the slider's (self::SLIDER_PANEL). The open tab is the first panel marked active, else the slider while
     * its discount is applied, else the first tab. With no added panel there is no tab bar and `active` is ''.
     *
     * @param array<string, array{label: string, html: string, is_active: bool}> $panels
     * @return array{panels: array, active: string}
     */
    private static function tabs( array $panels, bool $is_slider_applied ): array {
        $active = '';

        foreach ( $panels as $key => $panel ) {
            if ( $panel['is_active'] ) {
                $active = $key;
                break;
            }
        }

        if ( '' === $active ) {
            $active = $is_slider_applied && [] !== $panels ? self::SLIDER_PANEL : (string) array_key_first( $panels );
        }

        return [
            'panels' => $panels,
            'active' => $active,
        ];
    }

    /** 0 for a customer with no row yet, null for a guest. */
    private function balance_of( ?int $user_id ): ?int {
        if ( null === $user_id ) {
            return null;
        }

        $customer = $this->customers->find_by_user_id( $user_id );

        return null === $customer ? 0 : $customer->get_balance();
    }

    /**
     * What the template prints besides the numbers: where Apply posts, and the store's money format for
     * redeem.js, which previews the discount as the slider moves.
     */
    private static function chrome(): array {
        return [
            'endpoint'    => \WC_AJAX::get_endpoint( self::AJAX_ENDPOINT ),
            'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
            'account_url' => (string) wc_get_page_permalink( 'myaccount' ),
            'money'       => [
                'decimals'     => wc_get_price_decimals(),
                'decimal_sep'  => wc_get_price_decimal_separator(),
                'thousand_sep' => wc_get_price_thousand_separator(),
                'format'       => Text::plain( get_woocommerce_price_format() ),
                'symbol'       => Text::plain( get_woocommerce_currency_symbol() ),
            ],
        ];
    }

    private static function template( array $view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the included template reads $view.
        ob_start();
        include __DIR__ . '/templates/redeem-card.php';

        return (string) ob_get_clean();
    }
}
