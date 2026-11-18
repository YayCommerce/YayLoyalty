<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\CurrencyRate;

defined( 'ABSPATH' ) || exit;

/** Puts the friend's referral code on the cart while it applies and takes it off silently otherwise, so checkout is never blocked. */
final class ReferralCartSync {

    /** Session key holding the referral whose coupon the friend removed, so it isn't applied again this session. */
    private const DECLINED_SESSION_KEY = 'yayloyalty_referral_declined';

    private ReferralRepository $referrals;

    private ReferralCoupon $coupon;

    public function __construct( ReferralRepository $referrals, ReferralCoupon $coupon ) {
        $this->referrals = $referrals;
        $this->coupon    = $coupon;
    }

    /** Hooked on plugins_loaded. Runs before CouponProvider (PHP_INT_MAX), so the points discount stays last. */
    public function register(): void {
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'sync_cart' ], PHP_INT_MAX - 1 );
        add_action( 'woocommerce_check_cart_items', [ $this, 'sync_before_check' ], 0 );
        add_action( 'woocommerce_removed_coupon', [ $this, 'on_removed' ] );
    }

    public function sync_cart( \WC_Cart $cart ): void {
        ListenerGuard::run(
            'ReferralCartSync',
            'referral coupon sync failed',
            function () use ( $cart ): void {
                $this->sync( $cart );
            }
        );
    }

    /**
     * Classic cart and checkout check coupons before their totals run, so a code that stopped applying would be
     * removed by WooCommerce with an error naming it. One totals run first lets sync_cart() take it off silently.
     */
    public function sync_before_check(): void {
        $cart = function_exists( 'WC' ) ? WC()->cart : null;

        if ( $cart instanceof \WC_Cart && [] !== array_filter( $cart->get_applied_coupons(), [ ReferralCoupon::class, 'referral_id' ] ) ) {
            $cart->calculate_totals();
        }
    }

    /**
     * The friend removed the discount, or WooCommerce removed it as invalid: it stays off for the session.
     *
     * @param mixed $code
     */
    public function on_removed( $code ): void {
        $id = ReferralCoupon::referral_id( $code );

        if ( null !== $id && function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( self::DECLINED_SESSION_KEY, $id );
        }
    }

    private function sync( \WC_Cart $cart ): void {
        $applied = $cart->get_applied_coupons();
        $next    = array_values(
            array_filter(
                $applied,
                static fn( $code ): bool => null === ReferralCoupon::referral_id( $code )
            )
        );
        $wanted  = $this->wanted_code( $cart );

        if ( null !== $wanted ) {
            $next[] = $wanted;
        }

        if ( $next !== $applied ) {
            $cart->set_applied_coupons( $next );
        }
    }

    /** The code the cart should carry: the logged-in friend's, while usable, not declined, the cart meets the minimum and no individual-use coupon is on it. */
    private function wanted_code( \WC_Cart $cart ): ?string {
        $user_id  = get_current_user_id();
        $referral = $user_id > 0 ? $this->referrals->find_pending_for_friend( $user_id ) : null;

        if ( null === $referral || $this->is_declined( (int) $referral->get_id() ) || ! $this->coupon->is_usable( $referral, null ) ) {
            return null;
        }

        $minimum  = (float) ( $referral->get_terms()['min_order'] ?? 0 );
        $decimals = wc_get_price_decimals();

        if ( $minimum > 0 && round( self::cart_subtotal( $cart ), $decimals ) < round( CurrencyRate::from_base( $minimum ), $decimals ) ) {
            return null;
        }

        return self::has_individual_use_coupon( $cart ) ? null : ReferralCoupon::code_for( (int) $referral->get_id() );
    }

    /** Whether a merchant coupon on the cart is individual use, which another coupon must never join. */
    private static function has_individual_use_coupon( \WC_Cart $cart ): bool {
        foreach ( $cart->get_applied_coupons() as $code ) {
            if ( null === ReferralCoupon::referral_id( $code ) && ( new \WC_Coupon( $code ) )->get_individual_use() ) {
                return true;
            }
        }

        return false;
    }

    /** The subtotal WooCommerce's minimum check reads, from the items: before_calculate_totals runs before the cart's totals are fresh. */
    private static function cart_subtotal( \WC_Cart $cart ): float {
        $subtotal = 0.0;

        foreach ( $cart->get_cart() as $item ) {
            $product = $item['data'] ?? null;

            if ( $product instanceof \WC_Product ) {
                $args      = [ 'qty' => (float) $item['quantity'] ];
                $subtotal += (float) ( $cart->display_prices_including_tax() ? wc_get_price_including_tax( $product, $args ) : wc_get_price_excluding_tax( $product, $args ) );
            }
        }

        return $subtotal;
    }

    private function is_declined( int $referral_id ): bool {
        $declined = function_exists( 'WC' ) && WC()->session ? (int) WC()->session->get( self::DECLINED_SESSION_KEY ) : 0;

        return $declined === $referral_id;
    }
}
