<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Enums\TierMode;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\Tier;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A customer's tier standing as shown to people: their tier, the next one, and the total that counts toward it.
 * The drawer's tier route and the launcher's tier line (TierProgress) both read it, so the two can't drift.
 * It only reads; TierEvaluator is the only writer of the tier columns.
 */
final class TierStanding {

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( LedgerRepository $ledger ) {
        $this->ledger = $ledger;
    }

    /**
     * `tier` is null when the stored tier is deleted; `next` is the lowest live tier ranked above it, or the
     * lowest tier when the customer has none. In Rolling, `progress` is the window total read now, so a row
     * that aged out shows before the next evaluation. The hold dates are null unless the hold is still running.
     *
     * @param Tier[] $live TierRepository::live(), in rank order.
     * @param string $now  UTC MySQL datetime.
     * @return array{tier: ?Tier, next: ?Tier, progress: int, mode: string, window_months: ?int, held_from: ?string, held_until: ?string}
     */
    public function of( Customer $customer, array $live, string $now ): array {
        $tier = null;

        foreach ( $live as $candidate ) {
            if ( $candidate->get_id() === $customer->get_tier_id() ) {
                $tier = $candidate;
                break;
            }
        }

        $rank = null === $tier ? 0 : $tier->get_tier_rank();
        $next = null;

        foreach ( $live as $candidate ) {
            if ( $candidate->get_tier_rank() > $rank ) {
                $next = $candidate;
                break;
            }
        }

        $mode       = TierEvaluator::mode();
        $months     = TierMode::ROLLING === $mode ? TierEvaluator::rolling_months() : null;
        $held_until = $customer->get_tier_held_until();
        $is_held    = null !== $held_until && $held_until > $now;

        if ( null !== $months ) {
            $progress = $this->ledger->window_total( $customer->get_user_id(), Dates::add_months( $now, -$months ), $now );
        } else {
            $progress = $customer->get_tier_progress_value();
            // The evaluator's last value; a customer never evaluated reads their points earned.
            $progress = null === $progress ? $customer->get_lifetime_earned() : (int) $progress;
        }

        return [
            'tier'          => $tier,
            'next'          => $next,
            'progress'      => $progress,
            'mode'          => $mode,
            'window_months' => $months,
            'held_from'     => $is_held ? $customer->get_tier_held_from() : null,
            'held_until'    => $is_held ? $held_until : null,
        ];
    }
}
