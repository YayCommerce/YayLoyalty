<?php
namespace YayTests\Unit\Pro\Services;

use Brain\Monkey\Functions;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Models\Reward;
use YayLoyalty\Pro\Services\RewardConditionsEvaluator;
use YayTests\Unit\TestCase;

/**
 * Every reward condition type, paired with a control condition of another type, under both matches:
 * `all` holds only when both hold, `any` when either does.
 *
 * @group P-1
 */
final class RewardConditionMatchPerTypeTest extends TestCase {

    /** User 9 is a vip; user 7 is a plain customer. */
    private const VIP      = 9;
    private const CUSTOMER = 7;

    protected function set_up() {
        parent::set_up();

        Functions\when( 'get_userdata' )->alias(
            static function ( $user_id ) {
                $roles = [
                    self::CUSTOMER => [ 'customer' ],
                    self::VIP      => [ 'vip' ],
                    10             => [ 'vip' ],
                    11             => [ 'customer' ],
                ];

                return isset( $roles[ $user_id ] ) ? (object) [ 'roles' => $roles[ $user_id ] ] : false;
            }
        );
    }

    /**
     * Each type's condition, its control, and whether each holds is set by who is shopping and their
     * lifetime points: the role and customer conditions hold for the vip, the points ones from 1000.
     *
     * @return array<string, array{array, array, string}>
     */
    private function types(): array {
        $role   = [
            'type'     => 'user_role',
            'operator' => 'in',
            'value'    => [ 'vip' ],
        ];
        $points = [
            'type'     => 'lifetime_points',
            'operator' => 'gte',
            'value'    => 1000,
        ];

        return [
            'user_role'       => [ $role, $points, 'user' ],
            'customer_ids'    => [
                [
                    'type'     => 'customer_ids',
                    'operator' => 'in',
                    'value'    => [ self::VIP ],
                ],
                $points,
                'user',
            ],
            'lifetime_points' => [ $points, $role, 'points' ],
        ];
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public function cases(): array {
        $cases = [];

        foreach ( array_keys( $this->types() ) as $type ) {
            foreach ( [ true, false ] as $holds ) {
                foreach ( [ true, false ] as $control_holds ) {
                    $label           = sprintf( '%s %s, control %s', $type, $holds ? 'holds' : 'fails', $control_holds ? 'holds' : 'fails' );
                    $cases[ $label ] = [ $type, $holds, $control_holds ];
                }
            }
        }

        return $cases;
    }

    /**
     * @dataProvider cases
     */
    public function test_all_needs_both_and_any_needs_one( string $type, bool $holds, bool $control_holds ) {
        list( $condition, $control, $driven_by ) = $this->types()[ $type ];

        $user_holds   = 'user' === $driven_by ? $holds : $control_holds;
        $points_holds = 'user' === $driven_by ? $control_holds : $holds;
        $ctx          = $this->ctx( $user_holds ? self::VIP : self::CUSTOMER, $points_holds ? 1500 : 10 );

        $this->assertSame( $holds, $this->redeemable( [ $condition ], $ctx ), "$type alone" );
        $this->assertSame(
            $holds && $control_holds,
            $this->redeemable(
                [
                    'match'      => 'all',
                    'conditions' => [ $condition, $control ],
                ],
                $ctx
            ),
            "$type under all"
        );
        $this->assertSame(
            $holds || $control_holds,
            $this->redeemable(
                [
                    'match'      => 'any',
                    'conditions' => [ $condition, $control ],
                ],
                $ctx
            ),
            "$type under any"
        );
    }

    /**
     * All three types in one reward, over every hold/fail combination. The shopper picks the role and
     * customer results: 9 is a listed vip, 10 an unlisted vip, 11 a listed customer, 7 neither.
     */
    public function test_all_three_types_in_one_reward() {
        $conditions = [
            [
                'type'     => 'user_role',
                'operator' => 'in',
                'value'    => [ 'vip' ],
            ],
            [
                'type'     => 'customer_ids',
                'operator' => 'in',
                'value'    => [ self::VIP, 11 ],
            ],
            [
                'type'     => 'lifetime_points',
                'operator' => 'gte',
                'value'    => 1000,
            ],
        ];
        $shoppers   = [
            self::VIP      => [ true, true ],
            10             => [ true, false ],
            11             => [ false, true ],
            self::CUSTOMER => [ false, false ],
        ];

        foreach ( $shoppers as $user_id => list( $role_holds, $listed ) ) {
            foreach ( [ true, false ] as $points_hold ) {
                $ctx   = $this->ctx( $user_id, $points_hold ? 1500 : 10 );
                $held  = (int) $role_holds + (int) $listed + (int) $points_hold;
                $label = sprintf( 'user %d, %d of 3 hold', $user_id, $held );

                $this->assertSame(
                    3 === $held,
                    $this->redeemable(
                        [
                            'match'      => 'all',
                            'conditions' => $conditions,
                        ],
                        $ctx
                    ),
                    "$label under all"
                );
                $this->assertSame(
                    $held > 0,
                    $this->redeemable(
                        [
                            'match'      => 'any',
                            'conditions' => $conditions,
                        ],
                        $ctx
                    ),
                    "$label under any"
                );
            }
        }
    }

    private function redeemable( array $conditions, Context $ctx ): bool {
        $reward = new Reward( 1, 'VIP $5', 'vip-5', 'fixed_coupon', 'active', 0, 100, [], $conditions );

        return ( new RewardConditionsEvaluator() )->matches( true, $reward, $ctx );
    }

    private function ctx( int $user_id, int $lifetime_points ): Context {
        $loader = static function () use ( $lifetime_points ) {
            return new CustomerFacts( $lifetime_points, 0, [] );
        };

        return new Context( $user_id, 5000, null, null, 'USD', '2026-11-13 00:00:00', $loader );
    }
}
