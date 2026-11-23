<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Storefront\AccountMyPoints;
use YayLoyalty\Models\Customer;
use YayLoyalty\Pro\Services\TierEvaluator;
use YayLoyalty\Pro\Services\TierStanding;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * My Points' "Your tier" section: the launcher line's fields (TierProgress::line()), plus the member's multiplier,
 * the date they reached their tier, and every live tier. It reads TierStanding, as the launcher and the drawer do.
 */
final class AccountTier {

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( AccountMyPoints::MEMBER_TIER_FILTER, [ $this, 'member_tier' ], 10, 2 );
    }

    /**
     * Null when tiers are off or no tier is live.
     *
     * @param array|null $value   The filter's value so far.
     * @param int        $user_id The member.
     * @return array<string, mixed>|null AccountMyPoints::MEMBER_TIER_FILTER's shape.
     */
    public function member_tier( $value, int $user_id ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter passes $value.
        if ( ! TierEvaluator::is_enabled() ) {
            return null;
        }

        $live = ( new TierRepository() )->live();

        if ( [] === $live ) {
            return null;
        }

        $customer = ( new CustomerRepository() )->find_by_user_id( $user_id ) ?? new Customer( $user_id );
        $standing = ( new TierStanding( new LedgerRepository() ) )->of( $customer, $live, Dates::now() );
        $tier     = $standing['tier'];
        $tiers    = [];

        foreach ( $live as $candidate ) {
            $tiers[] = [
                'name'        => $candidate->get_name(),
                'color'       => $candidate->get_color(),
                'threshold'   => $candidate->get_threshold(),
                'multiplier'  => $candidate->get_multiplier(),
                'entry_bonus' => $candidate->get_entry_bonus(),
                'is_current'  => null !== $tier && $candidate->get_id() === $tier->get_id(),
            ];
        }

        return TierProgress::line( $tier, $standing['next'], $standing['progress'], $standing['window_months'], $standing['period'], null === $tier ? null : $standing['held_until'] ) + [
            'multiplier'  => null === $tier ? null : $tier->get_multiplier(),
            'achieved_at' => null === $tier ? null : $customer->get_tier_achieved_at(),
            'tiers'       => $tiers,
        ];
    }
}
