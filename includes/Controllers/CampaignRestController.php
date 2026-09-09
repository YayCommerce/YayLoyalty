<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Campaigns CRUD — the definition-table template.
 *
 * Rewards and tiers are near-copies of this controller. There is no service
 * layer: the invariants here are validation, and a service wrapping one
 * repository would be ceremony.
 *
 * BUILT-IN PROTECTION DEPENDS ON THE SCHEMA, NOT ON THIS HANDLER. `is_builtin`
 * is marked readonly and stripped from write args by AbstractRestController, so
 * `PUT {"is_builtin":0}` cannot flip it and then walk through the DELETE guard.
 * Never read is_builtin from a request.
 */
final class CampaignRestController extends AbstractRestController {

    /** @var CampaignRepository */
    private $campaigns;

    public function __construct( CampaignRepository $campaigns ) {
        $this->campaigns = $campaigns;
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
                    'permission_callback' => [ $this, 'permission_callback' ],
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
                    'permission_callback' => [ $this, 'permission_callback' ],
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
                    'permission_callback' => [ $this, 'permission_callback' ],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'update_item' ], $request ),
                    'permission_callback' => [ $this, 'permission_callback' ],
                    'args'                => $this->get_write_args(),
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'delete_item' ], $request ),
                    'permission_callback' => [ $this, 'permission_callback' ],
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

        return $this->respond_paginated( $result );
    }

    public function get_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        return $campaign->to_array();
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

        // Slug is generated server-side and de-duplicated. A client-supplied
        // slug is honoured as a starting point but never trusted to be unique.
        $slug = $this->unique_slug( $request['slug'] ? (string) $request['slug'] : $name );

        $now = Dates::now();

        $campaign = new Campaign(
            null,
            $name,
            $slug,
            $trigger,
            $request['status'] ? Status::assert( (string) $request['status'] ) : Status::ACTIVE,
            null !== $request['priority'] ? (int) $request['priority'] : 0,
            Campaign::validate_config( $trigger, (array) ( $request['config'] ?? [] ) ),
            (array) ( $request['conditions'] ?? [] ),
            $this->nullable_date( $request['starts_at'] ),
            $this->nullable_date( $request['ends_at'] ),
            false, // is_builtin is never settable from a request.
            get_current_user_id(),
            $now,
            $now
        );

        $id = $this->campaigns->insert( $campaign );

        $created = $this->campaigns->find_campaign( $id );

        return null === $created ? $campaign->to_array() : $created->to_array();
    }

    public function update_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        // slug and trigger are immutable after create, enforced here rather than
        // by trusting the client to omit them. Built-ins are addressed by slug
        // across sites, so a changed slug would break the Free UI's binding.
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

        $data = $campaign->to_row();

        // slug/trigger/created_at are not updatable.
        unset( $data['slug'], $data['trigger'], $data['created_at'], $data['created_by'], $data['is_builtin'] );

        $this->campaigns->update(
            (int) $request['id'],
            $data,
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        $updated = $this->campaigns->find_campaign( (int) $request['id'] );

        return null === $updated ? $campaign->to_array() : $updated->to_array();
    }

    public function delete_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        // The Free UI binds to built-ins by slug, and the earning engine expects
        // them to exist. is_builtin is readonly in the schema, so this check
        // cannot be sidestepped by flipping the flag first.
        if ( $campaign->is_builtin() ) {
            return $this->error_forbidden( __( 'Built-in campaigns cannot be deleted.', 'yayloyalty' ) );
        }

        $this->campaigns->delete( (int) $request['id'] );

        return [
            'deleted' => true,
            'id'      => (int) $request['id'],
        ];
    }

    /**
     * A slug that is unique in the table, derived from $source.
     */
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
     * @param mixed $value
     */
    private function nullable_date( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return (string) $value;
    }

    public function get_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_campaign',
            'type'       => 'object',
            'properties' => [
                'id'         => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'name'       => [
                    'type'    => 'string',
                    'context' => [ 'view', 'edit' ],
                ],
                'slug'       => [
                    'description' => __( 'Stable machine key. Generated on create and immutable afterwards.', 'yayloyalty' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'trigger'    => [
                    'type'    => 'string',
                    'enum'    => CampaignTrigger::all(),
                    'context' => [ 'view', 'edit' ],
                ],
                'status'     => [
                    'description' => __( 'Merchant intent only. Liveness is derived from the dates.', 'yayloyalty' ),
                    'type'        => 'string',
                    'enum'        => Status::all(),
                    'context'     => [ 'view', 'edit' ],
                ],
                'priority'   => [
                    'type'    => 'integer',
                    'context' => [ 'view', 'edit' ],
                ],
                'config'     => [
                    'type'    => 'object',
                    'context' => [ 'view', 'edit' ],
                ],
                'conditions' => [
                    'type'    => [ 'object', 'array' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'starts_at'  => [
                    'type'    => [ 'string', 'null' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'ends_at'    => [
                    'description' => __( 'Exclusive: a campaign is not live at exactly this instant.', 'yayloyalty' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                // Server-owned. ALWAYS_READONLY covers these too, but declaring
                // them keeps the schema honest as documentation.
                'is_builtin' => [
                    'type'     => 'boolean',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_by' => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at' => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'updated_at' => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
