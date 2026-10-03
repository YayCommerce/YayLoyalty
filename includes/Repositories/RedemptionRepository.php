<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_redemptions. Inserted only by Engine\Redemption\CommitWriter
 * under the customer's row lock; read by the commit's duplicate check, the
 * `redemption_count` reward condition and the customer drawer. Scaffold:
 * stubs; the table itself is Schema\RedemptionsTable, registered in
 * Installer::schemas() when redemption ships.
 */
final class RedemptionRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'redemptions';
    }

    protected function model_class(): string {
        return Redemption::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'created_at', 'status', 'points' ];
    }

    public function find_redemption( int $id ): ?Redemption {
        $found = $this->find( $id );

        return $found instanceof Redemption ? $found : null;
    }

    /**
     * The double-debit guard's read: the flexible redemption already
     * written for this order, if any. Postcondition: at most one row
     * (UNIQUE (order_id, reward_id) on the table); null when none.
     */
    public function find_by_order( int $order_id, int $reward_id ): ?Redemption { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): SELECT * WHERE order_id = %d AND reward_id = %d LIMIT 1, hydrated.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * How many times this customer has redeemed this reward, for the
     * `redemption_count` condition. Postcondition: COUNT(*) over the
     * (reward_id, user_id) index, statuses other than cancelled.
     */
    public function count_for_customer( int $reward_id, int $user_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): SELECT COUNT(*) WHERE reward_id = %d AND user_id = %d AND status <> 'cancelled'.
        throw new \RuntimeException( 'Not implemented' );
    }
}
