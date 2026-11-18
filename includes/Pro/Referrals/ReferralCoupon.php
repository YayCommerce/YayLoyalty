<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** The friend's first-order discount: a virtual coupon `yl-referral-{id}` that ReferralCartSync applies. */
final class ReferralCoupon {

    private const CODE_PREFIX = 'yl-referral-';

    private ReferralRepository $referrals;

    public function __construct( ReferralRepository $referrals ) {
        $this->referrals = $referrals;
    }

    /** Hooked on plugins_loaded. */
    public function register(): void {
        add_filter( 'woocommerce_get_shop_coupon_data', [ $this, 'coupon_data' ], 10, 2 );
        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'is_valid' ], 10, 3 );
        add_filter( 'woocommerce_cart_totals_coupon_label', [ $this, 'label' ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'label_block_line' ] );
    }

    public static function code_for( int $referral_id ): string {
        return self::CODE_PREFIX . $referral_id;
    }

    /**
     * The coupon's terms from the referral's snapshot, converted from base currency.
     *
     * @param mixed $data False, or another provider's array.
     * @param mixed $code
     * @return mixed
     */
    public function coupon_data( $data, $code ) {
        $referral = $this->referral_for_code( $code );

        if ( null === $referral ) {
            return $data;
        }

        $terms   = $referral->get_terms();
        $percent = ReferralSettings::DISCOUNT_PERCENT === ( $terms['discount_type'] ?? '' );
        $amount  = (float) ( $terms['discount_amount'] ?? 0 );
        $minimum = (float) ( $terms['min_order'] ?? 0 );

        return [
            'discount_type'  => $percent ? 'percent' : 'fixed_cart',
            'amount'         => $percent ? $amount : CurrencyRate::from_base( $amount ),
            'minimum_amount' => $minimum > 0 ? CurrencyRate::from_base( $minimum ) : '',
            'individual_use' => false,
            'usage_limit'    => 0,
        ];
    }

    /**
     * Refuses the code to anyone but the friend, or once the referral can no longer be used.
     * An order passes again once it is the referral's first order.
     *
     * @param mixed $valid
     * @return mixed
     * @throws \Exception With the refusal WooCommerce shows; WC_Discounts::is_coupon_valid() catches it.
     */
    public function is_valid( $valid, \WC_Coupon $coupon, \WC_Discounts $discounts ) {
        $referral = $this->referral_for_code( $coupon->get_code() );

        if ( null === $referral ) {
            return $valid;
        }

        $order = $discounts->get_object();

        if ( $order instanceof \WC_Order ) {
            $usable = $referral->get_friend_user_id() === (int) $order->get_customer_id()
                && ( $referral->get_first_order_id() === (int) $order->get_id() || $this->is_usable( $referral, (int) $order->get_id() ) );
        } else {
            $usable = $referral->get_friend_user_id() === get_current_user_id() && $this->is_usable( $referral, null );
        }

        if ( ! $usable ) {
            throw new \Exception( esc_html__( 'This referral discount is no longer available.', 'yayloyalty' ) );
        }

        return $valid;
    }

    public function label( string $label, \WC_Coupon $coupon ): string {
        return null === self::referral_id( $coupon->get_code() ) ? $label : self::display_label();
    }

    /** Block checkout reads the coupon's code as its totals label; this relabels the referral line. */
    public function label_block_line(): void {
        if ( ! is_user_logged_in() || ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
            return;
        }

        wp_add_inline_script(
            'wc-blocks-checkout',
            sprintf(
                'window.wc.blocksCheckout.registerCheckoutFilters("yayloyalty-referral",{coupons:function(c){return Array.isArray(c)?c.map(function(x){return x&&0===String(x.code).indexOf(%1$s)?Object.assign({},x,{label:%2$s}):x;}):c;}});',
                wp_json_encode( self::CODE_PREFIX ),
                wp_json_encode( self::display_label() )
            )
        );
    }

    private static function display_label(): string {
        return __( 'Referral discount', 'yayloyalty' );
    }

    /**
     * Pending, unexpired, the program live, and no paid order besides $exclude_order_id.
     */
    public function is_usable( Referral $referral, ?int $exclude_order_id ): bool {
        if ( ReferralStatus::PENDING !== $referral->get_status() || ! ReferralSettings::get()['enabled'] ) {
            return false;
        }

        $expires = $referral->get_coupon_expires_at();

        if ( null === $expires || $expires <= Dates::now() ) {
            return false;
        }

        // Any order already paid or awaiting payment confirmation counts, so the first order can't be followed by a second discounted one before it completes.
        $orders = wc_get_orders(
            [
                'customer_id' => $referral->get_friend_user_id(),
                'status'      => array_merge( wc_get_is_paid_statuses(), [ 'on-hold' ] ),
                'exclude'     => $exclude_order_id ? [ $exclude_order_id ] : [], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- at most one order id, the one being checked.
                'limit'       => 1,
                'return'      => 'ids',
            ]
        );

        return [] === $orders;
    }

    /**
     * @param mixed $code
     */
    public static function referral_id( $code ): ?int {
        $code = is_string( $code ) ? wc_format_coupon_code( $code ) : '';

        return 1 === preg_match( '/^' . preg_quote( self::CODE_PREFIX, '/' ) . '([1-9]\d{0,18})$/D', $code, $match ) ? (int) $match[1] : null;
    }

    /**
     * @param mixed $code
     */
    private function referral_for_code( $code ): ?Referral {
        $id       = self::referral_id( $code );
        $referral = null === $id ? null : $this->referrals->find( $id );

        return $referral instanceof Referral ? $referral : null;
    }
}
