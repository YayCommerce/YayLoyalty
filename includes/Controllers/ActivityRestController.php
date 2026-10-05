<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Schemas\ActivityQueryArgs;
use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Repositories\CustomerMetaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/activity — paginated activity-log rows
 * read from `yayloyalty_customer_meta`.
 *
 * Only three of the eight `ActivityEvent` kinds have a writer today — see
 * `Enums\ActivityEvent`'s docblock for which. No backfill: a pre-existing
 * member's log starts the day this shipped.
 *
 * `search` is a PHP-side match against decoded real content fields only
 * (`CustomerMetaRepository::paginate_activity()`), never the JSON
 * envelope's structural keys. `after`/`before` are site-timezone
 * calendar days (`Dates::site_day_to_utc()`), same as the ledger tab.
 *
 * A separate controller rather than a method on `CustomerRestController`,
 * the same way `LedgerRestController` owns `/customers/{id}/ledger`.
 */
final class ActivityRestController extends AbstractRestController {

    /** @var CustomerMetaRepository */
    private $meta;

    public function __construct( CustomerMetaRepository $meta ) {
        $this->meta = $meta;
    }

    public function get_rest_base(): string {
        return 'activity';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)/activity',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_history' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => array_merge(
                        [ 'id' => $this->member_id_param() ],
                        $this->get_collection_params(),
                        ActivityQueryArgs::all()
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_history( \WP_REST_Request $request ) {
        $types = ActivityQueryArgs::event_types_for( (string) $request['group'] );

        $result = $this->meta->paginate_activity(
            (int) $request['id'],
            [
                'event_types' => $types,
                'search'      => (string) ( $request['search'] ?? '' ),
                'after'       => (string) ( $request['after'] ?? '' ),
                'before'      => (string) ( $request['before'] ?? '' ),
                'page'        => (int) $request['page'],
                'per_page'    => (int) $request['per_page'],
            ]
        );

        return $this->respond_paginated( $result );
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_activity_entry',
            'type'       => 'object',
            'properties' => [
                'id'          => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'occurred_at' => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'event_type'  => [
                    'type'     => 'string',
                    'enum'     => ActivityEvent::ALL,
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'data'        => [
                    'description' => __( 'Structured fields the copy module composes a sentence from. Never prose.', 'yayloyalty' ),
                    'type'        => 'object',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'actor_kind'  => [
                    'type'     => 'string',
                    'enum'     => ActivityQueryArgs::ACTOR_KINDS,
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'actor_name'  => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'staff_only'  => [
                    'description' => __( 'Display flag: this row quotes an internal admin note. Not access control.', 'yayloyalty' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
            ],
        ];
    }
}
