<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
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
            '/campaigns/reorder',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'reorder_items' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => $this->reorder_args( __( 'Campaign ids, highest priority first; see CampaignRepository::reorder().', 'yayloyalty' ) ),
                ],
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
     * Whether a purchase config leaves its rate out. The engine reads a missing rate as 0, and a
     * 0-point match stops every lower-priority campaign from applying.
     *
     * @param array<string, mixed> $config
     */
    private static function lacks_purchase_rate( string $trigger, array $config ): bool {
        return CampaignTrigger::PURCHASE === $trigger && ! isset( $config['points_per_unit'] );
    }

    /** Saves a dragged order as priority. A duplicate or unknown id refuses the whole request. */
    public function reorder_items( \WP_REST_Request $request ) {
        return $this->handle_reorder(
            $request,
            [ $this->campaigns, 'reorder' ],
            __( 'A campaign appears twice in the new order.', 'yayloyalty' ),
            __( 'The new order names a campaign that no longer exists.', 'yayloyalty' )
        );
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

        if ( ! self::dates_in_order( self::nullable_date( $request['starts_at'] ), self::nullable_date( $request['ends_at'] ) ) ) {
            return $this->error_invalid_arguments( __( 'The campaign must end after it starts.', 'yayloyalty' ) );
        }

        // A client slug is a starting point, never trusted to be unique.
        $slug = self::unique_slug(
            $request['slug'] ? (string) $request['slug'] : $name,
            'campaign',
            fn( string $slug ) => null !== $this->campaigns->find_by_slug( $slug )
        );

        $now = Dates::now();

        $campaign = new Campaign(
            null,
            $name,
            $slug,
            $trigger,
            $request['status'] ? Status::assert( (string) $request['status'] ) : Status::ACTIVE,
            $this->campaigns->top_priority( $trigger ),
            Campaign::validate_config( $trigger, $config ),
            CampaignConditionSet::normalize( $conditions ),
            self::nullable_date( $request['starts_at'] ),
            self::nullable_date( $request['ends_at'] ),
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

        $window = self::requested_window( $request, $campaign->get_starts_at(), $campaign->get_ends_at() );

        if ( null === $window ) {
            return $this->error_invalid_arguments( __( 'The campaign must end after it starts.', 'yayloyalty' ) );
        }

        list( $starts_at, $ends_at ) = $window;

        $was_archived = $campaign->is_archived();

        self::apply_update( $campaign, $request );

        // An archived row keeps its old number while reorders skip it, so restoring it in place could tie.
        if ( $was_archived && ! $campaign->is_archived() ) {
            $campaign->set_priority( $this->campaigns->top_priority( $campaign->get_trigger() ) );
        }

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

        $id = (int) $campaign->get_id();

        // ledger.campaign_id has no foreign key: holding the row until commit makes a racing award,
        // which takes it shared in LedgerService, either land before the count or skip after the delete.
        if ( ! $this->campaigns->lock_for_delete( $id ) ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        if ( ! self::is_deletable( $campaign, $this->ledger->count_for_campaign_locked( $id ) ) ) {
            return $this->error_conflict( __( 'This campaign has awarded points. Archive it instead of deleting it.', 'yayloyalty' ) );
        }

        $this->campaigns->delete( $id );

        return [
            'deleted' => true,
            'id'      => $id,
        ];
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
     * Whether $campaign may be deleted: never a built-in, which the engine and the Lite UI expect,
     * and only with no ledger row naming it. Rows, never points: a campaign earned-then-fully-refunded
     * nets 0 points but still has rows, and deleting would orphan them.
     */
    private static function is_deletable( Campaign $campaign, int $ledger_row_count ): bool {
        return ! $campaign->is_builtin() && 0 === $ledger_row_count;
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
                    'description' => __( 'Highest wins within a trigger. Assigned by the server; only campaigns/reorder changes it.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
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
