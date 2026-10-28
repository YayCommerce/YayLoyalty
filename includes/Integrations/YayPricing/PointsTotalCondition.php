<?php
namespace YayLoyalty\Integrations\YayPricing;

use YAYDP\Condition\YAYDP_Comparators;
use YAYDP\Condition\YAYDP_Condition_Context;
use YAYDP\Condition\YAYDP_Condition_Registry;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * A YayPricing condition comparing one of the customer's points totals (CustomerPointsTotals) with the
 * rule's number. One class, one instance per metric. It extends YayPricing's base class, so only
 * YayPricingIntegration::register_conditions() names it: autoloading it earlier, or without YayPricing, is a fatal.
 */
final class PointsTotalCondition extends \YAYDP\Abstracts\YAYDP_Condition_Type {

    /** @var string */
    private $slug;

    /** @var string A CustomerPointsTotals metric. */
    private $metric;

    /** @var CustomerPointsTotals */
    private $totals;

    public function __construct( string $slug, string $metric, CustomerPointsTotals $totals ) {
        $this->slug   = $slug;
        $this->metric = $metric;
        $this->totals = $totals;
    }

    public function slug() {
        return $this->slug;
    }

    public function label() {
        $points = SettingsHelper::points_label_plural();

        switch ( $this->metric ) {
            case CustomerPointsTotals::SPENT:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s spent', 'yayloyalty' ), $points );
            case CustomerPointsTotals::EARNED_MONTH:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s earned this month', 'yayloyalty' ), $points );
            case CustomerPointsTotals::SPENT_MONTH:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s spent this month', 'yayloyalty' ), $points );
            default:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s earned', 'yayloyalty' ), $points );
        }
    }

    public function group() {
        return YAYDP_Condition_Registry::GROUP_CUSTOMER;
    }

    public function comparators() {
        return YAYDP_Comparators::numeric();
    }

    public function editor() {
        return [ 'kind' => 'number' ];
    }

    public function default_value() {
        return 100;
    }

    /** Guests never match; a customer without a row compares 0. */
    public function check( array $condition, YAYDP_Condition_Context $ctx ) {
        if ( ! $ctx->is_logged_in() ) {
            return false;
        }

        return YAYDP_Comparators::compare_numeric( $this->totals->get( (int) $ctx->user_id(), $this->metric ), $condition );
    }
}
