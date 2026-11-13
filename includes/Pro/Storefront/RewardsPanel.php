<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Redemption\ProgramGate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The card's reward rows: one checkbox row per reward RedemptionService::reward_options() offers, listed after the
 * Points discount row and ticked to apply: its name and cost only. Draws only; redeem.js posts the ticked ids and
 * the server answers a new card. It also lists the same rewards, with a one-line description, under "Ways to
 * redeem" in the launcher and My Account.
 */
final class RewardsPanel {

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( RedeemCard::ROWS_FILTER, [ $this, 'rows' ], 10, 2 );
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
        // A reward the shopper's conditions exclude is not theirs to see; RedemptionService still refuses it at commit.
        $options = array_filter(
            $service->reward_options( $cart, $user_id ),
            static function ( array $option ): bool {
                return Result::REASON_CONDITIONS_NOT_MET !== $option['quote']->get_reason();
            }
        );

        if ( [] === $options || self::is_refused( $options ) ) {
            return $rows;
        }

        foreach ( self::row_views( $options, $service->applied_reward_costs( $cart, $user_id ), $balance ) as $row ) {
            $rows[] = self::template( $row );
        }

        return $rows;
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
