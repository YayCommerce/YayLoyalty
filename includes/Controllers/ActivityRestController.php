<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Schemas\ActivityQueryArgs;
use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/activity — paginated activity-log rows
 * (F09 Wave B, artboard 3d), reading real `yayloyalty_customer_meta` rows.
 *
 * Only three of the eight `ActivityEvent` kinds have a writer today
 * (`account_created`, `points_adjusted`, `account_deleted` —
 * `Services\CustomerActivityService`); the other five (tier changes,
 * expiry, redemptions, balance/expiry emails) have no source feature, so a
 * customer whose only history would be one of them legitimately gets zero
 * rows — that is the true count, not a bug. No backfill: a pre-existing
 * member's log starts the day this shipped.
 *
 * `search` is a PHP-side match against decoded real content fields only
 * (`CustomerMetaRepository::paginate_activity()`), never the JSON
 * envelope's structural keys. `after`/`before` are UTC day bounds — no
 * site-timezone conversion, same as every other drawer filter.
 *
 * A separate controller rather than a method on `CustomerRestController`,
 * the same way `LedgerRestController` owns `/customers/{id}/ledger`: keeps
 * both files under the 200-line cap and mirrors an established shape.
 */
final class ActivityRestController extends AbstractRestController {

    /** @var CustomerMetaRepository */
    private $meta;

    /** @var CustomerRepository Read-only — only the deleted-member id validation. */
    private $customers;

    public function __construct( CustomerMetaRepository $meta, CustomerRepository $customers ) {
        $this->meta      = $meta;
        $this->customers = $customers;
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
                        [ 'id' => $this->user_id_param( $this->customers ) ],
                        $this->get_collection_params(),
                        ActivityQueryArgs::all()
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_history( \WP_REST_Request $request ) {
        $types = ActivityQueryArgs::event_types_for( (string) $request['group'] ) ?? ActivityEvent::ALL;

        try {
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
        } catch ( \Throwable $e ) {
            // customer_meta missing (version not bumped yet) or any other
            // query failure must degrade to an honest empty page, not a
            // 500 that takes the whole drawer down.
            Log::error( 'ActivityRestController', $e->getMessage() );

            return $this->respond_empty_page( $request );
        }

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
                    'enum'     => ActivityQueryArgs::EVENT_TYPES,
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
