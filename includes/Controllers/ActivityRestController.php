<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Fixtures\CustomerDrawer\CustomerDrawerFixture;
use YayLoyalty\Controllers\Schemas\ActivityQueryArgs;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/customers/{id}/activity — paginated activity-log rows
 * (F09 Wave B, artboard 3d).
 *
 * No `activity` table exists yet (docs/data-model.md → "Activity log (not
 * designed)"), so the real path always answers an EMPTY envelope — zero is
 * the true count, not a placeholder, matching `counts.activity` on
 * `CustomerRestController::format_customer()`. The WP_DEBUG-gated drawer
 * fixture (`DrawerActivity`) is the only thing that answers this for now;
 * when a real table ships, only `get_history()` below changes — the schema
 * and its Zod mirror (`activity.schema.ts`) are already the contract.
 *
 * A separate controller rather than a method on `CustomerRestController`,
 * the same way `LedgerRestController` owns `/customers/{id}/ledger`: keeps
 * both files under the 200-line cap and mirrors an established shape.
 */
final class ActivityRestController extends AbstractRestController {

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
                        [ 'id' => $this->user_id_param() ],
                        $this->get_collection_params(),
                        ActivityQueryArgs::all(),
                        CustomerDrawerFixture::scenario_arg()
                    ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_history( \WP_REST_Request $request ) {
        $user_id  = (int) $request['id'];
        $group    = (string) $request['group'];
        $page     = (int) $request['page'];
        $per_page = (int) $request['per_page'];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $request['scenario'] ) ) {
            $result = CustomerDrawerFixture::activity(
                (string) $request['scenario'],
                $group,
                [
                    'page'     => $page,
                    'per_page' => $per_page,
                    'search'   => (string) $request['search'],
                    'after'    => (string) $request['after'],
                    'before'   => (string) $request['before'],
                ]
            );

            return $this->respond_paginated( $result );
        }

        // Real path: no table, no rows, no error — an empty page is the
        // truthful answer, not a degraded one. $user_id is unused here
        // (nothing to look up yet) but stays validated via user_id_param().
        unset( $user_id );

        return $this->respond_paginated(
            [
                'items'    => [],
                'total'    => 0,
                'page'     => $page,
                'per_page' => $per_page,
            ]
        );
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
