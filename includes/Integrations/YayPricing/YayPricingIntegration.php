<?php
namespace YayLoyalty\Integrations\YayPricing;

use YayLoyalty\Engine\Redemption\RewardCartLines;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Lets merchants base YayPricing rules on loyalty activity: whether the cart holds a loyalty reward and which
 * ones, and points earned and spent, lifetime and this month. The condition classes extend YayPricing's own, so they are first
 * named inside YayPricing's yaydp_register_conditions hook: autoloading them earlier, or without YayPricing, is a fatal.
 */
final class YayPricingIntegration {

    use SingletonTrait;

    /** The first YayPricing with the condition registry and its yaydp_register_conditions action. */
    public const MIN_YAYPRICING_VERSION = '3.5.8';

    protected function __construct() {
        if ( ! self::is_active() ) {
            return;
        }

        add_action( 'yaydp_register_conditions', [ $this, 'register_conditions' ] );
        add_filter( 'yaydp_init_cart_items', [ $this, 'skip_reward_lines' ] );
        ( new LoyaltyCouponRerun() )->register();
    }

    /**
     * Leaves the lines a reward added at 0 out of YayPricing's cart, so they neither count toward its rules nor get
     * priced by them. Marking them as YayPricing's own free items instead would make it delete them.
     *
     * @param mixed $items WooCommerce cart items, keyed by cart item key.
     * @return mixed
     */
    public function skip_reward_lines( $items ) {
        if ( ! is_array( $items ) ) {
            return $items;
        }

        return array_filter(
            $items,
            static function ( $item ): bool {
                return ! RewardCartLines::is_reward_line( $item );
            }
        );
    }

    /** YayPricing's main file defines YAYDP_VERSION before YayLoyalty boots. */
    public static function is_active(): bool {
        return defined( 'YAYDP_VERSION' ) && version_compare( YAYDP_VERSION, self::MIN_YAYPRICING_VERSION, '>=' );
    }

    /** @param \YAYDP\Condition\YAYDP_Condition_Registry $registry */
    public function register_conditions( $registry ): void {
        $totals = new CustomerPointsTotals();

        $registry->register( new RewardAppliedCondition() );
        $registry->register( new AppliedRewardsCondition() );
        $registry->register( new PointsTotalCondition( 'yayloyalty_points_earned', CustomerPointsTotals::EARNED, $totals ) );
        $registry->register( new PointsTotalCondition( 'yayloyalty_points_spent', CustomerPointsTotals::SPENT, $totals ) );
        $registry->register( new PointsTotalCondition( 'yayloyalty_points_earned_this_month', CustomerPointsTotals::EARNED_MONTH, $totals ) );
        $registry->register( new PointsTotalCondition( 'yayloyalty_points_spent_this_month', CustomerPointsTotals::SPENT_MONTH, $totals ) );
    }
}
