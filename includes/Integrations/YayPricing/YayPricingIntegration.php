<?php
namespace YayLoyalty\Integrations\YayPricing;

use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Lets merchants base YayPricing rules on loyalty activity: points earned and spent, lifetime and this
 * month. The condition classes extend YayPricing's own, so they are first named inside YayPricing's
 * yaydp_register_conditions hook: autoloading them earlier, or without YayPricing, is a fatal.
 */
class YayPricingIntegration {

    use SingletonTrait;

    /** The first YayPricing with the condition registry and its yaydp_register_conditions action. */
    public const MIN_YAYPRICING_VERSION = '3.5.8';

    protected function __construct() {
        if ( ! self::is_active() ) {
            return;
        }

        add_action( 'yaydp_register_conditions', [ $this, 'register_conditions' ] );
    }

    /** YayPricing's main file defines YAYDP_VERSION before YayLoyalty boots. */
    public static function is_active(): bool {
        return defined( 'YAYDP_VERSION' ) && version_compare( YAYDP_VERSION, self::MIN_YAYPRICING_VERSION, '>=' );
    }

    /** @param \YAYDP\Condition\YAYDP_Condition_Registry $registry */
    public function register_conditions( $registry ): void {
        $totals = new CustomerPointsTotals();

        $registry->register( new PointsTotalCondition( 'yayloyalty_points_earned', CustomerPointsTotals::EARNED, $totals ) );
        $registry->register( new PointsTotalCondition( 'yayloyalty_points_spent', CustomerPointsTotals::SPENT, $totals ) );
        $registry->register( new PointsTotalCondition( 'yayloyalty_points_earned_this_month', CustomerPointsTotals::EARNED_MONTH, $totals ) );
        $registry->register( new PointsTotalCondition( 'yayloyalty_points_spent_this_month', CustomerPointsTotals::SPENT_MONTH, $totals ) );
    }
}
