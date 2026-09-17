<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_rewards.
 *
 * No caching, same reasoning as CampaignRepository: nothing here yet knows the
 * right cache key or how it would invalidate, and the table is small. That
 * belongs to whichever task builds the redemption engine.
 *
 * Plain object, not a singleton — see docs/code-standards.md section 8.
 */
final class RewardRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'rewards';
    }

    protected function model_class(): string {
        return Reward::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'name', 'sort_order', 'status', 'created_at', 'updated_at' ];
    }

    public function find_reward( int $id ): ?Reward {
        $model = $this->find( $id );

        return $model instanceof Reward ? $model : null;
    }

    public function find_by_slug( string $slug ): ?Reward {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE slug = %s LIMIT 1',
                $slug
            ),
            ARRAY_A
        );

        return is_array( $row ) ? Reward::from_row( $row ) : null;
    }

    /**
     * The liability/worth valuation rate: the built-in flexible-discount
     * reward's `points_per_currency_unit`. 0 when the reward or key is
     * missing. Lives here (a reward-config read) rather than on
     * DashboardRepository, which delegates to this method — one
     * implementation for both the dashboard's liability stat and the
     * customer drawer's `worth` stat.
     */
    public function points_per_currency_unit(): float {
        $reward = $this->find_by_slug( 'cart-discount' );

        return null === $reward ? 0.0 : (float) ( $reward->get_config()['points_per_currency_unit'] ?? 0 );
    }

    /**
     * Paginated list with optional type/status filters.
     *
     * @param array $args Plus 'type' and 'status'.
     * @return array{items: Reward[], total: int, page: int, per_page: int}
     */
    public function paginate_filtered( array $args = [] ): array {
        global $wpdb;

        $where = [];

        if ( ! empty( $args['type'] ) ) {
            $where[] = $wpdb->prepare( 'type = %s', $args['type'] );
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = $wpdb->prepare( 'status = %s', $args['status'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = $this->like_fragment( 'name', (string) $args['search'] );
        }

        $args['where'] = $where;

        if ( ! isset( $args['orderby'] ) ) {
            $args['orderby'] = 'sort_order';
        }

        return $this->paginate( $args );
    }

    /**
     * Insert a built-in row if its slug is not already present.
     *
     * Idempotent by slug so repeated installs cannot duplicate it, and so a
     * merchant's edits to the row are never overwritten by a later install
     * run. Runs under the installer's upgrade mutex, so two concurrent
     * requests cannot both seed; the unique key on slug is the backstop if
     * they somehow do.
     */
    public function seed_builtin( Reward $reward ): void {
        if ( null !== $this->find_by_slug( $reward->get_slug() ) ) {
            return;
        }

        $this->insert( $reward );
    }
}
