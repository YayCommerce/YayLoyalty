<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_campaigns.
 *
 * The event column is `trigger_type` (see CampaignsTable): a plain identifier,
 * so no query here backticks anything.
 *
 * No caching. The original design cached live campaigns per trigger with a
 * now()-derived TTL, which contradicted the event-time rule it sat next to: a
 * late-processed order would have been scored against today's live set rather
 * than the set live at its award time. A correct cache must key on the
 * evaluation timestamp, and the first code that knows the right key is the
 * earning engine — including how it invalidates. Until then this runs
 * uncached against an indexed table with a handful of rows.
 *
 * Plain object, not a singleton — see docs/code-standards.md section 8.
 */
final class CampaignRepository extends AbstractRepository {

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
