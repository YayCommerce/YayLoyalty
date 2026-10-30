<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Models\Reward;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_rewards. No cache yet: the hottest reads are one indexed slug lookup per storefront page and one per points earned email. */
final class RewardRepository extends AbstractRepository {

    /** The built-in flexible-discount reward the installer seeds. Its config holds the one points→money rate. */
    public const FLEXIBLE_DISCOUNT_SLUG = 'cart-discount';

    /** Draws before giving up and letting the unique key refuse the insert. */
    private const COUPON_CODE_ATTEMPTS = 5;

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
        $model = $this->find_one_by( 'slug', $slug );

        return $model instanceof Reward ? $model : null;
    }

    public function find_by_coupon_code( string $code ): ?Reward {
        $model = $this->find_one_by( 'coupon_code', $code );

        return $model instanceof Reward ? $model : null;
    }

    /** Gives a reward without a code its generated one before the row is written. */
    public function insert( AbstractModel $model ): int {
        if ( $model instanceof Reward && '' === $model->get_coupon_code() ) {
            $model->set_coupon_code( $this->unique_coupon_code() );
        }

        return parent::insert( $model );
    }

    /**
     * Active rewards of these types that are live at $now, in `sort_order ASC, id ASC`.
     *
     * @param string[] $types RewardType constants.
     * @param string   $now   UTC MySQL datetime.
     * @return Reward[]
     */
    public function live_of_types( array $types, string $now ): array {
        global $wpdb;

        if ( [] === $types ) {
            return [];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Sql::in_placeholders() emits only a fixed-count %s list; values are bound via prepare() below.
        $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE status = %s AND type IN (' . Sql::in_placeholders( $types ) . ') ORDER BY sort_order ASC, id ASC',
                array_merge( [ Status::ACTIVE ], array_values( $types ) )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        $live = [];

        foreach ( (array) $rows as $row ) {
            $reward = $this->hydrate( $row );

            if ( $reward instanceof Reward && $reward->is_live_at( $now ) ) {
                $live[] = $reward;
            }
        }

        return $live;
    }

    /** The sort_order that places a new reward after every existing one, archived rows included. */
    public function next_sort_order(): int {
        global $wpdb;

        $max = $wpdb->get_var( 'SELECT MAX(sort_order) FROM `' . esc_sql( $this->table() ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        return null === $max ? 0 : (int) $max + 1;
    }

    /**
     * Renumbers every row 0..n-1: $ids first in the given order, the rest
     * after them in their current order. Renumbering all rows keeps an
     * unlisted (e.g. archived) row from tying with a listed one. One
     * statement, so a failure never leaves half a reorder. False, with
     * nothing written, when $ids names a row that does not exist.
     *
     * @param int[] $ids
     */
    public function reorder( array $ids ): bool {
        global $wpdb;

        $table   = esc_sql( $this->table() );
        $listed  = array_values( array_map( 'intval', $ids ) );
        $current = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM `{$table}` ORDER BY sort_order ASC, id ASC" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( [] !== array_diff( $listed, $current ) ) {
            return false;
        }

        $order = array_merge( $listed, array_values( array_diff( $current, $listed ) ) );

        $this->bulk_set_int_column( 'sort_order', array_flip( $order ) );

        return true;
    }

    /** The built-in flexible-discount reward's `points_per_currency_unit`, or 0 unless that reward is live. */
    public function live_points_per_currency_unit(): float {
        return self::rate_of( $this->find_live_flexible_discount( Dates::now() ) );
    }

    /**
     * The built-in reward only while it is live at $now: active, and inside its start and end dates.
     */
    public function find_live_flexible_discount( string $now ): ?Reward {
        $reward = $this->find_by_slug( self::FLEXIBLE_DISCOUNT_SLUG );

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

    public function seed_builtin( Reward $reward ): void {
        $this->seed_builtin_by_slug( $reward, $reward->get_slug() );
    }

    /** A code no reward and no WooCommerce coupon uses yet; after the last attempt, a fresh draw, which the unique key backs. */
    private function unique_coupon_code(): string {
        for ( $attempt = 1; $attempt < self::COUPON_CODE_ATTEMPTS; $attempt++ ) {
            $code = self::draw_coupon_code();

            if ( ! $this->is_coupon_code_taken( $code ) ) {
                return $code;
            }
        }

        return self::draw_coupon_code();
    }

    /** EarnContext::COUPON_CODE_PREFIX plus COUPON_CODE_LENGTH random characters of COUPON_CODE_ALPHABET. */
    private static function draw_coupon_code(): string {
        $alphabet = EarnContext::COUPON_CODE_ALPHABET;
        $last     = strlen( $alphabet ) - 1;
        $code     = EarnContext::COUPON_CODE_PREFIX;

        for ( $i = 0; $i < EarnContext::COUPON_CODE_LENGTH; $i++ ) {
            $code .= $alphabet[ random_int( 0, $last ) ];
        }

        return $code;
    }

    /** Whether a reward or a WooCommerce coupon already uses $code. */
    private function is_coupon_code_taken( string $code ): bool {
        if ( null !== $this->find_by_coupon_code( $code ) ) {
            return true;
        }

        return function_exists( 'wc_get_coupon_id_by_code' ) && 0 !== (int) wc_get_coupon_id_by_code( $code );
    }
}
