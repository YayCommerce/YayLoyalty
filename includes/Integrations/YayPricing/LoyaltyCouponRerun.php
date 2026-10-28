<?php
namespace YayLoyalty\Integrations\YayPricing;

use YayLoyalty\Engine\Points\Context as EarnContext;

defined( 'ABSPATH' ) || exit;

/**
 * YayPricing evaluates its rules on woocommerce_before_calculate_totals at 110 to 112, and
 * CouponProvider::sync_cart() adds or removes the loyalty coupons at PHP_INT_MAX, after it. On the run
 * that applies or removes a reward, "Loyalty reward applied" would read the old coupons. This runs the
 * totals once more when the outermost run changed the loyalty coupons, and never more than once.
 *
 * It does not wait for a rule to ask the condition: YayPricing skips evaluating while its cart signature
 * (items, coupons, shipping) is unchanged, so on the run that drops a reward nothing asks, yet the
 * discount it kept still depends on the coupons. The re-run sees a new signature and evaluates again.
 * YayPricing also runs the totals inside its own hooks, so only the outermost run snapshots and compares.
 */
final class LoyaltyCouponRerun {

    /** @var string[]|null The sorted loyalty codes before YayPricing ran, in the outermost run. */
    private $before = null;

    /** @var int How many totals runs are open: the outermost is 1. */
    private $depth = 0;

    /** @var bool */
    private $is_rerunning = false;

    public function register(): void {
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'snapshot' ], 100 );
        add_action( 'woocommerce_after_calculate_totals', [ $this, 'rerun_if_changed' ], PHP_INT_MAX );
    }

    public function snapshot( \WC_Cart $cart ): void {
        if ( $this->is_rerunning ) {
            return;
        }

        ++$this->depth;

        if ( 1 === $this->depth ) {
            $this->before = self::sorted_loyalty_codes( $cart );
        }
    }

    public function rerun_if_changed( \WC_Cart $cart ): void {
        if ( $this->is_rerunning || $this->depth < 1 ) {
            return;
        }

        --$this->depth;

        if ( $this->depth > 0 || null === $this->before ) {
            return;
        }

        $is_stale     = self::sorted_loyalty_codes( $cart ) !== $this->before;
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
