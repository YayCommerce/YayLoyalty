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
 * The points card: one checkbox list, the Points discount row (which opens into the slider) then the rows
 * ROWS_FILTER adds (Pro's rewards), in one template for every place it shows. The shortcode and the action
 * print it anywhere; the classic adapter prints it in the totals tables and the Store API adapter carries the
 * same markup to the Cart and Checkout blocks. It decides nothing: every number is RedemptionService's answer,
 * and its `wc-ajax` endpoint only sets SessionIntent. It has its own plain stylesheet and reads nothing from
 * the Design tab, which styles the launcher only.
 * The balance and a nonce are printed into the page, so the launcher's page-cache caveat applies here too.
 */
final class RedeemCard {

    public const SHORTCODE = 'yayloyalty_redeem';
    public const ACTION    = 'yayloyalty_redeem_card';

    /** Filters the rows listed after the Points discount row: `( string[] $rows, array $context ): string[]`. */
    public const ROWS_FILTER = 'yayloyalty_redeem_card_rows';

    /** The `wc-ajax` endpoint the card's ticks and slider post to on pages without the blocks, and the nonce action it checks. */
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
     * The card's changes on pages without the blocks: POST `security` and `rewards`, comma-separated reward ids
     * (present and empty clears them), `points` (0 removes), or both. Postcondition: SessionIntent::apply() with
     * whichever is present, then one totals run, so the coupon provider puts the coupons on or takes them off,
     * and the card's new HTML is answered. Never clamps and never touches a coupon. POST `dismiss` instead only
     * forgets the dropped rewards the card's notice reports (SessionIntent::clear_dropped()) and answers no HTML.
     */
    public function on_apply(): void {
        check_ajax_referer( self::NONCE_ACTION, 'security' );

        // The notice's ×, from every page the card shows on (blocks included): forgets the dropped rewards and
        // leaves the shopper's choice, the totals and the card as they are, so an open checkout draft survives.
        if ( isset( $_POST['dismiss'] ) ) {
            $this->intent->clear_dropped();
            wp_send_json_success();
        }

        $cart = RedemptionService::cart();

        if ( null === $cart ) {
            wp_send_json_error();
        }

        $this->intent->apply(
            isset( $_POST['rewards'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['rewards'] ) ) ) ) : null,
            isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : null
        );

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
     * The card's HTML for the current shopper and cart, with its assets enqueued. A guest gets the sign-in card
     * alone. A customer gets the header, then the list: the Points discount row from view(), then the rows
     * ROWS_FILTER adds. '' when nothing renders: no cart, an empty cart, neither a slider nor a row to show
     * (a customer the gates refuse, a missing reward), or a failure.
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
                // A guest gets the sign-in card whatever the cart holds, so nothing is quoted and no row is asked for.
                $guest = Result::denied( ProgramGate::REASON_NO_CUSTOMER );

                self::enqueue_assets();

                return self::template( self::view( null, $guest, $guest, 0 ) + self::chrome() );
            }

            $balance = (int) $this->balance_of( $user_id );
            $quote   = $this->service->quote( $cart, $user_id );
            $view    = self::view( $balance, $quote, $this->service->ceiling( $cart, $user_id ), $this->intent->get() );
            $rows    = $this->rows( $cart, $user_id, $balance );

            if ( 'hidden' === $view['state'] && [] === $rows ) {
                $this->notice_dropped_elsewhere();

                return '';
            }

            self::enqueue_assets();

            $reward = $this->service->live_flexible_reward();
            $worth  = null === $reward || $balance <= 0 ? null : LauncherPayload::worth( $reward, $balance );

            return self::template(
                $view + [
                    'heading_balance' => $balance,
                    'worth'           => $worth,
                    'rows'            => $rows,
                    'summary'         => $this->summary( $cart, $user_id, $quote ),
                    'dropped'         => $this->intent->dropped(),
                ] + self::chrome()
            );
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
     * What the template prints for the Points discount row, from the two quotes. Pure: no WordPress or
     * WooCommerce call.
     *
     * @param int|null $balance   The shopper's balance; null for a guest.
     * @param Result   $quote     RedemptionService::quote(): what the session's request gets, net of the rewards.
     * @param Result   $ceiling   RedemptionService::ceiling(): what asking for everything gets, net of the rewards.
     * @param int      $requested SessionIntent::get(): the points the shopper asked for; 0 while the row is unticked.
     * @return array{state: string} `guest` and `hidden` carry nothing else. `slider` adds requested, points and
     *                              value (applied; 0 when the quote is refused), balance, min, step, max (the
     *                              ceiling's points), max_value, rate, is_disabled and bound_by.
     */
    public static function view( ?int $balance, Result $quote, Result $ceiling, int $requested ): array {
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

        $step = Clamp::step_of( $reward );
        // The slider moves in steps from 0, so its left end is the first step at or above the reward's minimum.
        $min = max( $step, (int) ceil( Clamp::min_of( $reward ) / $step ) * $step );
        $max = $ceiling->get_points();

        return [
            'state'       => 'slider',
            'requested'   => $requested,
            'points'      => $quote->is_allowed() ? $quote->get_points() : 0,
            'value'       => $quote->is_allowed() ? $quote->get_value() : 0.0,
            'balance'     => $balance,
            'min'         => $min,
            'step'        => $step,
            'max'         => $max,
            'max_value'   => $ceiling->get_value(),
            'rate'        => $ceiling->get_rate_snapshot(),
            // A ticked row stays tickable when the rewards leave it nothing, so it can be unticked; only an unticked one is shut.
            'is_disabled' => $max < $min && $requested <= 0,
            'bound_by'    => $is_lowered ? $quote->get_bound_by() : '',
        ];
    }

    /**
     * The heading's inner HTML, escaped: "Your rewards", then for a customer the checkout's title "Points &
     * rewards" (redeem.css shows one of the two by mode), the balance as "9,850 Points" and, when $worth is
     * non-null, "⇄ $98.50". The worth is the only money the heading prints, and only while `cart-discount` is
     * live. A guest (null) gets "Your rewards" alone.
     */
    public static function heading( ?int $balance, ?float $worth ): string {
        $html = '<span class="yayloyalty-redeem__title">' . esc_html__( 'Your rewards', 'yayloyalty' ) . '</span>';

        if ( null !== $balance ) {
            // Both titles are printed because the blocks' card is shared by Cart and Checkout.
            $html .= '<span class="yayloyalty-redeem__title-checkout">' . esc_html(
                /* translators: %s: the store's points label, as "Points". */
                sprintf( __( '%s & rewards', 'yayloyalty' ), SettingsHelper::points_label_plural() )
            ) . '</span>';

            // points_text()'s format, with the number and the label wrapped apart so the label can sit smaller.
            $html .= '<span class="yayloyalty-redeem__balance">' . sprintf(
                /* translators: 1: number of points, 2: the store's points label, e.g. "Points". */
                esc_html( _x( '%1$s %2$s', 'points amount', 'yayloyalty' ) ),
                esc_html( SettingsHelper::format_number( $balance ) ),
                '<span class="yayloyalty-redeem__unit">' . esc_html( SettingsHelper::points_label( $balance ) ) . '</span>'
            ) . '</span>';

            if ( null !== $worth ) {
                $html .= '<span class="yayloyalty-redeem__worth"><span aria-hidden="true">⇄</span> ' . esc_html( LauncherCopy::money( $worth ) ) . '</span>';
            }
        }

        return $html;
    }

    /**
     * The rows other code lists after the Points discount row, in the order added; anything but a string is dropped.
     *
     * @return string[]
     */
    private function rows( \WC_Cart $cart, int $user_id, int $balance ): array {
        /**
         * Rows the card lists after its Points discount row; Pro adds its rewards.
         * Internal seam for Pro, not a public API. Each string is printed as given inside the card's list, so
         * whoever adds it escapes it.
         *
         * @param string[] $rows
         * @param array{service: RedemptionService, cart: \WC_Cart, user_id: int, balance: int} $context
         */
        $rows = apply_filters(
            self::ROWS_FILTER, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
            [],
            [
                'service' => $this->service,
                'cart'    => $cart,
                'user_id' => $user_id,
                'balance' => $balance,
            ]
        );

        return array_values( array_filter( is_array( $rows ) ? $rows : [], 'is_string' ) );
    }

    /**
     * What the card has applied, for the checkout's summary line: `points` the slider's applied points plus each
     * applied reward's cost, `count` how many are applied, the slider counting as one. Both 0 when nothing is.
     *
     * @return array{points: int, count: int}
     */
    private function summary( \WC_Cart $cart, int $user_id, Result $quote ): array {
        $slider  = $quote->is_allowed() ? $quote->get_points() : 0;
        $rewards = $this->service->applied_reward_costs( $cart, $user_id );

        return [
            'points' => $slider + array_sum( $rewards ),
            'count'  => ( $slider > 0 ? 1 : 0 ) + count( $rewards ),
        ];
    }

    /**
     * The card's notice line for a picked reward the store took away; $name '' is a deleted reward, which has none.
     */
    public static function dropped_line( string $name ): string {
        if ( '' === $name ) {
            return __( 'A reward you picked is no longer available and was removed.', 'yayloyalty' );
        }

        /* translators: %s: the reward's name, as "$5 off". */
        return sprintf( __( '%s is no longer available and was removed.', 'yayloyalty' ), $name );
    }

    /**
     * With no card to show them in, the dropped rewards' lines go to WooCommerce's notices instead and are
     * forgotten, so the shopper is told once either way.
     */
    private function notice_dropped_elsewhere(): void {
        if ( ! function_exists( 'wc_add_notice' ) ) {
            return;
        }

        foreach ( $this->intent->dropped() as $name ) {
            wc_add_notice( self::dropped_line( $name ), 'notice' );
        }

        $this->intent->clear_dropped();
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
     * What the template prints besides the numbers: where the card posts, and the store's money format for
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
