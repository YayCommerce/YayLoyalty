<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Where an order's loyalty coupon lines and its redemption rows disagree, as orders committed before lines
 * were reconciled can: a line nothing paid for, or an `applied` row with points still out and no line.
 * Reads only. A stale row can also be an admin's "Don't restore", which looks the same, so the admin decides.
 * RedemptionService::repair_mismatch() acts on what this finds.
 */
final class RewardMismatch {

    /** A loyalty coupon line with no redemption row: the customer got the discount and paid no points. */
    public const KIND_UNPAID_LINE = 'unpaid_line';

    /** An `applied` row whose line is gone: the customer paid points for a discount the order does not carry. */
    public const KIND_STALE_ROW = 'stale_row';

    /** Statuses an order can be repaired in: a dead order's rows are settled by its cancel or refund. */
    private const LIVE_STATUSES = [ 'pending', 'on-hold', 'processing', 'completed' ];

    private RedemptionRepository $redemptions;

    private RewardRepository $rewards;

    private ContextFactory $contexts;

    public function __construct( RedemptionRepository $redemptions, RewardRepository $rewards, ContextFactory $contexts ) {
        $this->redemptions = $redemptions;
        $this->rewards     = $rewards;
        $this->contexts    = $contexts;
    }

    public static function build(): self {
        return new self( new RedemptionRepository(), new RewardRepository(), new ContextFactory( new CustomerRepository() ) );
    }

    /**
     * The order's mismatches. `points` is what repairing one moves; `reward` is null once the reward is deleted,
     * which leaves an unpaid line with no price to debit.
     *
     * @return array<int, array{kind: string, code: string, points: int, reward: ?Reward}>
     */
    public function for_order( \WC_Order $order ): array {
        if ( $order->get_customer_id() < 1 || ! in_array( $order->get_status(), self::LIVE_STATUSES, true ) ) {
            return [];
        }

        $lines = array_map( 'wc_format_coupon_code', EarnContext::loyalty_codes( $order->get_coupon_codes() ) );
        $rows  = $this->redemptions->for_order( (int) $order->get_id() );
        $coded = array_map(
            'wc_format_coupon_code',
            array_filter( array_map( static fn( Redemption $row ): string => (string) $row->get_coupon_code(), $rows ) )
        );
        $found = [];

        foreach ( array_diff( $lines, $coded ) as $code ) {
            $reward  = $this->rewards->find_by_coupon_code( $code );
            $found[] = self::mismatch( self::KIND_UNPAID_LINE, $code, null === $reward ? 0 : $this->line_points( $order, $reward ), $reward );
        }

        foreach ( $rows as $row ) {
            $code   = wc_format_coupon_code( (string) $row->get_coupon_code() );
            $points = $row->get_points() - $row->get_restored_points();

            if ( '' !== $code && Redemption::STATUS_APPLIED === $row->get_status() && $points > 0 && ! in_array( $code, $lines, true ) ) {
                $found[] = self::mismatch( self::KIND_STALE_ROW, $code, $points, $this->rewards->find_reward( $row->get_reward_id() ) );
            }
        }

        return $found;
    }

    /**
     * The order's mismatch for $code, or null.
     *
     * @return array{kind: string, code: string, points: int, reward: ?Reward}|null
     */
    public function find( \WC_Order $order, string $code ): ?array {
        $code = wc_format_coupon_code( $code );

        foreach ( $this->for_order( $order ) as $mismatch ) {
            if ( $code === $mismatch['code'] ) {
                return $mismatch;
            }
        }

        return null;
    }

    /** What an unpaid line costs: the reward's fixed cost, or for the flexible discount the points its line's value stands for. */
    private function line_points( \WC_Order $order, Reward $reward ): int {
        return RedemptionService::is_flexible_discount( $reward ) ? (int) $this->contexts->from_order( $order, $reward, 0 )->get_points_requested() : (int) $reward->get_cost_points();
    }

    /** @return array{kind: string, code: string, points: int, reward: ?Reward} */
    private static function mismatch( string $kind, string $code, int $points, ?Reward $reward ): array {
        return [
            'kind'   => $kind,
            'code'   => $code,
            'points' => $points,
            'reward' => $reward,
        ];
    }
}
