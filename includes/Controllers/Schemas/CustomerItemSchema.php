<?php
namespace YayLoyalty\Controllers\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Item schema for `GET /customers/{id}` — split out of CustomerRestController
 * to keep that file under the 200-line cap (docs/development-rules.md).
 *
 * Every property is readonly: nothing on this resource is client-writable
 * (manual adjustments go through `POST .../adjust`, a different resource).
 */
final class CustomerItemSchema {

    /**
     * @return array<string, mixed>
     */
    public static function properties(): array {
        return [
            'user_id'                  => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'name'                     => [
                'description' => __( 'Display name, from wp_users. Carried on this endpoint (not only the list row) so the drawer has an identity to render on a deep link or a refresh.', 'yayloyalty' ),
                'type'        => 'string',
                'context'     => [ 'view' ],
                'readonly'    => true,
            ],
            'email'                    => [
                'type'     => 'string',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            // Every aggregate is server-owned: it is derived from the
            // ledger and must never be settable from a request.
            'balance'                  => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'pending_points'           => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'lifetime_earned'          => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'lifetime_redeemed'        => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'last_activity_at'         => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'expires_at'               => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'joined_at'                => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'updated_at'               => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'deleted_at'               => [
                'description' => __( 'When the underlying WordPress user was deleted. Always null today — no deleted_at column exists yet (docs/data-model.md).', 'yayloyalty' ),
                'type'        => [ 'string', 'null' ],
                'context'     => [ 'view' ],
                'readonly'    => true,
            ],
            'counts'                   => [
                'description' => __( 'Row counts behind the drawer tab labels. activity/redemptions/referrals are always 0 on the real path — those tables do not exist yet.', 'yayloyalty' ),
                'type'        => 'object',
                'context'     => [ 'view' ],
                'readonly'    => true,
                'properties'  => [
                    'ledger'      => [ 'type' => 'integer' ],
                    'activity'    => [ 'type' => 'integer' ],
                    'redemptions' => [ 'type' => 'integer' ],
                    'referrals'   => [ 'type' => 'integer' ],
                ],
            ],
            'loose_ends'               => [
                'description' => __( 'F09 Wave B, artboard 4a. Void coupons, still-credited referrals, and whether a deleted account still counts toward the member total. Always null on the real path, same reason as deleted_at.', 'yayloyalty' ),
                'type'        => [ 'object', 'null' ],
                'context'     => [ 'view' ],
                'readonly'    => true,
                'properties'  => [
                    'void_coupons'        => [
                        'type'       => 'object',
                        'properties' => [
                            'count'   => [ 'type' => 'integer' ],
                            'example' => [ 'type' => [ 'string', 'null' ] ],
                        ],
                    ],
                    'credited_referrals'  => [ 'type' => 'integer' ],
                    'counted_in_members'  => [ 'type' => 'boolean' ],
                    'kept_ledger_entries' => [ 'type' => 'integer' ],
                ],
            ],
            'expiry'                   => [
                'description' => __( 'F09 Wave B, artboard 4b. Points about to expire, the batch breakdown (Pro FIFO lots), and the reminder schedule. Always null on the real path — needs lots, which does not exist yet.', 'yayloyalty' ),
                'type'        => [ 'object', 'null' ],
                'context'     => [ 'view' ],
                'readonly'    => true,
                'properties'  => [
                    'expiring_points' => [ 'type' => 'integer' ],
                    'expires_at'      => [ 'type' => 'string' ],
                    'safe_points'     => [ 'type' => 'integer' ],
                    'reminders'       => [
                        'type'       => 'object',
                        'properties' => [
                            'sent_at'     => [ 'type' => [ 'string', 'null' ] ],
                            'next_due_at' => [ 'type' => [ 'string', 'null' ] ],
                            'window_days' => [ 'type' => 'integer' ],
                        ],
                    ],
                    'batches'         => [
                        'type'  => [ 'array', 'null' ],
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'earned_from' => [ 'type' => 'string' ],
                                'earned_to'   => [ 'type' => 'string' ],
                                'points'      => [ 'type' => 'integer' ],
                                'expires_at'  => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            'negative_balance'         => [
                'description' => __( 'F09 Wave B, artboard 5a (F23). The earned/spent/reversed story behind a negative balance, when one can be assembled. Always null on the real path — would need walking the ledger backwards.', 'yayloyalty' ),
                'type'        => [ 'object', 'null' ],
                'context'     => [ 'view' ],
                'readonly'    => true,
                'properties'  => [
                    'shape'           => [
                        'type' => 'string',
                        'enum' => [ 'refund_reversal', 'other' ],
                    ],
                    'earned'          => [
                        'type'       => [ 'object', 'null' ],
                        'properties' => [
                            'order_id'    => [ 'type' => 'integer' ],
                            'points'      => [ 'type' => 'integer' ],
                            'occurred_at' => [ 'type' => 'string' ],
                        ],
                    ],
                    'spent'           => [
                        'type'       => [ 'object', 'null' ],
                        'properties' => [
                            'order_id' => [ 'type' => 'integer' ],
                            'points'   => [ 'type' => 'integer' ],
                            'value'    => [ 'type' => 'string' ],
                        ],
                    ],
                    'reversed'        => [
                        'type'       => [ 'object', 'null' ],
                        'properties' => [
                            'order_id'    => [ 'type' => 'integer' ],
                            'points'      => [ 'type' => 'integer' ],
                            'occurred_at' => [ 'type' => 'string' ],
                        ],
                    ],
                    'cause_ledger_id' => [ 'type' => [ 'integer', 'null' ] ],
                    'deficit'         => [ 'type' => 'integer' ],
                ],
            ],
            'points_per_currency_unit' => [
                'description' => __( 'Conversion rate for money-equivalent figures (drawer expiry/negative-balance panels). Restored minimally after the wider worth object was removed.', 'yayloyalty' ),
                'type'        => 'number',
                'context'     => [ 'view' ],
                'readonly'    => true,
            ],
        ];
    }
}
