<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_campaigns. Caches the unfiltered set and filters liveness in
 * PHP: a cache of "live today" would be wrong for a late-processed order.
 * Every write drops the cache. Not final so a test can stub the reads.
 */
class CampaignRepository extends AbstractRepository {

    /** The built-in purchase campaign seeded by the installer. */
    public const DEFAULT_PURCHASE_SLUG = 'default-purchase';

    /** The built-in signup and review campaigns seeded by the installer. */
    public const SIGNUP_SLUG = 'signup';
    public const REVIEW_SLUG = 'review';

    private const CACHE_GROUP = 'yayloyalty';
    private const CACHE_KEY   = 'campaigns_non_archived';
    private const CACHE_TTL   = 3600;

    protected function table_slug(): string {
        return 'campaigns';
    }

    protected function model_class(): string {
        return Campaign::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'name', 'priority', 'status', 'created_at', 'updated_at' ];
    }

    public function find_campaign( int $id ): ?Campaign {
        $model = $this->find( $id );

        return $model instanceof Campaign ? $model : null;
    }

    /**
     * Whether the row exists, read under an exclusive row lock. Inside a transaction: delete_item()
     * holds it so no award can name the campaign between its ledger re-count and the delete.
     *
     * @throws QueryException When the read fails, e.g. a lock wait timeout.
     */
    public function lock_for_delete( int $id ): bool {
        return $this->lock_row( $id, 'FOR UPDATE' );
    }

    /**
     * Whether the row exists, read under a shared row lock. Inside a transaction: LedgerService
     * holds it while it appends an earn row naming the campaign, so a delete waits for the commit.
     *
     * @throws QueryException When the read fails, e.g. a lock wait timeout.
     */
    public function lock_shared( int $id ): bool {
        return $this->lock_row( $id, 'LOCK IN SHARE MODE' );
    }

    /** LOCK IN SHARE MODE, not FOR SHARE: MySQL 5.7 and MariaDB lack the latter. */
    private function lock_row( int $id, string $lock ): bool {
        global $wpdb;

        $found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a locking read must reach the database, never a cache.
            $wpdb->prepare(
                'SELECT id FROM `' . esc_sql( $this->table() ) . '` WHERE id = %d ' . $lock, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $lock is one of two literals above.
                $id
            )
        );

        if ( '' !== $wpdb->last_error ) {
            throw new QueryException( 'select', $wpdb->last_error, $this->table() . ' (lock)' );
        }

        return null !== $found;
    }

    public function find_by_slug( string $slug ): ?Campaign {
        $model = $this->find_one_by( 'slug', $slug );

        return $model instanceof Campaign ? $model : null;
    }

    /**
     * Every non-archived campaign, highest priority first, ties to the newest. One cache key for the table.
     *
     * @return Campaign[]
     */
    public function all_non_archived(): array {
        $cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query on the plugin's own table; this method caches the result.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '`
                 WHERE status != %s
                 ORDER BY priority DESC, id DESC',
                Status::ARCHIVED
            ),
            ARRAY_A
        );

        $campaigns = array_map( [ Campaign::class, 'from_row' ], is_array( $rows ) ? $rows : [] );

        wp_cache_set( self::CACHE_KEY, $campaigns, self::CACHE_GROUP, self::CACHE_TTL );

        return $campaigns;
    }

    /**
     * Campaigns live for a trigger at $at (the event's timestamp, not now()), in selection order.
     *
     * @return Campaign[]
     */
    public function live_for_trigger( string $trigger, string $at ): array {
        return array_values(
            array_filter(
                $this->all_non_archived(),
                static function ( Campaign $campaign ) use ( $trigger, $at ) {
                    return $campaign->get_trigger() === $trigger && $campaign->is_live_at( $at );
                }
            )
        );
    }

    /** The built-in purchase campaign. Null if archived. */
    public function find_default_purchase(): ?Campaign {
        foreach ( $this->all_non_archived() as $campaign ) {
            if ( self::DEFAULT_PURCHASE_SLUG === $campaign->get_slug() ) {
                return $campaign;
            }
        }

        return null;
    }

    /** The priority that puts a new campaign above every other non-archived one of its trigger. */
    public function top_priority( string $trigger ): int {
        // all_non_archived() is highest priority first, so the first match is the top.
        foreach ( $this->all_non_archived() as $campaign ) {
            if ( $campaign->get_trigger() === $trigger ) {
                return $campaign->get_priority() + 1;
            }
        }

        return 0;
    }

    /**
     * Saves a dragged order as priority. Per trigger, the listed campaigns come first in the given
     * order and that trigger's others follow in their current order; each then gets
     * count - 1 - position. False, writing nothing, when an id is not a non-archived campaign.
     *
     * @param int[] $ids
     */
    public function reorder( array $ids ): bool {
        global $wpdb;

        $listed     = array_values( array_map( 'intval', $ids ) );
        $by_trigger = [];

        foreach ( $this->all_non_archived() as $campaign ) {
            $by_trigger[ $campaign->get_trigger() ][] = (int) $campaign->get_id();
        }

        if ( [] !== array_diff( $listed, array_merge( ...array_values( $by_trigger ) ) ) ) {
            return false;
        }

        $priorities = [];

        foreach ( $by_trigger as $trigger_ids ) {
            $moved = array_values( array_intersect( $listed, $trigger_ids ) );

            if ( [] === $moved ) {
                continue;
            }

            $order = array_merge( $moved, array_values( array_diff( $trigger_ids, $moved ) ) );
            $count = count( $order );

            foreach ( $order as $position => $id ) {
                $priorities[ $id ] = $count - 1 - $position;
            }
        }

        $this->bulk_set_int_column( 'priority', $priorities );

        $this->flush_cache();

        return true;
    }

    public function insert( AbstractModel $model ): int {
        $id = parent::insert( $model );
        $this->flush_cache();

        return $id;
    }

    public function update( int $id, array $data, array $format = [] ): bool {
        $result = parent::update( $id, $data, $format );
        $this->flush_cache();

        return $result;
    }

    public function delete( int $id ): bool {
        $result = parent::delete( $id );
        $this->flush_cache();

        return $result;
    }

    public function flush_cache(): void {
        wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );

        /** Fires after any campaign write. Internal seam. */
        do_action( 'yayloyalty_campaigns_changed' );
    }

    /**
     * @param array $args paginate()'s, plus 'trigger', 'status' and 'search'.
     * @return array{items: Campaign[], total: int, page: int, per_page: int}
     */
    public function paginate_filtered( array $args = [] ): array {
        global $wpdb;

        $where = [];

        if ( ! empty( $args['trigger'] ) ) {
            $where[] = $wpdb->prepare( 'trigger_type = %s', $args['trigger'] );
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = $wpdb->prepare( 'status = %s', $args['status'] );
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = $this->like_fragment( 'name', (string) $args['search'] );
        }

        $args['where'] = $where;

        if ( ! isset( $args['orderby'] ) ) {
            $args['orderby'] = 'priority';
        }

        return $this->paginate( $args );
    }

    public function seed_builtin( Campaign $campaign ): void {
        $this->seed_builtin_by_slug( $campaign, $campaign->get_slug() );
    }
}
