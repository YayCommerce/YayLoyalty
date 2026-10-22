<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Redemption\CouponProvider;
use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\RewardCoupons;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The card's Rewards tab: one checkbox row per reward RedemptionService::reward_options() offers, the selection
 * line and one Apply/Update button. Draws only; redeem.js posts the ticked ids and the server answers a new card.
 * It also lists the same rewards, with the same one-line description, under "Ways to redeem" in the launcher and
 * My Account.
 */
final class RewardsPanel {

    public const KEY = 'rewards';

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( RedeemCard::PANELS_FILTER, [ $this, 'panel' ], 10, 2 );
        add_filter( LauncherPayload::REDEEM_OFFERS_FILTER, [ $this, 'redeem_offers' ], 10, 2 );
    }

    /**
     * Appends every live `fixed_coupon` / `percent_coupon` reward, in `sort_order`, whatever the member's
     * conditions or balance: there is no cart to check them against.
     *
     * @param array<int, array{name: string, cost: int, text: string}> $offers
     * @param string                                                   $now    UTC MySQL datetime.
     * @return array<int, array{name: string, cost: int, text: string}>
     */
    public function redeem_offers( array $offers, string $now ): array {
        foreach ( ( new RewardRepository() )->live_of_types( [ RewardType::FIXED_COUPON, RewardType::PERCENT_COUPON ], $now ) as $reward ) {
            $offers[] = LauncherPayload::offer( $reward );
        }

        return $offers;
    }

    /**
     * Adds `rewards` to the card's panels: active while rewards are applied. Unchanged when there is no option, or
     * when every option is refused by a program gate.
     *
     * @param array<string, array> $panels
     * @param array                $context RedeemCard::PANELS_FILTER's context.
     * @return array<string, array>
     */
    public function panel( array $panels, array $context ): array {
        $service = $context['service'] ?? null;
        $cart    = $context['cart'] ?? null;

        if ( ! $service instanceof RedemptionService || ! $cart instanceof \WC_Cart ) {
            return $panels;
        }

        $user_id = (int) $context['user_id'];
        $balance = (int) $context['balance'];
        $options = $service->reward_options( $cart, $user_id );

        if ( [] === $options || self::is_refused( $options ) ) {
            return $panels;
        }

        $applied = self::applied_points( $service->quote_rewards( $cart, $user_id ), $cart );
        $spent   = array_sum( $applied );

        $panels[ self::KEY ] = [
            'label'     => __( 'Rewards', 'yayloyalty' ),
            'html'      => self::template(
                [
                    'rows'       => self::rows( $options, $applied, $spent, $cart, $balance ),
                    'is_applied' => [] !== $applied,
                    'spent'      => $spent,
                    'balance'    => $balance,
                ]
            ),
            'is_active' => [] !== $applied,
        ];

        return $panels;
    }

    /** @param array<int, array{reward: Reward, quote: Result}> $options */
    private static function is_refused( array $options ): bool {
        // A program gate refuses the shopper, not a reward.
        foreach ( $options as $option ) {
            if ( ! in_array( $option['quote']->get_reason(), ProgramGate::REASONS, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The applied rewards' costs, keyed by reward id: picked ones whose coupon is on the cart, the same test
     * RedeemCard uses for the slider's exclusivity notice.
     *
     * @param array<int, array{reward: ?Reward, quote: Result}> $picked RedemptionService::quote_rewards().
     * @return array<int, int>
     */
    private static function applied_points( array $picked, \WC_Cart $cart ): array {
        $on_cart = CouponProvider::applied_reward_ids( $cart );
        $applied = [];

        foreach ( $picked as $reward_id => $row ) {
            if ( null !== $row['reward'] && in_array( (int) $reward_id, $on_cart, true ) ) {
                $applied[ $reward_id ] = (int) $row['reward']->get_cost_points();
            }
        }

        return $applied;
    }

    /**
     * What each row prints. A row the server refuses is `denied`, with its reason as `text`. A row that is not
     * applied and would take the applied rewards past the balance is `is_short`, re-enabled by redeem.js as the
     * selection drops.
     *
     * @param array<int, array{reward: Reward, quote: Result}> $options
     * @param array<int, int>                                  $applied
     * @param int                                              $spent   The applied rewards' summed cost.
     * @return array<int, array{id: int, name: string, cost: int, text: string, here: string, is_applied: bool, is_denied: bool, is_short: bool}>
     */
    private static function rows( array $options, array $applied, int $spent, \WC_Cart $cart, int $balance ): array {
        $rows = [];

        foreach ( $options as $reward_id => $option ) {
            $reward     = $option['reward'];
            $quote      = $option['quote'];
            $cost       = (int) $reward->get_cost_points();
            $is_applied = isset( $applied[ $reward_id ] );
            $is_denied  = ! $quote->is_allowed();

            $rows[] = [
                'id'         => (int) $reward_id,
                'name'       => $reward->get_name(),
                'cost'       => $cost,
                'text'       => $is_denied ? self::denial( $quote->get_reason(), $reward, $cart ) : LauncherCopy::coupon_reward_line( $reward ),
                'here'       => $is_denied ? '' : self::value_here( $reward, $quote ),
                'is_applied' => $is_applied,
                'is_denied'  => $is_denied,
                'is_short'   => ! $is_denied && ! $is_applied && $spent + $cost > $balance,
            ];
        }

        return $rows;
    }

    /** A refused row's line in place of its description. */
    private static function denial( string $reason, Reward $reward, \WC_Cart $cart ): string {
        switch ( $reason ) {
            case RewardCoupons::REASON_MIN_ORDER:
                $missing = (float) ( $reward->get_config()['min_order'] ?? 0 ) - RewardCoupons::displayed_subtotal( $cart );

                return sprintf( RedemptionService::message_for( $reason ), LauncherCopy::money( max( 0.0, round( $missing, wc_get_price_decimals() ) ) ) );

            case Result::REASON_INSUFFICIENT_BALANCE:
                return self::short_text();

            case Result::REASON_CONDITIONS_NOT_MET:
                return __( 'Not available for your account', 'yayloyalty' );
        }

        return RedemptionService::message_for( $reason );
    }

    /**
     * "{value} off here" when this cart gets less than the reward's face value: its amount for a fixed reward, its
     * percent of the same base, uncapped, for a percent one, which the quote falls short of only when the cap
     * lowered it (bound_by `reward_max`). '' otherwise.
     */
    private static function value_here( Reward $reward, Result $quote ): string {
        $decimals = wc_get_price_decimals();
        $value    = round( $quote->get_value(), $decimals );

        if ( RewardType::PERCENT_COUPON === $reward->get_type() ) {
            $is_short = Clamp::BOUND_REWARD_MAX === $quote->get_bound_by();
        } else {
            $is_short = $value < round( (float) ( $reward->get_config()['amount'] ?? 0 ), $decimals );
        }

        if ( ! $is_short ) {
            return '';
        }

        /* translators: %s: money amount, as "$3". */
        return sprintf( __( '%s off here', 'yayloyalty' ), LauncherCopy::money( $value ) );
    }

    /** "Not enough Points", with the merchant's label: a row the balance cannot cover. */
    public static function short_text(): string {
        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( 'Not enough %s', 'yayloyalty' ), SettingsHelper::points_label_plural() );
    }

    private static function template( array $view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the included template reads $view.
        ob_start();
        include __DIR__ . '/templates/rewards-panel.php';

        return (string) ob_get_clean();
    }
}
