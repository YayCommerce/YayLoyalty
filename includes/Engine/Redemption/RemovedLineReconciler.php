<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Returns a loyalty coupon line's points whenever the line leaves an order: the order screen, REST v3, or any
 * code calling remove_coupon(). Deletions are queued as WooCommerce deletes the items and settled once at the
 * end of the request, because REST v3 removes every coupon line and then re-applies the ones the request keeps:
 * a line still on the order when the request settles was never removed. The order screen's dialog
 * (Admin\OrderCouponRemoval) only decides whether to waive the restore.
 */
final class RemovedLineReconciler {

    private Reversal $reversal;

    /** Read-only: the line's row, for the reward's name in the notes. */
    private RedemptionRepository $redemptions;

    /** Read-only: the reward's name for the notes. */
    private RewardRepository $rewards;

    /** @var array<int, array<string, true>> Order id => formatted code => true, for each loyalty line deleted this request. */
    private array $queue = [];

    /** @var array<int, array<string, true>> Order id => formatted code => true, for each line the admin chose not to restore. */
    private array $waived = [];

    public function __construct( Reversal $reversal, RedemptionRepository $redemptions, RewardRepository $rewards ) {
        $this->reversal    = $reversal;
        $this->redemptions = $redemptions;
        $this->rewards     = $rewards;
    }

    /** Called once at boot, not behind is_admin(): a REST v3 request is not an admin request. */
    public function register(): void {
        add_action( 'woocommerce_before_delete_order_item', [ $this, 'on_item_deleted' ] );
        add_action( 'woocommerce_before_delete_order', [ $this, 'on_order_deleted' ] );
        add_action( 'shutdown', [ $this, 'settle' ], 0 );
    }

    /** Queues a loyalty coupon line WooCommerce is about to delete. */
    public function on_item_deleted( $item_id ): void {
        $item = \WC_Order_Factory::get_order_item( absint( $item_id ) );

        if ( ! $item instanceof \WC_Order_Item_Coupon || ! EarnContext::is_loyalty_code( (string) $item->get_code() ) ) {
            return;
        }

        $this->queue[ (int) $item->get_order_id() ][ wc_format_coupon_code( $item->get_code() ) ] = true;
    }

    /** A permanently deleted order returns nothing for its lines. */
    public function on_order_deleted( $order_id ): void {
        unset( $this->queue[ absint( $order_id ) ] );
    }

    /** The admin chose "Don't restore" for $code on this order: its removal writes no ledger row. */
    public function waive( int $order_id, string $code ): void {
        $this->waived[ $order_id ][ wc_format_coupon_code( $code ) ] = true;
    }

    /**
     * Settles every queued line whose order still exists, is not trashed, and no longer carries the code, and notes each
     * result on the order. Empties the queue first, so a second call does nothing. Never throws: a failing line is logged
     * and noted, and the rest still settle.
     */
    public function settle(): void {
        $queue        = $this->queue;
        $waived       = $this->waived;
        $this->queue  = [];
        $this->waived = [];

        foreach ( $queue as $order_id => $codes ) {
            $order = wc_get_order( $order_id );

            if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() ) {
                continue;
            }

            $kept = array_map( 'wc_format_coupon_code', $order->get_coupon_codes() );

            foreach ( array_keys( $codes ) as $code ) {
                if ( ! in_array( $code, $kept, true ) ) {
                    $this->settle_line( $order, (string) $code, isset( $waived[ $order_id ][ $code ] ) );
                }
            }
        }
    }

    private function settle_line( \WC_Order $order, string $code, bool $waive ): void {
        $row = $this->redemptions->find_by_order_and_coupon_code( (int) $order->get_id(), $code );

        if ( null === $row ) {
            return;
        }

        $name = $this->name_of( $row );

        try {
            $outcome = $this->reversal->coupon_removed( $order, $code, $waive );
        } catch ( \Throwable $e ) {
            Log::error( self::class, sprintf( 'Restoring order #%d coupon %s failed: %s', (int) $order->get_id(), $code, $e->getMessage() ) );
            self::note_failure( $order, $name );

            return;
        }

        if ( null === $outcome ) {
            return;
        }

        if ( Outcome::AWARDED === $outcome->get_status() ) {
            $points = null === $outcome->get_entry() ? 0 : abs( $outcome->get_entry()->get_points() );
            /* translators: 1: the reward's name, as "$5 off", 2: points, as "500 points". */
            $order->add_order_note( sprintf( __( '%1$s removed — %2$s restored.', 'yayloyalty' ), $name, SettingsHelper::points_text( $points ) ), 0, true );

            return;
        }

        if ( Reversal::REASON_KEY_SPENT === $outcome->get_reason() ) {
            Log::error( self::class, sprintf( 'Restoring order #%d coupon %s found its ledger key spent.', (int) $order->get_id(), $code ) );
            self::note_failure( $order, $name );

            return;
        }

        // Only a row that still had points out: an `unpaid` row's points are back already, and a repeat moves nothing.
        if ( $waive && Redemption::STATUS_APPLIED === $row->get_status() ) {
            /* translators: 1: the reward's name, as "$5 off", 2: the admin's display name. */
            $order->add_order_note( sprintf( __( '%1$s removed — points not restored, as chosen by %2$s.', 'yayloyalty' ), $name, wp_get_current_user()->display_name ), 0, true );
        }
    }

    private static function note_failure( \WC_Order $order, string $name ): void {
        /* translators: %s: the reward's name, as "$5 off". */
        $order->add_order_note( sprintf( __( '%s removed, but its points could not be restored. Adjust the customer\'s points by hand.', 'yayloyalty' ), $name ), 0, true );
    }

    /** The reward's name; "Reward" once the reward is deleted. */
    private function name_of( Redemption $row ): string {
        $reward = $this->rewards->find_reward( $row->get_reward_id() );

        return null === $reward ? __( 'Reward', 'yayloyalty' ) : $reward->get_name();
    }
}
