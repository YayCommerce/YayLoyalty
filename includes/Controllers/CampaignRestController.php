<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\CampaignConditionSet;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Campaigns CRUD, and the template for other definition tables. No service: the
 * invariants are validation. Built-in protection relies on is_builtin being
 * readonly in the schema, never on reading it from a request. $ledger is
 * read-only here, for the points_issued and is_deletable fields and the delete guard.
 */
final class CampaignRestController extends AbstractRestController {

    /** @var CampaignRepository */
    private $campaigns;

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( CampaignRepository $campaigns, LedgerRepository $ledger ) {
        $this->campaigns = $campaigns;
        $this->ledger    = $ledger;
    }

    public function get_rest_base(): string {
        return 'campaigns';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_items' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => array_merge(
                        $this->get_collection_params(),
                        [
                            'trigger' => [
                                'type' => 'string',
                                'enum' => CampaignTrigger::all(),
                            ],
                            'status'  => [
                                'type' => 'string',
                                'enum' => Status::all(),
                            ],
                        ]
                    ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'create_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => $this->get_write_args(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'update_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => $this->get_write_args(),
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'delete_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_items( \WP_REST_Request $request ) {
        $result = $this->campaigns->paginate_filtered(
            [
                'page'     => (int) $request['page'],
                'per_page' => (int) $request['per_page'],
                'orderby'  => $request['orderby'] ? (string) $request['orderby'] : 'priority',
                'order'    => $request['order'] ? (string) $request['order'] : 'desc',
                'trigger'  => $request['trigger'] ? (string) $request['trigger'] : '',
                'status'   => $request['status'] ? (string) $request['status'] : '',
                'search'   => $request['search'] ? (string) $request['search'] : '',
            ]
        );

        $result['items'] = self::decorate( $this->ledger, $result['items'] );

        return $this->respond_paginated( $result );
    }

    public function get_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        return self::decorate( $this->ledger, [ $campaign ] )[0];
    }

    /**
     * Whether a purchase config in rate mode leaves its rate out. The engine reads a missing rate
     * as 0, and a 0-point match stops every lower-priority campaign from applying. A multiplier
     * needs no key, since a missing factor is ×1.
     *
     * @param array<string, mixed> $config
     */
    private static function lacks_purchase_rate( string $trigger, array $config ): bool {
        return CampaignTrigger::PURCHASE === $trigger
            && ! PurchaseHandler::is_multiplier( $config )
            && ! isset( $config['points_per_unit'] );
    }

    public function create_item( \WP_REST_Request $request ) {
        $trigger = (string) $request['trigger'];

        if ( ! CampaignTrigger::is_valid( $trigger ) ) {
            return $this->error_invalid_arguments( __( 'Unknown campaign trigger.', 'yayloyalty' ) );
        }

        $name = trim( (string) $request['name'] );

        if ( '' === $name ) {
            return $this->error_invalid_arguments( __( 'A campaign needs a name.', 'yayloyalty' ) );
        }

        $config     = (array) ( $request['config'] ?? [] );
        $conditions = (array) ( $request['conditions'] ?? [] );

        if ( ! Campaign::config_is_valid( $trigger, $config ) ) {
            return $this->error_invalid_arguments( __( 'The campaign config has an unknown key or a value of the wrong type.', 'yayloyalty' ) );
        }

        if ( self::lacks_purchase_rate( $trigger, $config ) ) {
            return $this->error_invalid_arguments( __( 'A purchase campaign needs a rate.', 'yayloyalty' ) );
        }

        if ( ! Campaign::conditions_are_valid( $conditions ) ) {
            return $this->error_invalid_arguments( __( 'The campaign conditions are malformed.', 'yayloyalty' ) );
        }

        if ( ! self::dates_in_order( $this->nullable_date( $request['starts_at'] ), $this->nullable_date( $request['ends_at'] ) ) ) {
            return $this->error_invalid_arguments( __( 'The campaign must end after it starts.', 'yayloyalty' ) );
        }

        // A client slug is a starting point, never trusted to be unique.
        $slug = $this->unique_slug( $request['slug'] ? (string) $request['slug'] : $name );

        $now = Dates::now();

        $campaign = new Campaign(
            null,
            $name,
            $slug,
            $trigger,
            $request['status'] ? Status::assert( (string) $request['status'] ) : Status::ACTIVE,
            null !== $request['priority'] ? (int) $request['priority'] : 0,
            Campaign::validate_config( $trigger, $config ),
            CampaignConditionSet::normalize( $conditions ),
            $this->nullable_date( $request['starts_at'] ),
            $this->nullable_date( $request['ends_at'] ),
            false, // is_builtin is never settable from a request.
            get_current_user_id(),
            $now,
            $now
        );

        $id = $this->campaigns->insert( $campaign );

        $created = $this->campaigns->find_campaign( $id );

        return self::decorate( $this->ledger, [ null === $created ? $campaign : $created ] )[0];
    }

    public function update_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        // Validate before any setter runs so a rejected request leaves the model untouched.
        $error = $this->update_error( $campaign, $request );

        if ( null !== $error ) {
            return $error;
        }

        // Checked against the stored date when the request moves one side only. A body that
        // touches neither date (pause, archive, rename) must still go through on a row whose
        // stored dates were never in order, or that row could never be repaired or retired.
        $moves_dates = $request->has_param( 'starts_at' ) || $request->has_param( 'ends_at' );
        $starts_at   = $request->has_param( 'starts_at' ) ? $this->nullable_date( $request['starts_at'] ) : $campaign->get_starts_at();
        $ends_at     = $request->has_param( 'ends_at' ) ? $this->nullable_date( $request['ends_at'] ) : $campaign->get_ends_at();

        if ( $moves_dates && ! self::dates_in_order( $starts_at, $ends_at ) ) {
            return $this->error_invalid_arguments( __( 'The campaign must end after it starts.', 'yayloyalty' ) );
        }

        self::apply_update( $campaign, $request );
        $campaign->set_starts_at( $starts_at );
        $campaign->set_ends_at( $ends_at );

        $update = $campaign->to_update_row();
        $this->campaigns->update( (int) $campaign->get_id(), $update['data'], $update['format'] );

        return self::decorate( $this->ledger, [ $campaign ] )[0];
    }

    /** Why an update request must be refused before anything is written, or null when it may proceed. */
    private function update_error( Campaign $campaign, \WP_REST_Request $request ): ?\WP_Error {
        if ( null !== $request['config'] && ! Campaign::config_is_valid( $campaign->get_trigger(), (array) $request['config'] ) ) {
            return $this->error_invalid_arguments( __( 'The campaign config has an unknown key or a value of the wrong type.', 'yayloyalty' ) );
        }

        if ( null !== $request['config'] && self::lacks_purchase_rate( $campaign->get_trigger(), (array) $request['config'] ) ) {
            return $this->error_invalid_arguments( __( 'A purchase campaign needs a rate.', 'yayloyalty' ) );
        }

        if ( null !== $request['conditions'] && ! Campaign::conditions_are_valid( (array) $request['conditions'] ) ) {
            return $this->error_invalid_arguments( __( 'The campaign conditions are malformed.', 'yayloyalty' ) );
        }

        // The engine reads the default campaign's rate for every multiplier; the default multiplying
        // itself would have no rate of its own to start from.
        if (
            null !== $request['config']
            && CampaignRepository::DEFAULT_PURCHASE_SLUG === $campaign->get_slug()
            && PurchaseHandler::is_multiplier( (array) $request['config'] )
        ) {
            return $this->error_invalid_arguments( __( 'The default purchase campaign sets its own rate; it cannot multiply itself.', 'yayloyalty' ) );
        }

        // Checked here rather than left to set_status()'s exception: a rejected request must
        // answer 403 through this validate-first block, not fall into exec_write()'s 500 path.
        if ( null !== $request['status'] && Status::ARCHIVED === (string) $request['status'] && ! $campaign->can_archive() ) {
            return $this->error_forbidden( __( 'Built-in campaigns cannot be archived.', 'yayloyalty' ) );
        }

        if ( null !== $request['name'] && '' === trim( (string) $request['name'] ) ) {
            return $this->error_invalid_arguments( __( 'A campaign needs a name.', 'yayloyalty' ) );
        }

        return null;
    }

    /**
     * Copies the request's editable fields onto $campaign. Slug and trigger are immutable after create,
     * since built-ins are addressed by slug across sites.
     */
    private static function apply_update( Campaign $campaign, \WP_REST_Request $request ): void {
        if ( null !== $request['name'] ) {
            $campaign->set_name( trim( (string) $request['name'] ) );
        }

        if ( null !== $request['status'] ) {
            $campaign->set_status( (string) $request['status'] );
        }

        if ( null !== $request['priority'] ) {
            $campaign->set_priority( (int) $request['priority'] );
        }

        if ( null !== $request['config'] ) {
            $campaign->set_config( (array) $request['config'] );
        }

        if ( null !== $request['conditions'] ) {
            $campaign->set_conditions( (array) $request['conditions'] );
        }

        $campaign->set_updated_at( Dates::now() );
        $campaign->set_updated_by( get_current_user_id() );
    }

    public function delete_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        if ( $campaign->is_builtin() ) {
            return $this->error_forbidden( __( 'Built-in campaigns cannot be deleted.', 'yayloyalty' ) );
        }

        $id     = (int) $campaign->get_id();
        $totals = $this->ledger->totals_by_campaign( [ $id ] );

        if ( ! self::is_deletable( $campaign, $totals[ $id ]['count'] ?? 0 ) ) {
            return $this->error_conflict( __( 'This campaign has awarded points. Archive it instead of deleting it.', 'yayloyalty' ) );
        }

        // Unguarded gap: nothing locks between this check and the delete below, and
        // ledger.campaign_id is a plain KEY, not a FOREIGN KEY, so an award written into
        // that gap would be orphaned rather than rejected.
        // TODO(campaign-delete-race): close this gap, which the earning listeners can now reach.
        $this->campaigns->delete( $id );

        return [
            'deleted' => true,
            'id'      => $id,
        ];
    }

    /** A slug unique in the table, derived from $source. */
    private function unique_slug( string $source ): string {
        $base = sanitize_title( $source );

        if ( '' === $base ) {
            $base = 'campaign';
        }

        $base = substr( $base, 0, 60 );
        $slug = $base;
        $n    = 2;

        while ( null !== $this->campaigns->find_by_slug( $slug ) ) {
            $slug = substr( $base, 0, 60 - strlen( (string) $n ) - 1 ) . '-' . $n;
            ++$n;
        }

        return $slug;
    }

    /**
     * Adds the two ledger-derived fields to each campaign's wire shape, in
     * one batched query rather than one per row. `points_issued` is a figure.
     * `is_deletable` is delete_item()'s own guard, restated for the client so
     * the two never disagree about what a delete would do.
     *
     * @param Campaign[] $campaigns
     * @return array<int, array<string, mixed>>
     */
    public static function decorate( LedgerRepository $ledger, array $campaigns ): array {
        $ids    = array_map( static fn( Campaign $campaign ) => (int) $campaign->get_id(), $campaigns );
        $totals = $ledger->totals_by_campaign( $ids );

        return array_map(
            static function ( Campaign $campaign ) use ( $totals ) {
                $total = $totals[ (int) $campaign->get_id() ] ?? [
                    'points' => 0,
                    'count'  => 0,
                ];

                return $campaign->to_array() + [
                    'points_issued' => $total['points'],
                    'is_deletable'  => self::is_deletable( $campaign, $total['count'] ),
                ];
            },
            $campaigns
        );
    }

    /**
     * Whether $campaign may be deleted: never a built-in, which the engine and the Free UI expect,
     * and only with no ledger row naming it. Rows, never points: a campaign earned-then-fully-refunded
     * nets 0 points but still has rows, and deleting would orphan them.
     */
    private static function is_deletable( Campaign $campaign, int $ledger_rows ): bool {
        return ! $campaign->is_builtin() && 0 === $ledger_rows;
    }

    /** Whether the dates are in order: an unset side passes, and two set dates must run forward. ends_at is exclusive, so equal dates are empty. */
    private static function dates_in_order( ?string $starts_at, ?string $ends_at ): bool {
        return null === $starts_at || null === $ends_at || Dates::to_timestamp( $starts_at ) < Dates::to_timestamp( $ends_at );
    }

    /**
     * Route-arg check for starts_at/ends_at: empty clears the date, anything else must be a
     * full UTC `Y-m-d H:i:s`, the shape the column stores and the SPA sends. Without it a
     * string strtotime() cannot read passes dates_in_order() (it reads as 0) and lands in the
     * DATETIME column as garbage.
     */
    public static function validate_nullable_date( $value ): bool {
        if ( null === $value || '' === $value ) {
            return true;
        }

        $date = is_string( $value ) ? \DateTimeImmutable::createFromFormat( Dates::MYSQL_FORMAT, $value, new \DateTimeZone( 'UTC' ) ) : false;

        return false !== $date && $date->format( Dates::MYSQL_FORMAT ) === $value;
    }

    private function nullable_date( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return (string) $value;
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_campaign',
            'type'       => 'object',
            'properties' => [
                'id'            => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'name'          => [
                    'type'    => 'string',
                    'context' => [ 'view', 'edit' ],
                ],
                'slug'          => [
                    'description' => __( 'Stable machine key. Generated on create and immutable afterwards.', 'yayloyalty' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'trigger'       => [
                    'type'    => 'string',
                    'enum'    => CampaignTrigger::all(),
                    'context' => [ 'view', 'edit' ],
                ],
                'status'        => [
                    'description' => __( 'Merchant intent only. Liveness is derived from the dates.', 'yayloyalty' ),
                    'type'        => 'string',
                    'enum'        => Status::all(),
                    'context'     => [ 'view', 'edit' ],
                ],
                'priority'      => [
                    'type'    => 'integer',
                    'context' => [ 'view', 'edit' ],
                ],
                'config'        => [
                    'type'    => 'object',
                    'context' => [ 'view', 'edit' ],
                ],
                'conditions'    => [
                    'type'    => [ 'object', 'array' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'starts_at'     => [
                    'type'              => [ 'string', 'null' ],
                    'context'           => [ 'view', 'edit' ],
                    'validate_callback' => [ self::class, 'validate_nullable_date' ],
                ],
                'ends_at'       => [
                    'description'       => __( 'Exclusive: a campaign is not live at exactly this instant.', 'yayloyalty' ),
                    'type'              => [ 'string', 'null' ],
                    'context'           => [ 'view', 'edit' ],
                    'validate_callback' => [ self::class, 'validate_nullable_date' ],
                ],
                'is_builtin'    => [
                    'type'     => 'boolean',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_by'    => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at'    => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'updated_at'    => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'points_issued' => [
                    'description' => __( 'Points this campaign has awarded, lifetime: positive earn-type ledger rows, the same measure as its performance page. Derived at read time, never stored.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'is_deletable'  => [
                    'description' => __( 'Whether DELETE would succeed: not built-in, and no ledger row names it.', 'yayloyalty' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
            ],
        ];
    }
}
