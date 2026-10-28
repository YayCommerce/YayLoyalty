<?php
namespace YayLoyalty\Integrations\YayPricing;

use YayLoyalty\Engine\Points\Context as EarnContext;

defined( 'ABSPATH' ) || exit;

/**
 * YayPricing evaluates its rules on woocommerce_before_calculate_totals at 110 to 112, and
 * CouponProvider::sync_cart() adds or removes the loyalty coupons at PHP_INT_MAX, after it. On the run
 * that applies or removes a reward, "Loyalty reward applied" would read the old coupons. This runs the
 * totals once more when the loyalty coupons changed during a run, and never more than once.
 */
final class LoyaltyCouponRerun {

    /** @var string[]|null The sorted loyalty codes before YayPricing ran. */
    private $before = null;

    /** @var bool */
    private $is_rerunning = false;

    public function register(): void {
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'snapshot' ], 100 );
        add_action( 'woocommerce_after_calculate_totals', [ $this, 'rerun_if_changed' ], PHP_INT_MAX );
    }

    /** @param \WC_Cart $cart */
    public function snapshot( $cart ): void {
        if ( $this->is_rerunning || ! $cart instanceof \WC_Cart ) {
            return;
        }

        $this->before = self::loyalty_codes( $cart );
    }

    /** @param \WC_Cart $cart */
    public function rerun_if_changed( $cart ): void {
        if ( $this->is_rerunning || null === $this->before || ! $cart instanceof \WC_Cart ) {
            return;
        }

        $changed      = self::loyalty_codes( $cart ) !== $this->before;
        $this->before = null;

        if ( ! $changed ) {
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
    private static function loyalty_codes( \WC_Cart $cart ): array {
        $codes = array_values(
            array_filter(
                array_map( 'strval', $cart->get_applied_coupons() ),
                [ EarnContext::class, 'is_loyalty_code' ]
            )
        );

        sort( $codes );

        return $codes;
    }
}
