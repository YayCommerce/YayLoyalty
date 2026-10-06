<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\Reward;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_rewards. No cache yet: the hottest reads are one indexed slug lookup per storefront page and one per points earned email. */
final class RewardRepository extends AbstractRepository {

    /** The built-in flexible-discount reward the installer seeds. Its config holds the one points→money rate. */
    public const CART_DISCOUNT_SLUG = 'cart-discount';

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

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE slug = %s LIMIT 1',
                $slug
            ),
            ARRAY_A
        );

        return is_array( $row ) ? Reward::from_row( $row ) : null;
    }

    /**
     * The one points→money rate: the built-in flexible-discount reward's
     * `points_per_currency_unit`, or 0 when the reward or key is missing.
     * The dashboard's liability stat, the drawer's money figures and, through
     * the reward's config in Clamp, the checkout discount all use this rate.
     */
    public function points_per_currency_unit(): float {
        return self::rate_of( $this->find_by_slug( self::CART_DISCOUNT_SLUG ) );
    }

    /** The same rate, but 0 unless the built-in reward is live. */
    public function live_points_per_currency_unit(): float {
        return self::rate_of( $this->find_live_cart_discount( Dates::now() ) );
    }

    /**
     * The built-in reward only while it is live at $now: active, and inside its start and end dates.
     * Live is not proof customers can spend it: RedemptionService::is_available() says whether checkout redeems it.
     */
    public function find_live_cart_discount( string $now ): ?Reward {
        $reward = $this->find_by_slug( self::CART_DISCOUNT_SLUG );

        return null !== $reward && $reward->is_live_at( $now ) ? $reward : null;
    }

    /** The rate read from an already-loaded built-in reward, for a caller that also needs the reward itself. */
    public static function rate_of( ?Reward $reward ): float {
        return null === $reward ? 0.0 : (float) ( $reward->get_config()['points_per_currency_unit'] ?? 0 );
    }

    /**
     * @param array $args paginate()'s, plus 'type', 'status' and 'search'.
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

    /** Inserts a built-in row unless its slug is present, so a later install never overwrites a merchant's edits. */
    public function seed_builtin( Reward $reward ): void {
        if ( null !== $this->find_by_slug( $reward->get_slug() ) ) {
            return;
        }

        $this->insert( $reward );
    }
}
