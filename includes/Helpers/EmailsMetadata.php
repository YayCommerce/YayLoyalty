<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Static email metadata (labels/descriptions/recipient/defaults). One source of truth —
 * pro_email_groups() covers every id, the Free ones included (reused, never duplicated).
 * FREE_EMAIL_IDS marks which ids the Lite build's UI renders editable; every id is still persisted
 * and returned to every build (see EmailsHelper's docblock). Values are rebuilt on every call, not
 * consts, because __() cannot run inside a const initializer on PHP 7.4.
 */
class EmailsMetadata {

    /**
     * @var string[]
     */
    public const FREE_EMAIL_IDS = [ 'points_earned', 'points_expiring_soon', 'points_expired' ];

    /**
     * Email groups in display order. Free ids (points_earned,
     * points_expiring_soon, points_expired) live inside the "points" group alongside Pro-only ids —
     * one namespace, no remap needed.
     *
     * @return array<string, array{label: string, items: array<string, array{label: string, description: string, recipient: string, default_enabled: bool}>}>
     */
    public static function pro_email_groups(): array {
        return [
            'points'   => [
                'label' => __( 'Points', 'yayloyalty' ),
                'items' => [
                    'points_earned'           => [
                        'label'           => __( 'Points earned', 'yayloyalty' ),
                        'description'     => __( 'Sent when a customer earns points from an order or activity.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                    'points_expiring_soon'    => [
                        'label'           => __( 'Points expiring soon', 'yayloyalty' ),
                        'description'     => __( 'Sent shortly before a customer\'s points balance expires.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                    'expiring_final_reminder' => [
                        'label'           => __( 'Expiring final reminder', 'yayloyalty' ),
                        'description'     => __( 'A last-chance reminder sent just before points expire.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                    'points_expired'          => [
                        'label'           => __( 'Points expired', 'yayloyalty' ),
                        'description'     => __( 'Sent after a customer\'s points balance has expired.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => false,
                    ],
                ],
            ],
            'rewards'  => [
                'label' => __( 'Rewards', 'yayloyalty' ),
                'items' => [
                    'reward_claimed' => [
                        'label'           => __( 'Reward claimed', 'yayloyalty' ),
                        'description'     => __( 'Sent when a customer claims a reward.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                    'coupon_issued'  => [
                        'label'           => __( 'Coupon issued', 'yayloyalty' ),
                        'description'     => __( 'Sent when a reward coupon is issued to a customer.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                ],
            ],
            'referral' => [
                'label' => __( 'Referral', 'yayloyalty' ),
                'items' => [
                    'invite_sent'            => [
                        'label'           => __( 'Invite sent', 'yayloyalty' ),
                        'description'     => __( 'Sent when a customer invites a friend to join.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                    'referral_reward_earned' => [
                        'label'           => __( 'Referral reward earned', 'yayloyalty' ),
                        'description'     => __( 'Sent when a referral earns a reward for either party.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                ],
            ],
            'tiers'    => [
                'label' => __( 'Tiers', 'yayloyalty' ),
                'items' => [
                    'tier_reached'     => [
                        'label'           => __( 'Tier reached', 'yayloyalty' ),
                        'description'     => __( 'Sent when a customer reaches a new tier.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                    'tier_ending_soon' => [
                        'label'           => __( 'Tier ending soon', 'yayloyalty' ),
                        'description'     => __( 'Sent shortly before a customer\'s current tier expires.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                    'tier_lost'        => [
                        'label'           => __( 'Tier lost', 'yayloyalty' ),
                        'description'     => __( 'Sent when a customer drops out of their current tier.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => false,
                    ],
                ],
            ],
            'birthday' => [
                'label' => __( 'Birthday', 'yayloyalty' ),
                'items' => [
                    'birthday_points' => [
                        'label'           => __( 'Birthday points', 'yayloyalty' ),
                        'description'     => __( 'Sent when birthday points are awarded to a customer.', 'yayloyalty' ),
                        'recipient'       => 'customer',
                        'default_enabled' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Every id, flattened in group/display order.
     *
     * @return string[]
     */
    public static function all_email_ids(): array {
        $ids = [];

        foreach ( self::pro_email_groups() as $data ) {
            $ids = array_merge( $ids, array_keys( $data['items'] ) );
        }

        return $ids;
    }

    /**
     * id => default_enabled for every id.
     *
     * @return array<string, bool>
     */
    public static function all_defaults(): array {
        $defaults = [];

        foreach ( self::pro_email_groups() as $data ) {
            foreach ( $data['items'] as $id => $item ) {
                $defaults[ $id ] = $item['default_enabled'];
            }
        }

        return $defaults;
    }
}
