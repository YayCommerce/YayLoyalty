<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Repositories\CustomerReader;
use YayLoyalty\Utils\Log;
use YayLoyalty\Utils\PointsMath;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The points slider card: one template for every place it shows. The shortcode and the action print it
 * anywhere; the classic adapter prints it in the totals tables and the Store API adapter carries the same
 * markup to the Cart and Checkout blocks. It decides nothing: every number is RedemptionService's answer,
 * and its `wc-ajax` endpoint only sets SessionIntent. It has its own plain stylesheet and reads nothing from
 * the Design tab, which styles the launcher only.
 * The balance and a nonce are printed into the page, so the launcher's page-cache caveat applies here too.
 */
final class RedeemCard {

    public const SHORTCODE = 'yayloyalty_redeem';
    public const ACTION    = 'yayloyalty_redeem_card';

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
        add_action( 'wc_ajax_yayloyalty_redeem', [ $this, 'on_apply' ] );
    }

    /**
     * The card's Apply and Remove on pages without the blocks: POST `points` (0 removes) and `security`.
     * Postcondition: SessionIntent::set( points ), unclamped, then one totals run, so the coupon provider puts
     * the coupon on or takes it off, and the card's new HTML is answered. Never clamps and never touches a coupon.
     */
    public function on_apply(): void {
        check_ajax_referer( 'yayloyalty-redeem', 'security' );

        $cart = RedemptionService::cart();

        if ( null === $cart ) {
            wp_send_json_error();
        }

        $this->intent->set( isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : 0 );
        $cart->calculate_totals();

        wp_send_json_success( [ 'html' => $this->markup() ] );
    }

    /** The `yayloyalty_redeem_card` action: prints the card where a theme or a page builder calls it. */
    public function render(): void {
        echo $this->markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
    }

    /** Cart and checkout always carry the card, so its assets load in the head there rather than late from markup(). */
    public function enqueue_on_cart_pages(): void {
        if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
            self::enqueue_assets();
        }
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
     * The card's HTML for the current shopper and cart, with its assets enqueued. '' when nothing renders:
     * no cart, an empty cart, a customer the gates refuse, a missing reward, or a failure.
     */
    public function markup(): string {
        $level = ob_get_level();

        try {
            $cart = RedemptionService::cart();

            if ( null === $cart || $cart->is_empty() ) {
                return '';
            }

            $user_id = RedemptionService::shopper_id();
            $view    = self::view(
                $this->balance_of( $user_id ),
                $this->service->quote( $cart, $user_id ),
                $this->service->ceiling( $cart, $user_id ),
                wc_get_price_decimals()
            );

            if ( 'hidden' === $view['state'] ) {
                return '';
            }

            self::enqueue_assets();

            return self::template( $view + self::chrome() );
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
     * @param int|null $balance  The shopper's balance; null for a guest.
     * @param Result   $quote    RedemptionService::quote(): what the session's request gets.
     * @param Result   $ceiling  RedemptionService::ceiling(): what asking for everything gets.
     * @param int      $decimals The currency's decimals.
     * @return array{state: string} `guest` and `hidden` carry nothing else. `applied` adds points, value,
     *                              remaining and bound_by. `form` adds balance, worth, min, step, max,
     *                              max_value, rate, is_disabled and bound_by.
     */
    public static function view( ?int $balance, Result $quote, Result $ceiling, int $decimals ): array {
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

        $config = $reward->get_config();
        $step   = max( 1, (int) ( $config['increment'] ?? 1 ) );
        // The slider moves in steps from 0, so its left end is the first step at or above the reward's minimum.
        $min  = max( $step, (int) ceil( max( 0, (int) ( $config['min_points'] ?? 0 ) ) / $step ) * $step );
        $max  = $ceiling->get_points();
        $rate = $ceiling->get_rate_snapshot();

        return [
            'state'       => 'form',
            'balance'     => $balance,
            'worth'       => PointsMath::points_to_currency( $balance, $rate, $decimals ),
            'min'         => $min,
            'step'        => $step,
            'max'         => $max,
            'max_value'   => $ceiling->get_value(),
            'rate'        => $rate,
            'is_disabled' => $max < $min,
            'bound_by'    => $bound_by,
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
            'endpoint'    => \WC_AJAX::get_endpoint( 'yayloyalty_redeem' ),
            'nonce'       => wp_create_nonce( 'yayloyalty-redeem' ),
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
