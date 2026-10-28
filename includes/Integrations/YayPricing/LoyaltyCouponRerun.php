<?php
namespace YayLoyalty\Integrations\YayPricing;

use YayLoyalty\Engine\Points\Context as EarnContext;

defined( 'ABSPATH' ) || exit;

/**
 * YayPricing evaluates its rules on woocommerce_before_calculate_totals at 110 to 112, and
 * CouponProvider::sync_cart() adds or removes the loyalty coupons at PHP_INT_MAX, after it. On the run
 * that applies or removes a reward, "Loyalty reward applied" would read the old coupons. This runs the
 * totals once more when a rule asked that condition during a run that changed the loyalty coupons,
 * and never more than once.
 */
final class LoyaltyCouponRerun {

    /** @var string[]|null The sorted loyalty codes before YayPricing ran. */
    private $before = null;

    /** @var bool Whether RewardAppliedCondition was asked during this run. */
    private $is_asked = false;

    /** @var bool */
    private $is_rerunning = false;

    public function register(): void {
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'snapshot' ], 100 );
        add_action( 'woocommerce_after_calculate_totals', [ $this, 'rerun_if_changed' ], PHP_INT_MAX );
    }

    /** Called by RewardAppliedCondition::check(): this run's answer depends on the loyalty coupons. */
    public function mark_asked(): void {
        $this->is_asked = true;
    }

    /** @param \WC_Cart $cart */
    public function snapshot( $cart ): void {
        if ( $this->is_rerunning || ! $cart instanceof \WC_Cart ) {
            return;
        }

        $this->before   = self::sorted_loyalty_codes( $cart );
        $this->is_asked = false;
    }

    /** @param \WC_Cart $cart */
    public function rerun_if_changed( $cart ): void {
        if ( $this->is_rerunning || null === $this->before || ! $cart instanceof \WC_Cart ) {
            return;
        }

        $is_stale     = $this->is_asked && self::sorted_loyalty_codes( $cart ) !== $this->before;
        $this->before = null;

        if ( ! $is_stale ) {
            return;
        }

        $this->is_rerunning = true;

        try {
            $cart->calculate_totals();
        } finally {
            $this->is_rerunning = false;
        }
    }

    /** @return string[] */
    private static function sorted_loyalty_codes( \WC_Cart $cart ): array {
        $codes = EarnContext::loyalty_codes( $cart->get_applied_coupons() );
        sort( $codes );

        return $codes;
    }
}
