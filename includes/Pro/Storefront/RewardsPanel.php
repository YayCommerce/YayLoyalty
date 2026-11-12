<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\RewardGate;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Engine\Storefront\OfferLines;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\CurrencyRate;

defined( 'ABSPATH' ) || exit;

/**
 * The card's reward rows: one checkbox row per reward RedemptionService::reward_options() offers, listed after the
 * Points discount row and ticked to apply: its name and cost only. Draws only; redeem.js posts the ticked ids and
 * the server answers a new card. It also lists the same rewards, with a one-line description, under "Ways to
 * redeem" in the launcher and My Account. A reward whose conditions the viewer fails is locked there, with its
 * teaser in place of the description, and left off the card, which only lists what the shopper may claim.
 */
final class RewardsPanel {

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( RedeemCard::ROWS_FILTER, [ $this, 'rows' ], 10, 2 );
        add_filter( LauncherPayload::REDEEM_OFFERS_FILTER, [ $this, 'redeem_offers' ], 10, 3 );
    }

    /**
     * Appends every live `fixed_coupon` / `percent_coupon` reward, in `sort_order`, whatever the member's balance:
     * there is no cart to check it against. Conditions are checked for the viewer (offer_for()).
     *
     * @param array<int, array{name: string, cost: int, text: string}> $offers
     * @param string                                                   $now     UTC MySQL datetime.
     * @param int                                                      $user_id The viewer; 0 for a guest.
     * @return array<int, array{name: string, cost: int, text: string, locked?: bool, teaser?: string}>
     */
    public function redeem_offers( array $offers, string $now, int $user_id = 0 ): array {
        foreach ( ( new RewardRepository() )->live_of_types( [ RewardType::FIXED_COUPON, RewardType::PERCENT_COUPON ], $now ) as $reward ) {
            $offer = self::offer_for( $reward, $user_id, $now );

            if ( null !== $offer ) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    /**
     * One live reward's "Ways to redeem" row for the viewer: a plain row when it has no conditions or they hold;
     * locked, with its teaser, when they fail; null when they fail and there is no teaser to show, or bonus
     * offers are switched off. A guest fails any condition, so a cached guest page holds nothing personal.
     *
     * @param string $now UTC MySQL datetime.
     * @return array{name: string, cost: int, text: string, locked?: bool, teaser?: string}|null
     */
    private static function offer_for( Reward $reward, int $user_id, string $now ): ?array {
        if ( self::conditions_hold( $reward, $user_id, $now ) ) {
            return LauncherPayload::offer( $reward );
        }

        $teaser = OfferLines::enabled() ? OfferLines::teaser( $reward->get_config(), $reward->get_conditions() ) : null;

        if ( null === $teaser ) {
            return null;
        }

        return LauncherPayload::offer( $reward ) + [
            'locked' => true,
            'teaser' => $teaser,
        ];
    }

    /**
     * Whether the reward's conditions hold for the viewer, through the gate's own seam: always with none, never for a
     * guest otherwise. The balance is unbounded so it never decides, and liveness is the caller's: the conditions
     * alone say whether the reward is for them.
     *
     * @param string $now UTC MySQL datetime.
     */
    private static function conditions_hold( Reward $reward, int $user_id, string $now ): bool {
        if ( [] === $reward->get_conditions() ) {
            return true;
        }

        if ( $user_id < 1 ) {
            return false;
        }

        $currency = CurrencyRate::current();
        $ctx      = new Context( $user_id, PHP_INT_MAX, null, null, $currency, $now, null, null, CurrencyRate::rate( $currency ) );

        /** Documented in RewardGate::check(). */
        return (bool) apply_filters( RewardGate::CONDITIONS_FILTER, true, $reward, $ctx ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name.
    }

    /**
     * Appends one row's HTML per reward to the card's rows, each its own list item. Unchanged when there is no
     * option, or when every option is refused by a program gate.
     *
     * @param string[] $rows
     * @param array    $context RedeemCard::ROWS_FILTER's context.
     * @return string[]
     */
    public function rows( array $rows, array $context ): array {
        $service = $context['service'] ?? null;
        $cart    = $context['cart'] ?? null;

        if ( ! $service instanceof RedemptionService || ! $cart instanceof \WC_Cart ) {
            return $rows;
        }

        $user_id = (int) $context['user_id'];
        $balance = (int) $context['balance'];
        $options = $service->reward_options( $cart, $user_id );

        if ( [] === $options || self::is_refused( $options ) ) {
            return $rows;
        }

        $options = self::claimable( $options );

        if ( [] === $options ) {
            return $rows;
        }

        foreach ( self::row_views( $options, $service->applied_reward_costs( $cart, $user_id ), $balance ) as $row ) {
            $rows[] = self::template( $row );
        }

        return $rows;
    }

    /**
     * The options whose conditions hold for the shopper: the gate reports failed conditions before a short balance,
     * so its reason alone says. A disabled row for a reward the conditions rule out would only invite the shopper
     * to earn towards something they can't claim.
     *
     * @param array<int, array{reward: Reward, quote: Result}> $options
     * @return array<int, array{reward: Reward, quote: Result}>
     */
    private static function claimable( array $options ): array {
        return array_filter(
            $options,
            static function ( array $option ): bool {
                return Result::REASON_CONDITIONS_NOT_MET !== $option['quote']->get_reason();
            }
        );
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
     * What each row prints. A row the server refuses is `denied`. A row that is not applied and would take the
     * applied rewards past the balance is `is_short`, re-enabled by redeem.js as other ticks drop.
     *
     * @param array<int, array{reward: Reward, quote: Result}> $options
     * @param array<int, int>                                  $applied RedemptionService::applied_reward_costs().
     * @return array<int, array{id: int, name: string, cost: int, is_applied: bool, is_denied: bool, is_short: bool}>
     */
    private static function row_views( array $options, array $applied, int $balance ): array {
        $rows  = [];
        $spent = array_sum( $applied );

        foreach ( $options as $reward_id => $option ) {
            $reward     = $option['reward'];
            $cost       = (int) $reward->get_cost_points();
            $is_applied = isset( $applied[ $reward_id ] );
            $is_denied  = ! $option['quote']->is_allowed();

            $rows[] = [
                'id'         => (int) $reward_id,
                'name'       => $reward->get_name(),
                'cost'       => $cost,
                'is_applied' => $is_applied,
                'is_denied'  => $is_denied,
                'is_short'   => ! $is_denied && ! $is_applied && $spent + $cost > $balance,
            ];
        }

        return $rows;
    }

    private static function template( array $view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the included template reads $view.
        ob_start();
        include __DIR__ . '/templates/rewards-panel.php';

        return (string) ob_get_clean();
    }
}
