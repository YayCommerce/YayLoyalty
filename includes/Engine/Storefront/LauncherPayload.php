<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the launcher templates print for this request, read once.
 *
 * The balance is printed into the page (agreed for v1): only for a signed-in shopper, and the common
 * page caches skip signed-in visitors. A store that caches signed-in pages would show one shopper's
 * balance to another — the README's storefront section carries that caveat.
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
        $now    = Dates::now();
        $reward = $this->rewards->find_by_slug( RewardRepository::BUILTIN_DISCOUNT_SLUG );
        $rate   = RewardRepository::rate_of( $reward );
        $rules  = LauncherEarnRules::from_campaigns( $this->live_campaigns( $now ) );

        return [
            'launcher'     => $design['launcher'],
            'text'         => $design['storefront_text'],
            'hide_mobile'  => ! empty( $design['visibility']['hide_on_mobile'] ),
            'earn'         => $rules,
            'redeem'       => self::redeem_rule( $reward, $rate, $now ),
            'member'       => null === $user_id ? null : $this->member( $user_id, $rate ),
            'estimate'     => null === $user_id && null !== $rules[ CampaignTrigger::PURCHASE ] ? $this->product_estimate() : null,
            'register'     => self::register_route(),
            'account_url'  => (string) wc_get_page_permalink( 'myaccount' ),
            'checkout_url' => (string) wc_get_checkout_url(),
        ];
    }

    /** @return \YayLoyalty\Models\Campaign[] */
    private function live_campaigns( string $now ): array {
        $live = [];

        foreach ( [ CampaignTrigger::PURCHASE, CampaignTrigger::SIGNUP, CampaignTrigger::REVIEW ] as $trigger ) {
            $live = array_merge( $live, $this->campaigns->live_for_trigger( $trigger, $now ) );
        }

        return $live;
    }

    /** @return array{balance: int, worth: ?float} */
    private function member( int $user_id, float $rate ): array {
        $customer = $this->customers->find_by_user_id( $user_id );
        $balance  = null === $customer ? 0 : $customer->get_balance();

        return [
            'balance' => $balance,
            'worth'   => $balance > 0 ? PointsMath::points_to_currency( $balance, $rate, wc_get_price_decimals() ) : null,
        ];
    }

    /**
     * "Ways to redeem", read from the built-in cart-discount reward. Information only until checkout
     * redemption ships (F08): the panel states the rate, it offers no action.
     *
     * @return array{points_per_unit: float, min_points: int, max_percent: ?float}|null
     */
    private static function redeem_rule( ?Reward $reward, float $rate, string $now ): ?array {
        if ( null === $reward || $rate <= 0 || ! $reward->is_live_at( $now ) ) {
            return null;
        }

        $config = $reward->get_config();

        return [
            'points_per_unit' => $rate,
            'min_points'      => (int) ( $config['min_points'] ?? 0 ),
            'max_percent'     => isset( $config['max_percent_of_subtotal'] ) ? (float) $config['max_percent_of_subtotal'] : null,
        ];
    }

    /**
     * Where a guest can create an account, if anywhere: the signup campaign only pays a self-registration,
     * so the panel offers signup points only when the store lets shoppers register.
     *
     * @return string|null 'account' (My Account page), 'checkout' (only at checkout) or null.
     */
    private static function register_route(): ?string {
        if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
            return 'account';
        }

        return 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) ? 'checkout' : null;
    }

    /**
     * What this product would earn a guest, at the base rate (design 1c, note 3): no tier multiplier,
     * since that belongs to members. Null off a product page, on a variable product (its price is a
     * range) or when the engine finds nothing.
     */
    private function product_estimate(): ?int {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return null;
        }

        $product = wc_get_product( get_queried_object_id() );

        if ( ! $product || $product->is_type( 'variable' ) || '' === $product->get_price() ) {
            return null;
        }

        try {
            $points = PointsEngine::build( $this->campaigns )
                ->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product ) )
                ->get_points();
        } catch ( \Throwable $e ) {
            // A broken estimate must never take the product page down with it.
            Log::warning( 'LauncherPayload', 'product estimate failed: ' . $e->getMessage() );

            return null;
        }

        return $points > 0 ? $points : null;
    }
}
