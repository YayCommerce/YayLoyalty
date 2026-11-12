<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Re-evaluates a customer's tier after each committed ledger row. `yayloyalty_ledger_recorded` fires after
 * the outermost commit, so the evaluator never runs inside LedgerService's lock. A failed evaluation is
 * logged and swallowed: the ledger write already committed, and the next write or sweep corrects the tier.
 */
final class TierLedgerListener {

    private const SOURCE = 'TierLedgerListener';

    public function register(): void {
        // After CustomerActivityListener (10), so a membership row precedes the tier row in the log.
        add_action( 'yayloyalty_ledger_recorded', [ $this, 'on_recorded' ], 20 );
    }

    /**
     * Checks the type, because a third party could fire `yayloyalty_ledger_recorded` with anything.
     *
     * @param mixed $entry Expected to be a LedgerEntry; anything else is ignored.
     */
    public function on_recorded( $entry ): void {
        if ( ! $entry instanceof LedgerEntry ) {
            return;
        }

        ListenerGuard::run(
            self::SOURCE,
            'tier evaluation failed for user #' . $entry->get_user_id(),
            static function () use ( $entry ) {
                TierEvaluator::build()->on_ledger_entry( $entry );
            }
        );
    }
}
