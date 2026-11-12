<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Redemption\ProgramGate as RedemptionProgramGate;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the launcher templates print for this request, read once.
 * The balance is printed into the page, only for a signed-in shopper, whom the common page caches skip.
 * A store that caches signed-in pages would show one shopper's balance to another.
 */
final class LauncherPayload {

    /** Extra rows for "Ways to redeem", after the points discount. */
    public const REDEEM_OFFERS_FILTER = 'yayloyalty_launcher_redeem_offers';

    /** A member's points count as expiring soon, for the launcher's dot and the My Points tab's notice, this many days ahead. */
    public const EXPIRING_SOON_DAYS = 30;

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

    /** Whether "Ways to redeem" has nothing to print: no live points discount and no offers. */
    public static function redeem_is_empty( array $payload ): bool {
        return null === $payload['redeem'] && [] === $payload['offers'];
    }

    /**
     * The payload for a viewer, with the default repositories.
     *
     * @param array $design  DesignHelper::get_design().
     * @param int   $user_id The viewer; 0 for a guest.
     */
    public static function for_user( array $design, int $user_id ): array {
        return ( new self( new CampaignRepository(), new RewardRepository(), new CustomerRepository() ) )->build( $design, $user_id > 0 ? $user_id : null );
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
        $payload  = $this->for_balance( $design, null === $customer ? 0 : $customer->get_balance(), EarningService::points_left_today( $user_id ), $user_id );

        if ( null !== $customer && $customer->get_balance() > 0 ) {
            $payload['member']['expires_at'] = self::expiring_soon( $customer->get_expires_at() );
        }

        if ( null !== $customer ) {
            $payload['member']['pending'] = max( 0, $customer->get_pending_points() );
        }

        // A shopper checkout refuses (excluded role, opted out, program paused, coupons off) is promised no discount.
        if ( null !== RedemptionProgramGate::shopper_denial( $user_id ) ) {
            $payload['redeem']          = null;
            $payload['member']['worth'] = null;
        }

        return $payload;
    }

    /**
     * The payload for a member's balance, or a guest (null). The Design tab's preview passes a sample balance.
     * The design fields read here are mirrored by DESIGN_PREVIEW_FIELDS (apps/admin/src/lib/api/design.api.ts).
     *
     * @param array    $design  DesignHelper::get_design() or DesignHelper::with_unsaved().
     * @param int|null $balance    The member's balance; null for a guest.
     * @param int|null $left_today Points the member may still earn today (EarningService::points_left_today()); null for no limit.
     * @param int      $viewer_id  The member whose own earn values, bonuses and locked rewards are shown; 0 for what anyone is promised.
     */
    public function for_balance( array $design, ?int $balance, ?int $left_today = null, int $viewer_id = 0 ): array {
        $now      = Dates::now();
        $reward   = $this->rewards->find_live_flexible_discount( $now );
        $redeem   = self::redeem_rule( $reward );
        $register = self::register_route();
        $engine   = $this->engine();
        $rules    = null === $engine ? LauncherEarnRules::none() : LauncherEarnRules::advertise( $engine, ContextFactory::for_advert( $now ), null !== $register, $viewer_id );
        $estimate = null !== $engine && null === $balance && null !== $rules['earn'][ CampaignTrigger::PURCHASE ] ? self::product_estimate() : null;

        return [
            'hide_mobile'       => ! empty( $design['visibility']['hide_on_mobile'] ),
            'earn'              => $rules['earn'],
            'purchase_min'      => $rules['purchase_min'],
            'purchase_rounding' => $rules['purchase_rounding'],
            'earn_bonuses'      => OfferLines::enabled( $design ) ? $rules['bonuses'] : [],
            'redeem'            => $redeem,
            'offers'            => self::redeem_offers( $now, $viewer_id ),
            'member'            => null === $balance ? null : self::member( $balance, null === $redeem ? null : $reward ),
            'left_today'        => $left_today,
            'estimate'          => $estimate,
            // What the estimate takes off an order, as checkout would grant it: null below the minimum.
            'estimate_off'      => null === $estimate || null === $redeem ? null : self::worth( $reward, $estimate ),
            'register'          => $register,
            'account_url'       => (string) wc_get_page_permalink( 'myaccount' ),
            'history_url'       => AccountMyPoints::history_url(),
            'checkout_url'      => (string) wc_get_checkout_url(),
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
     * @return array{balance: int, worth: ?float, expires_at: ?string, pending: int} `expires_at` and `pending` stay empty
     *         here; build() sets them for a live member: `expires_at` when points expire within EXPIRING_SOON_DAYS, `pending`
     *         to the points open orders hold until they complete.
     */
    private static function member( int $balance, ?Reward $reward ): array {
        return [
            'balance'    => $balance,
            'worth'      => null === $reward || $balance <= 0 ? null : self::worth( $reward, $balance ),
            'expires_at' => null,
            'pending'    => 0,
        ];
    }

    /** $expires_at (UTC) when it is still ahead and no more than EXPIRING_SOON_DAYS away, else null. */
    private static function expiring_soon( ?string $expires_at ): ?string {
        if ( null === $expires_at || ! Dates::is_valid( $expires_at ) ) {
            return null;
        }

        $left = Dates::to_timestamp( $expires_at ) - Dates::to_timestamp( Dates::now() );

        return $left > 0 && $left <= self::EXPIRING_SOON_DAYS * DAY_IN_SECONDS ? $expires_at : null;
    }

    /**
     * The money a balance buys at the reward's rate with no cart limit, as checkout would grant it: Clamp owns the
     * steps, minimum and per-order cap, so neither promises more than the discount pays. Null when it buys nothing.
     * In the shopper's currency, at the rate checkout would charge. Shared by the launcher and the redeem card's header.
     */
    public static function worth( Reward $reward, int $balance ): ?float {
        $currency = CurrencyRate::current();
        $result   = Clamp::apply( $reward, $balance, $balance, null, $currency, wc_get_price_decimals(), CurrencyRate::rate( $currency ) );

        return $result->is_allowed() && $result->get_points() > 0 ? $result->get_value() : null;
    }

    /**
     * The built-in cart-discount reward's rate and limits, for the guest panel's "spend them at checkout" step
     * and the member panel's "Ways to redeem". A limit the merchant left off is null. points_per_unit is per
     * unit of the shopper's currency, through LauncherCopy::converted_rate().
     *
     * @param Reward|null $reward The reward when it is live (RewardRepository::find_live_flexible_discount()).
     * @return array{name: string, points_per_unit: float, min_points: int, max_points: ?int, max_percent: ?float, unlock_points: int, unlock_off: ?float}|null
     *         `unlock_points` the smallest balance the discount takes, and `unlock_off` what it takes off: the points card's "Earn N more".
     */
    private static function redeem_rule( ?Reward $reward ): ?array {
        $rate = RewardRepository::rate_of( $reward );

        if ( null === $reward || $rate <= 0 ) {
            return null;
        }

        $config        = $reward->get_config();
        $currency_rate = CurrencyRate::rate( CurrencyRate::current() );
        $step          = Clamp::step_of( $reward );
        // Its minimum on the step, and never less than one unit of the shopper's currency's worth.
        $unlock = max( (int) ( ceil( Clamp::min_of( $reward ) / $step ) * $step ), Clamp::points_for( 1.0, $reward, $currency_rate ) );

        return [
            'name'            => $reward->get_name(),
            'points_per_unit' => LauncherCopy::converted_rate( $rate / $currency_rate, $currency_rate ),
            'min_points'      => Clamp::min_of( $reward ),
            'max_points'      => isset( $config['max_points_per_order'] ) ? (int) $config['max_points_per_order'] : null,
            'max_percent'     => isset( $config['max_percent_of_discountable'] ) ? (float) $config['max_percent_of_discountable'] : null,
            'unlock_points'   => $unlock,
            'unlock_off'      => self::worth( $reward, $unlock ),
        ];
    }

    /**
     * The rows "Ways to redeem" lists after the points discount, in display order; [] when nothing subscribes.
     * A subscriber that throws gives [] and a malformed row is dropped, so neither takes the launcher down.
     * A locked row is one the viewer can't claim yet: it must carry the teaser the template prints in its place.
     *
     * @param string $now     UTC MySQL datetime.
     * @param int    $user_id The viewer; 0 for a guest.
     * @return array<int, array{name: string, cost: int, text: string, locked?: bool, teaser?: string}>
     */
    private static function redeem_offers( string $now, int $user_id ): array {
        try {
            /**
             * Rows for "Ways to redeem" in the launcher and My Account, after the points discount.
             * Internal seam for Pro, not a public API.
             *
             * @param array<int, array{name: string, cost: int, text: string, locked?: bool, teaser?: string}> $offers
             *        Name, cost in points, and one line under the name ('' for none). `locked` true marks a reward the
             *        viewer's conditions rule out, shown with `teaser` (what it takes) in place of `text`.
             * @param string $now     UTC MySQL datetime.
             * @param int    $user_id The viewer; 0 for a guest.
             */
            $offers = (array) apply_filters( self::REDEEM_OFFERS_FILTER, [], $now, $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
        } catch ( \Throwable $e ) {
            Log::warning( 'LauncherPayload', 'redeem offers failed: ' . $e->getMessage() );

            return [];
        }

        return array_values( array_filter( $offers, [ self::class, 'is_offer' ] ) );
    }

    /** @param mixed $offer */
    private static function is_offer( $offer ): bool {
        if ( ! is_array( $offer )
            || ! isset( $offer['name'], $offer['cost'], $offer['text'] )
            || ! is_string( $offer['name'] ) || '' === $offer['name']
            || ! is_int( $offer['cost'] ) || $offer['cost'] < 1
            || ! is_string( $offer['text'] ) ) {
            return false;
        }

        if ( ! array_key_exists( 'locked', $offer ) || false === $offer['locked'] ) {
            return true;
        }

        return true === $offer['locked'] && isset( $offer['teaser'] ) && is_string( $offer['teaser'] ) && '' !== trim( $offer['teaser'] );
    }

    /**
     * One "Ways to redeem" row for a `fixed_coupon` / `percent_coupon` reward: its name, cost and one line.
     *
     * @return array{name: string, cost: int, text: string}
     */
    public static function offer( Reward $reward ): array {
        return [
            'name' => $reward->get_name(),
            'cost' => (int) $reward->get_cost_points(),
            'text' => LauncherCopy::coupon_reward_line( $reward ),
        ];
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
