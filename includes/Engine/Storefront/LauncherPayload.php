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
        $engine   = PointsEngine::build( $this->campaigns );
        $rules    = self::earn_rules( $engine, $now, null !== $register );

        return [
            'launcher'     => $design['launcher'],
            'text'         => LauncherCopy::storefront_text( $design['storefront_text'] ),
            'hide_mobile'  => ! empty( $design['visibility']['hide_on_mobile'] ),
            'earn'         => $rules['earn'],
            'purchase_min' => $rules['purchase_min'],
            'redeem'       => $redeem,
            'member'       => null === $balance ? null : self::member( $balance, null === $redeem ? null : $reward ),
            'estimate'     => null === $balance && null !== $rules['earn'][ CampaignTrigger::PURCHASE ] ? self::product_estimate( $engine ) : null,
            'register'     => $register,
            'account_url'  => (string) wc_get_page_permalink( 'myaccount' ),
            'checkout_url' => (string) wc_get_checkout_url(),
        ];
    }

    /**
     * LauncherEarnRules::advertise() at $now, or no earn rows when it fails: the engine's filters run
     * here on every storefront page, and a broken subscriber must never take the page down with it.
     *
     * @return array LauncherEarnRules::advertise()'s shape.
     */
    private static function earn_rules( PointsEngine $engine, string $now, bool $can_register ): array {
        try {
            return LauncherEarnRules::advertise( $engine, ContextFactory::for_advert( $now ), $can_register );
        } catch ( \Throwable $e ) {
            Log::warning( 'LauncherPayload', 'earn rules failed: ' . $e->getMessage() );

            return LauncherEarnRules::none();
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
    private static function register_route(): ?string {
        if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
            return 'account';
        }

        if ( 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) || 'yes' === get_option( 'woocommerce_enable_delayed_account_creation' ) ) {
            return 'checkout';
        }

        return null;
    }

    /**
     * What this product would earn a guest, at the base rate: no tier multiplier,
     * since that belongs to members. Null off a product page, on a variable product (its price is a
     * range) or when the engine finds nothing.
     */
    private static function product_estimate( PointsEngine $engine ): ?int {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return null;
        }

        $product = wc_get_product( get_queried_object_id() );

        if ( ! $product || $product->is_type( 'variable' ) || '' === $product->get_price() ) {
            return null;
        }

        try {
            $points = $engine->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product ) )->get_points();
        } catch ( \Throwable $e ) {
            // A broken estimate must never take the product page down with it.
            Log::warning( 'LauncherPayload', 'product estimate failed: ' . $e->getMessage() );

            return null;
        }

        return $points > 0 ? $points : null;
    }
}
