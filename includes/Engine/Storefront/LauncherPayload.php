<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the launcher templates print for this request, read once.
 * The balance is printed into the page, only for a signed-in shopper, whom the common page caches skip.
 * A store that caches signed-in pages would show one shopper's balance to another.
 */
final class LauncherPayload {

    /** @var CampaignRepository */
    private $campaigns;

    /** @var RewardRepository */
    private $rewards;

    /** @var CustomerRepository */
    private $customers;

    public function __construct( CampaignRepository $campaigns, RewardRepository $rewards, CustomerRepository $customers ) {
        $this->campaigns = $campaigns;
        $this->rewards   = $rewards;
        $this->customers = $customers;
    }

    /**
     * @param array    $design  DesignHelper::get_design().
     * @param int|null $user_id The signed-in shopper; null for a guest.
     */
    public function build( array $design, ?int $user_id ): array {
        if ( null === $user_id ) {
            return $this->for_balance( $design, null );
        }

        $customer = $this->customers->find_by_user_id( $user_id );

        return $this->for_balance( $design, null === $customer ? 0 : $customer->get_balance() );
    }

    /**
     * The payload for a member's balance, or a guest (null). The Design tab's preview passes a sample balance.
     * The design fields read here are mirrored by DESIGN_PREVIEW_FIELDS (apps/admin/src/lib/api/design.api.ts).
     *
     * @param array    $design  DesignHelper::get_design() or DesignHelper::with_unsaved().
     * @param int|null $balance The member's balance; null for a guest.
     */
    public function for_balance( array $design, ?int $balance ): array {
        $now      = Dates::now();
        $reward   = RedemptionService::is_available() ? $this->rewards->find_live_cart_discount( $now ) : null;
        $redeem   = self::redeem_rule( $reward );
        $register = self::register_route();
        $engine   = $this->engine();
        $rules    = null === $engine ? LauncherEarnRules::none() : LauncherEarnRules::advertise( $engine, ContextFactory::for_advert( $now ), null !== $register );

        return [
            'launcher'     => $design['launcher'],
            'text'         => LauncherCopy::storefront_text( $design['storefront_text'] ),
            'hide_mobile'  => ! empty( $design['visibility']['hide_on_mobile'] ),
            'earn'         => $rules['earn'],
            'purchase_min' => $rules['purchase_min'],
            'redeem'       => $redeem,
            'member'       => null === $balance ? null : self::member( $balance, null === $redeem ? null : $reward ),
            'estimate'     => null !== $engine && null === $balance && null !== $rules['earn'][ CampaignTrigger::PURCHASE ] ? self::product_estimate() : null,
            'register'     => $register,
            'account_url'  => (string) wc_get_page_permalink( 'myaccount' ),
            'rewards_url'  => AccountRewards::url(),
            'checkout_url' => (string) wc_get_checkout_url(),
        ];
    }

    /**
     * The points engine, or null when building it fails: its handler seam runs here on every storefront page
     * and in the Design preview, and a broken subscriber must never take either down with it.
     */
    private function engine(): ?PointsEngine {
        try {
            return PointsEngine::build( $this->campaigns );
        } catch ( \Throwable $e ) {
            Log::warning( 'LauncherPayload', 'points engine failed: ' . $e->getMessage() );

            return null;
        }
    }

    /**
     * @param Reward|null $reward The cart-discount reward when it is live; null hides the worth.
     * @return array{balance: int, worth: ?float}
     */
    private static function member( int $balance, ?Reward $reward ): array {
        return [
            'balance' => $balance,
            'worth'   => null === $reward || $balance <= 0 ? null : self::worth( $reward, $balance ),
        ];
    }

    /**
     * What the whole balance takes off one order, as checkout would grant it: Clamp owns the steps,
     * minimum and per-order cap, so the launcher never promises more than the discount pays.
     */
    private static function worth( Reward $reward, int $balance ): ?float {
        $result = Clamp::apply( $reward, $balance, $balance, null, get_woocommerce_currency(), wc_get_price_decimals() );

        return $result->is_allowed() && $result->get_points() > 0 ? $result->get_value() : null;
    }

    /**
     * The built-in cart-discount reward's rate, for the guest panel's "spend them at checkout" step.
     *
     * @param Reward|null $reward The reward when it is live (RewardRepository::find_live_cart_discount()).
     * @return array{points_per_unit: float}|null
     */
    private static function redeem_rule( ?Reward $reward ): ?array {
        $rate = RewardRepository::rate_of( $reward );

        return $rate > 0 ? [ 'points_per_unit' => $rate ] : null;
    }

    /**
     * Where a guest can create an account, if anywhere: the signup campaign pays only a self-registration,
     * so the panel offers signup points only when the store lets shoppers register.
     *
     * @return string|null 'account' (My Account page), 'checkout' (at or right after checkout) or null.
     */
    public static function register_route(): ?string {
        if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
            return 'account';
        }

        if ( 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) || 'yes' === get_option( 'woocommerce_enable_delayed_account_creation' ) ) {
            return 'checkout';
        }

        return null;
    }

    /** Where register_route() sends a guest: checkout for 'checkout', else My Account. */
    public static function register_url( string $route ): string {
        return (string) ( 'checkout' === $route ? wc_get_checkout_url() : wc_get_page_permalink( 'myaccount' ) );
    }

    /**
     * What this product would earn a guest (ProductEarnMessage::guest_points()). Null off a product page,
     * on a variable product (its price is a range) or when the engine finds nothing.
     */
    private static function product_estimate(): ?int {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return null;
        }

        $product = wc_get_product( get_queried_object_id() );

        if ( ! $product || $product->is_type( 'variable' ) || '' === $product->get_price() ) {
            return null;
        }

        $points = ProductEarnMessage::guest_points( $product );

        return $points > 0 ? $points : null;
    }
}
