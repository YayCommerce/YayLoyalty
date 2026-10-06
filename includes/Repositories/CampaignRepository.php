<?php
namespace YayLoyalty\Repositories;

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

    public function find_by_slug( string $slug ): ?Campaign {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE slug = %s LIMIT 1',
                $slug
            ),
            ARRAY_A
        );

        return is_array( $row ) ? Campaign::from_row( $row ) : null;
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

    /** The built-in purchase campaign, whose rate multiplier campaigns multiply. Null if archived. */
    public function find_default_purchase(): ?Campaign {
        foreach ( $this->all_non_archived() as $campaign ) {
            if ( self::DEFAULT_PURCHASE_SLUG === $campaign->get_slug() ) {
                return $campaign;
            }
        }

        return null;
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

    /** Inserts a built-in row unless its slug is present. The unique key on slug is the backstop. */
    public function seed_builtin( Campaign $campaign ): void {
        if ( null !== $this->find_by_slug( $campaign->get_slug() ) ) {
            return;
        }

        $this->insert( $campaign );
    }
}
