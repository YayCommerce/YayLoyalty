<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\RewardCoupons;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\CurrencyRate;

defined( 'ABSPATH' ) || exit;

/** Puts the friend's referral code on the cart while it applies and takes it off silently otherwise, so checkout is never blocked. */
final class ReferralCartSync {

    /** Session key holding the referral whose coupon the friend removed, so it isn't applied again this session. */
    private const DECLINED_SESSION_KEY = 'yayloyalty_referral_declined';

    private ReferralRepository $referrals;

    private ReferralCoupon $coupon;

    /** @var string[] Codes WooCommerce removes for an individual-use coupon; their removal is not the friend's. */
    private array $displaced = [];

    /** @var array<int, int> Referral ids this request created, by friend user id. */
    private array $created = [];

    public function __construct( ReferralRepository $referrals, ReferralCoupon $coupon ) {
        $this->referrals = $referrals;
        $this->coupon    = $coupon;
    }

    /** Hooked on plugins_loaded. Runs before CouponProvider (PHP_INT_MAX), so the points discount stays last. */
    public function register(): void {
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'sync_cart' ], PHP_INT_MAX - 1 );
        add_action( 'woocommerce_check_cart_items', [ $this, 'sync_before_check' ], 0 );
        add_action( 'woocommerce_removed_coupon', [ $this, 'on_removed' ] );
        add_filter( 'woocommerce_apply_individual_use_coupon', [ $this, 'on_individual_use_apply' ], 10, 3 );
        add_action( ReferralAttribution::CREATED_ACTION, [ $this, 'on_referral_created' ], 10, 2 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'apply_to_signup_order' ] );
    }

    public function on_referral_created( int $referral_id, int $friend_user_id ): void {
        $this->created[ $friend_user_id ] = $referral_id;
    }

    /**
     * Block checkout creates the account after the order, so a friend who signs up while checking out gets the
     * discount here, before payment, as classic checkout gives it when it recalculates the cart for the new customer.
     */
    public function apply_to_signup_order( \WC_Order $order ): void {
        ListenerGuard::run(
            'ReferralCartSync',
            'referral discount for order #' . (int) $order->get_id() . ' failed',
            function () use ( $order ): void {
                $referral_id = $this->created[ (int) $order->get_customer_id() ] ?? null;

                // Only a referral this request created: a returning friend's cart already decided, declined included.
                if ( null === $referral_id ) {
                    return;
                }

                $codes = $order->get_coupon_codes();
                $code  = ReferralCoupon::code_for( $referral_id );

                // A points discount was already settled against the undiscounted total.
                if ( in_array( $code, $codes, true ) || [] !== Context::loyalty_codes( $codes ) || self::has_individual_use_coupon( $codes ) ) {
                    return;
                }

                if ( true === $order->apply_coupon( $code ) ) {
                    $order->save();
                }
            }
        );
    }

    /**
     * WooCommerce is about to remove every other coupon for an individual-use one; that removal is not the friend's.
     *
     * @param mixed $coupons_to_keep
     * @param mixed $coupon
     * @param mixed $applied
     * @return mixed
     */
    public function on_individual_use_apply( $coupons_to_keep, $coupon, $applied ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WooCommerce passes the coupon before the applied codes.
        $this->displaced = array_values( array_diff( (array) $applied, (array) $coupons_to_keep ) );

        return $coupons_to_keep;
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
        $cart = RedemptionService::cart();

        if ( null !== $cart && [] !== array_filter( $cart->get_applied_coupons(), [ ReferralCoupon::class, 'referral_id' ] ) ) {
            $cart->calculate_totals();
        }
    }

    /**
     * The friend removed the discount, or WooCommerce removed it as invalid: it stays off for the session.
     *
     * @param mixed $code
     */
    public function on_removed( $code ): void {
        $id        = ReferralCoupon::referral_id( $code );
        $displaced = array_search( $code, $this->displaced, true );

        if ( false !== $displaced ) {
            unset( $this->displaced[ $displaced ] );

            return;
        }

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

        if ( $minimum > 0 && round( RewardCoupons::displayed_subtotal( $cart ), $decimals ) < round( CurrencyRate::from_base( $minimum ), $decimals ) ) {
            return null;
        }

        return self::has_individual_use_coupon( $cart->get_applied_coupons() ) ? null : ReferralCoupon::code_for( (int) $referral->get_id() );
    }

    /**
     * Whether a merchant coupon among $codes is individual use, which another coupon must never join.
     *
     * @param string[] $codes
     */
    private static function has_individual_use_coupon( array $codes ): bool {
        foreach ( $codes as $code ) {
            if ( null === ReferralCoupon::referral_id( $code ) && ( new \WC_Coupon( $code ) )->get_individual_use() ) {
                return true;
            }
        }

        return false;
    }

    private function is_declined( int $referral_id ): bool {
        $declined = function_exists( 'WC' ) && WC()->session ? (int) WC()->session->get( self::DECLINED_SESSION_KEY ) : 0;

        return $declined === $referral_id;
    }
}
