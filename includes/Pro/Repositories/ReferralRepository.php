<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Repositories\AbstractRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_referrals, Pro's own table (Pro\Database\ReferralsTable). SQL for this one table only. */
final class ReferralRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'referrals';
    }

    protected function model_class(): string {
        return Referral::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'created_at', 'status', 'hold_until' ];
    }

    /** The referral that brought in $friend_user_id, or null. A friend is referred at most once. */
    public function find_by_friend( int $friend_user_id ): ?Referral {
        $found = $this->find_one_by( 'friend_user_id', (string) $friend_user_id );

        return $found instanceof Referral ? $found : null;
    }

    /** $friend_user_id's referral while it is still pending, or null. */
    public function find_pending_for_friend( int $friend_user_id ): ?Referral {
        $referral = $this->find_by_friend( $friend_user_id );

        return null !== $referral && ReferralStatus::PENDING === $referral->get_status() ? $referral : null;
    }

    /**
     * Records a new friend as a pending referral.
     *
     * @param array<string, mixed> $terms The settings snapshot the referral keeps.
     * @return int The new referral's id.
     * @throws \YayLoyalty\Database\QueryException When the write fails, including a friend already referred.
     */
    public function insert_pending( int $advocate_user_id, int $friend_user_id, string $code, array $terms, string $coupon_expires_at ): int {
        $now = Dates::now();

        return $this->insert(
            new Referral( null, $advocate_user_id, $friend_user_id, $code, ReferralStatus::PENDING, null, $terms, $coupon_expires_at, null, null, $now, $now )
        );
    }
}
