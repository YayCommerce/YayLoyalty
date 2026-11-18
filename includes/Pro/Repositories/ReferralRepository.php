<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
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

    /** The referral whose first order is $order_id, or null. */
    public function find_by_first_order( int $order_id ): ?Referral {
        $found = $this->find_one_by( 'first_order_id', (string) $order_id );

        return $found instanceof Referral ? $found : null;
    }

    /**
     * Up to $limit referrals in $status whose $date_column has passed, after $after_id, by id.
     *
     * @param string $date_column `hold_until` or `coupon_expires_at`.
     * @return Referral[]
     */
    public function due( string $status, string $date_column, int $after_id, int $limit ): array {
        global $wpdb;

        $column = 'hold_until' === $date_column ? 'hold_until' : 'coupon_expires_at';
        $rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . "` WHERE status = %s AND `{$column}` <= %s AND id > %d ORDER BY id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table is escaped and the column is one of two fixed names.
                $status,
                Dates::now(),
                $after_id,
                $limit
            ),
            ARRAY_A
        );

        return array_values( array_filter( array_map( [ $this, 'hydrate' ], is_array( $rows ) ? $rows : [] ), static fn( $model ): bool => $model instanceof Referral ) );
    }

    /**
     * Expires a pending referral.
     *
     * @throws QueryException When the write fails.
     */
    public function expire( int $id ): bool {
        return $this->transition( $id, ReferralStatus::PENDING, [ 'status' => ReferralStatus::EXPIRED ] );
    }

    /**
     * Marks a paid referral reversed once its points are clawed back in full.
     *
     * @throws QueryException When the write fails.
     */
    public function mark_reversed( int $id ): bool {
        return $this->transition( $id, ReferralStatus::REWARDED, [ 'status' => ReferralStatus::REVERSED ] );
    }

    /**
     * Starts the hold on a pending referral.
     *
     * @return bool False when the referral was no longer pending.
     * @throws QueryException When the write fails.
     */
    public function hold( int $id, int $first_order_id, string $hold_until ): bool {
        return $this->transition(
            $id,
            ReferralStatus::PENDING,
            [
                'status'         => ReferralStatus::ON_HOLD,
                'first_order_id' => $first_order_id,
                'hold_until'     => $hold_until,
            ]
        );
    }

    /**
     * Marks a held referral paid.
     *
     * @return bool False when it was no longer on hold.
     * @throws QueryException When the write fails.
     */
    public function mark_rewarded( int $id ): bool {
        return $this->transition( $id, ReferralStatus::ON_HOLD, [ 'status' => ReferralStatus::REWARDED ] );
    }

    /**
     * Rejects a referral that is still in $from.
     *
     * @return bool False when the referral had already moved on.
     * @throws QueryException When the write fails.
     */
    public function reject( int $id, string $reason, string $from = ReferralStatus::PENDING ): bool {
        ReferralRejectReason::assert( $reason );

        return $this->transition(
            $id,
            $from,
            [
                'status'        => ReferralStatus::REJECTED,
                'reject_reason' => $reason,
            ]
        );
    }

    /**
     * Writes $data only while the referral is in $from, so two writers can't both move it.
     *
     * @param array<string, mixed> $data
     * @return bool Whether this call moved it.
     * @throws QueryException When the write fails.
     */
    private function transition( int $id, string $from, array $data ): bool {
        global $wpdb;

        $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $this->table(),
            $data + [ 'updated_at' => Dates::now() ],
            [
                'id'     => $id,
                'status' => $from,
            ]
        );

        if ( false === $result ) {
            throw new QueryException( 'update', esc_html( $wpdb->last_error ), esc_html( $this->table() ) );
        }

        return 1 === $result;
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
