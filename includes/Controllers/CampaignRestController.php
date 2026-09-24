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
 * Campaigns CRUD; the template for other definition tables. No service: the
 * invariants are validation. Built-in protection relies on is_builtin being
 * readonly in the schema, never on reading it from a request.
 *
 * $ledger is a read-only use here (points_issued/is_deletable decoration
 * and the delete guard) — the same instance RestAPI's other controllers
 * hold, not a second construction.
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

        $result['items'] = $this->decorate( $result['items'] );

        return $this->respond_paginated( $result );
    }

    public function get_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        return $this->decorate( [ $campaign ] )[0];
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

        if ( ! Campaign::conditions_are_valid( $conditions ) ) {
            return $this->error_invalid_arguments( __( 'The campaign conditions are malformed.', 'yayloyalty' ) );
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

        return $this->decorate( [ null === $created ? $campaign : $created ] )[0];
    }

    public function update_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        // Validate before any setter runs so a rejected request leaves the model untouched.
        if ( null !== $request['config'] && ! Campaign::config_is_valid( $campaign->get_trigger(), (array) $request['config'] ) ) {
            return $this->error_invalid_arguments( __( 'The campaign config has an unknown key or a value of the wrong type.', 'yayloyalty' ) );
        }

        if ( null !== $request['conditions'] && ! Campaign::conditions_are_valid( (array) $request['conditions'] ) ) {
            return $this->error_invalid_arguments( __( 'The campaign conditions are malformed.', 'yayloyalty' ) );
        }

        // Checked here rather than left to set_status()'s exception: a rejected request must
        // answer 403 through this validate-first block, not fall into exec_write()'s 500 path.
        if ( null !== $request['status'] && Status::ARCHIVED === (string) $request['status'] && $campaign->is_builtin() ) {
            return $this->error_forbidden( __( 'Built-in campaigns cannot be archived.', 'yayloyalty' ) );
        }

        // slug and trigger are immutable after create: built-ins are addressed by slug across sites.
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

        if ( $request->has_param( 'starts_at' ) ) {
            $campaign->set_starts_at( $this->nullable_date( $request['starts_at'] ) );
        }

        if ( $request->has_param( 'ends_at' ) ) {
            $campaign->set_ends_at( $this->nullable_date( $request['ends_at'] ) );
        }

        $campaign->set_updated_at( Dates::now() );
        $campaign->set_updated_by( get_current_user_id() );

        $update = $campaign->to_update_row();
        $this->campaigns->update( (int) $campaign->get_id(), $update['data'], $update['format'] );

        return $this->decorate( [ $campaign ] )[0];
    }

    public function delete_item( \WP_REST_Request $request ) {
        // Fixture only, no repository fallback: fixture ids 1-3 collide with real built-in ids.
        $row = self::find_dummy_row( (int) $request['id'] );

        if ( null === $row ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        // The engine and the Free UI expect built-ins to exist. is_builtin is readonly, so this cannot be sidestepped.
        if ( $row['is_builtin'] ) {
            return $this->error_forbidden( __( 'Built-in campaigns cannot be deleted.', 'yayloyalty' ) );
        }

        $id     = (int) $campaign->get_id();
        $totals = $this->ledger->totals_by_campaign( [ $id ] );

        // count, never points: a campaign earned-then-fully-refunded nets 0 points but still has
        // rows naming it, and deleting would orphan them.
        if ( ( $totals[ $id ]['count'] ?? 0 ) > 0 ) {
            return $this->error_conflict( __( 'This campaign has awarded points. Archive it instead of deleting it.', 'yayloyalty' ) );
        }

        // Unguarded gap: nothing locks between this check and the delete below, and
        // ledger.campaign_id is a plain KEY, not a FOREIGN KEY, so a row written into that gap
        // would be orphaned rather than rejected. Not reachable today — the earning listeners
        // that would write a real campaign_id aren't registered at boot (see Engine/Earning/) —
        // revisit once they are.
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
     * one batched query rather than one per row. `points_issued` is a
     * figure; `is_deletable` is delete_item()'s own guard restated for the
     * client, so the two can never disagree about what a delete would do.
     *
     * @param Campaign[] $campaigns
     * @return array<int, array<string, mixed>>
     */
    private function decorate( array $campaigns ): array {
        $ids    = array_map( static fn( Campaign $campaign ) => (int) $campaign->get_id(), $campaigns );
        $totals = $this->ledger->totals_by_campaign( $ids );

        return array_map(
            static function ( Campaign $campaign ) use ( $totals ) {
                $total = $totals[ (int) $campaign->get_id() ] ?? [
                    'points' => 0,
                    'count'  => 0,
                ];

                return $campaign->to_array() + [
                    'points_issued' => $total['points'],
                    'is_deletable'  => ! $campaign->is_builtin() && 0 === $total['count'],
                ];
            },
            $campaigns
        );
    }

    /**
     * The campaign resource with its lifetime points issued, which the model
     * does not carry. Swap point: a ledger sum by campaign_id replaces the
     * fixture figure passed in here.
     *
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private static function with_lifetime( array $campaign, int $points_issued_lifetime ): array {
        $campaign['points_issued_lifetime'] = $points_issued_lifetime;

        return $campaign;
    }

    /**
     * @param mixed $value
     */
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
                    'type'    => [ 'string', 'null' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'ends_at'       => [
                    'description' => __( 'Exclusive: a campaign is not live at exactly this instant.', 'yayloyalty' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view', 'edit' ],
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
                    'description' => __( 'Net points this campaign has awarded, lifetime. Derived from the ledger at read time, never stored.', 'yayloyalty' ),
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
