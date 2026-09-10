<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Enums\Status;
use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_campaigns.
 *
 * The event column is `trigger_type` (see CampaignsTable): a plain identifier,
 * so no query here backticks anything.
 *
 * No row caching. The original design cached live campaigns per trigger with a
 * now()-derived TTL, which contradicted the event-time rule it sat next to: a
 * late-processed order would have been scored against today's live set rather
 * than the set live at its award time. A correct cache must key on the
 * evaluation timestamp, and the first code that knows the right key is the
 * earning engine. Until then this runs uncached against an indexed table with a
 * handful of rows.
 *
 * What IS maintained is a monotonic version counter, bumped on every write (see
 * OPTION_CACHE_VERSION). A later cache keys on it instead of querying
 * MAX(updated_at) — one autoloaded option read rather than a table scan, and it
 * moves even when a row is deleted.
 *
 * Plain object, not a singleton — see docs/code-standards.md section 8.
 */
final class CampaignRepository extends AbstractRepository {

    /**
     * Bumped on every insert/update/delete. A consumer compares the value it
     * cached against the current one to know whether its view is stale.
     */
    public const OPTION_CACHE_VERSION = 'yayloyalty_campaigns_version';

    protected function table_slug(): string {
        return 'campaigns';
    }

    /**
     * Current campaigns cache version. 0 before the first write.
     */
    public function cache_version(): int {
        return (int) get_option( self::OPTION_CACHE_VERSION, 0 );
    }

    public function insert( AbstractModel $model ): int {
        $id = parent::insert( $model );
        $this->bump_cache_version();

        return $id;
    }

    public function update( int $id, array $data, array $format = [] ): bool {
        $result = parent::update( $id, $data, $format );
        $this->bump_cache_version();

        return $result;
    }

    public function delete( int $id ): bool {
        $deleted = parent::delete( $id );
        $this->bump_cache_version();

        return $deleted;
    }

    /**
     * Advance the version counter. Cheap and lock-free enough for this: a lost
     * concurrent increment still changes the value, which is all a staleness
     * check needs.
     */
    private function bump_cache_version(): void {
        update_option( self::OPTION_CACHE_VERSION, $this->cache_version() + 1 );
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

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE slug = %s LIMIT 1',
                $slug
            ),
            ARRAY_A
        );

        return is_array( $row ) ? Campaign::from_row( $row ) : null;
    }

    /**
     * The campaign that should fire for a trigger at a given instant.
     *
     * Highest priority wins; ties go to the newest row. The built-in purchase
     * campaign sits at priority 0 so any matching campaign displaces it.
     *
     * $at is the EVENT's timestamp, not now() — see Campaign::is_live_at().
     * Liveness is expressed in SQL here so selection stays a single indexed
     * query rather than loading every campaign to filter in PHP.
     */
    public function find_live_by_trigger( string $trigger, string $at ): ?Campaign {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '`
                 WHERE trigger_type = %s
                   AND status = %s
                   AND ( starts_at IS NULL OR starts_at <= %s )
                   AND ( ends_at IS NULL OR ends_at > %s )
                 ORDER BY priority DESC, id DESC
                 LIMIT 1',
                $trigger,
                Status::ACTIVE,
                $at,
                $at
            ),
            ARRAY_A
        );

        return is_array( $row ) ? Campaign::from_row( $row ) : null;
    }

    /**
     * Paginated list with optional trigger/status filters.
     *
     * @param array $args Plus 'trigger' and 'status'.
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

    /**
     * Insert a built-in row if its slug is not already present.
     *
     * Idempotent by slug so repeated installs cannot duplicate it. Runs under
     * the installer's upgrade mutex, so two concurrent requests cannot both
     * seed; the unique key on slug is the backstop if they somehow do.
     */
    public function seed_builtin( Campaign $campaign ): void {
        if ( null !== $this->find_by_slug( $campaign->get_slug() ) ) {
            return;
        }

        $this->insert( $campaign );
    }
}
