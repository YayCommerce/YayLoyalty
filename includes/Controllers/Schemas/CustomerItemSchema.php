<?php
namespace YayLoyalty\Controllers\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Item schema for `GET /customers/{id}`. Every property is readonly: nothing on this resource is
 * client-writable, and manual adjustments go through `POST .../adjust`, a different resource.
 */
final class CustomerItemSchema {

    /** @return array<string, mixed> */
    public static function properties(): array {
        return [
            'user_id'           => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'name'              => [
                'description' => __( 'Display name, from wp_users. Carried on this endpoint (not only the list row) so the drawer has an identity to render on a deep link or a refresh.', 'yayloyalty' ),
                'type'        => 'string',
                'context'     => [ 'view' ],
                'readonly'    => true,
            ],
            'email'             => [
                'type'     => 'string',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'balance'           => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'pending_points'    => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'lifetime_earned'   => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'lifetime_redeemed' => [
                'type'     => 'integer',
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'last_activity_at'  => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'expires_at'        => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'joined_at'         => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'updated_at'        => [
                'type'     => [ 'string', 'null' ],
                'context'  => [ 'view' ],
                'readonly' => true,
            ],
            'deleted_at'        => [
                'description' => __( 'Derived from the latest account_deleted row in yayloyalty_customer_meta, gated on the WP user currently being gone (get_userdata() false) — not a column, and never will be. Null while the WP user still resolves, even if a deletion row exists (a reinstated id self-heals).', 'yayloyalty' ),
                'type'        => [ 'string', 'null' ],
                'context'     => [ 'view' ],
                'readonly'    => true,
            ],
            'counts'            => [
                'description' => __( 'Row counts behind the drawer tab labels. ledger/activity are real; redemptions is always 0 — that table does not exist yet.', 'yayloyalty' ),
                'type'        => 'object',
                'context'     => [ 'view' ],
                'readonly'    => true,
                'properties'  => [
                    'ledger'      => [ 'type' => 'integer' ],
                    'activity'    => [ 'type' => 'integer' ],
                    'redemptions' => [ 'type' => 'integer' ],
                ],
            ],
            'loose_ends'        => [
                'description' => __( 'What deleting this member left behind. Non-null exactly when deleted_at is non-null. kept_ledger_entries is always real; void_coupons/counted_in_members are always null — no redemptions/member-count feature exists — and render as "Not tracked yet" in the SPA rather than being hidden.', 'yayloyalty' ),
                'type'        => [ 'object', 'null' ],
                'context'     => [ 'view' ],
                'readonly'    => true,
                'properties'  => [
                    'void_coupons'        => [
                        'type'       => [ 'object', 'null' ],
                        'properties' => [
                            'count'   => [ 'type' => 'integer' ],
                            'example' => [ 'type' => [ 'string', 'null' ] ],
                        ],
                    ],
                    'counted_in_members'  => [ 'type' => [ 'boolean', 'null' ] ],
                    'kept_ledger_entries' => [ 'type' => 'integer' ],
                ],
            ],
            'expiry'            => [
                'description' => __( 'Points about to expire, the batch breakdown (lots, oldest first), and the reminder schedule. Always null for now — needs lots, which do not exist yet.', 'yayloyalty' ),
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
            'negative_balance'  => [
                'description' => __( 'The earned/spent/reversed story behind a negative balance, when one can be assembled. Always null for now — would need walking the ledger backwards.', 'yayloyalty' ),
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
        ];
    }
}
