<?php
namespace YayTests\Unit\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextLineItem;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Models\CampaignConditionSet;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Utils\ConditionMatch;
use YayTests\Unit\TestCase;

/**
 * Every campaign condition type, paired with a control condition of another type, under both matches:
 * `all` holds only when both hold, `any` when either does.
 *
 * @group P-1
 */
final class CampaignConditionMatchPerTypeTest extends TestCase {

    /**
     * Each type's condition, the Context values that make it hold and fail, and its control.
     * The control is order count >= 5, or lifetime points >= 500 for order count itself.
     *
     * @return array<string, array{array, array, array, string}>
     */
    private function types(): array {
        return [
            'user_role'        => [ $this->set( 'user_role', [ 'vip' ] ), [ 'roles' => [ 'vip' ] ], [ 'roles' => [ 'customer' ] ], 'orders' ],
            'customer_ids'     => [ $this->set( 'customer_ids', [ 7 ] ), [ 'user_id' => 7 ], [ 'user_id' => 8 ], 'orders' ],
            'currency'         => [ $this->set( 'currency', [ 'EUR' ] ), [ 'currency' => 'EUR' ], [ 'currency' => 'USD' ], 'orders' ],
            'payment_method'   => [ $this->set( 'payment_method', [ 'cod' ] ), [ 'payment' => 'cod' ], [ 'payment' => 'bacs' ], 'orders' ],
            'country'          => [
                [
                    'type'     => 'country',
                    'operator' => 'in',
                    'value'    => [
                        'countries' => [ 'US' ],
                        'source'    => 'billing',
                    ],
                ],
                [ 'billing' => 'US' ],
                [ 'billing' => 'CA' ],
                'orders',
            ],
            'product_in_cart'  => [ $this->set( 'product_in_cart', [ 10 ] ), [ 'product' => 10 ], [ 'product' => 11 ], 'orders' ],
            'category_in_cart' => [ $this->set( 'category_in_cart', [ 3 ] ), [ 'category' => 3 ], [ 'category' => 4 ], 'orders' ],
            'lifetime_points'  => [ $this->numeric( 'lifetime_points', 500 ), [ 'lifetime' => 600 ], [ 'lifetime' => 100 ], 'orders' ],
            'order_count'      => [ $this->numeric( 'order_count', 5 ), [ 'orders' => 5 ], [ 'orders' => 4 ], 'lifetime' ],
            'lifetime_spend'   => [
                [
                    'type'     => 'lifetime_spend',
                    'operator' => 'gte',
                    'value'    => [
                        'amount'   => 500,
                        'currency' => 'USD',
                    ],
                ],
                [ 'spend' => 600.0 ],
                [ 'spend' => 100.0 ],
                'orders',
            ],
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
        list( $condition, $pass, $fail, $control_key ) = $this->types()[ $type ];

        $control = 'orders' === $control_key ? $this->numeric( 'order_count', 5 ) : $this->numeric( 'lifetime_points', 500 );
        $values  = array_merge(
            $holds ? $pass : $fail,
            'orders' === $control_key ? [ 'orders' => $control_holds ? 5 : 0 ] : [ 'lifetime' => $control_holds ? 600 : 0 ]
        );
        $ctx     = $this->context( $values );

        $evaluator = new ConditionsEvaluator();

        $this->assertSame( $holds, $evaluator->evaluate( [ $condition ], $ctx ), "$type alone" );
        $this->assertSame(
            $holds && $control_holds,
            $evaluator->evaluate(
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
            $evaluator->evaluate(
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
     * @return array<string, array{string[]}>
     */
    public function lists(): array {
        return [
            'three conditions' => [ [ 'user_role', 'currency', 'country' ] ],
            'four conditions'  => [ [ 'country', 'product_in_cart', 'lifetime_spend', 'order_count' ] ],
            'every type'       => [ array_keys( $this->types() ) ],
        ];
    }

    /**
     * Every hold/fail combination of several conditions in one campaign: `all` holds only when every
     * condition does, `any` when at least one does.
     *
     * @dataProvider lists
     * @param string[] $list_types
     */
    public function test_a_longer_list_needs_every_condition_under_all_and_one_under_any( array $list_types ) {
        $types      = $this->types();
        $conditions = [];

        foreach ( $list_types as $type ) {
            $conditions[] = $types[ $type ][0];
        }

        $evaluator = new ConditionsEvaluator();
        $count     = count( $list_types );

        for ( $mask = 0; $mask < ( 1 << $count ); $mask++ ) {
            $values = [];
            $held   = 0;

            foreach ( $list_types as $i => $type ) {
                $holds = (bool) ( $mask & ( 1 << $i ) );
                $held += $holds ? 1 : 0;
                $values = array_merge( $values, $holds ? $types[ $type ][1] : $types[ $type ][2] );
            }

            $ctx   = $this->context( $values );
            $label = sprintf( '%d of %d hold (mask %d)', $held, $count, $mask );

            $all = $evaluator->explain(
                [
                    'match'      => 'all',
                    'conditions' => $conditions,
                ],
                $ctx
            );

            $this->assertCount( $count, $all['conditions'], $label );
            $this->assertSame( $held, count( array_filter( array_column( $all['conditions'], 'matched' ) ) ), "$label: per-condition results" );
            $this->assertSame( $held === $count, $all['matched'], "$label under all" );
            $this->assertSame(
                $held > 0,
                $evaluator->evaluate(
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

    public function test_a_four_condition_any_list_keeps_its_match_and_conditions_through_storage() {
        \Brain\Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $types = $this->types();
        $list  = [
            'match'      => 'any',
            'conditions' => [ $types['country'][0], $types['product_in_cart'][0], $types['lifetime_spend'][0], $types['order_count'][0] ],
        ];

        $this->assertTrue( CampaignConditionSet::is_valid( $list ) );

        $stored = ConditionMatch::decode( ConditionMatch::encode( CampaignConditionSet::normalize( $list ), 1 ) );

        $this->assertSame( 'any', $stored['match'] );
        // normalize() stores conditions in the type's declaration order, which doesn't change the verdict.
        $this->assertSame( [ 'country', 'product_in_cart', 'order_count', 'lifetime_spend' ], array_column( $stored['conditions'], 'type' ) );

        // Only the order count holds: any matches before and after storage, all doesn't.
        $ctx       = $this->context( [ 'orders' => 5 ] );
        $evaluator = new ConditionsEvaluator();

        $this->assertTrue( $evaluator->evaluate( $stored, $ctx ) );
        $this->assertFalse( $evaluator->evaluate( array_merge( $stored, [ 'match' => 'all' ] ), $ctx ) );
    }

    /**
     * A Context for user 7, a customer paying USD by bank transfer from CA, with product 1 in category 1,
     * and no history, changed by $values.
     *
     * @param array<string, mixed> $values
     */
    private function context( array $values ): Context {
        $v = array_merge(
            [
                'user_id'  => 7,
                'roles'    => [ 'customer' ],
                'currency' => 'USD',
                'payment'  => 'bacs',
                'billing'  => 'CA',
                'product'  => 1,
                'category' => 1,
                'lifetime' => 0,
                'orders'   => 0,
                'spend'    => 0.0,
            ],
            $values
        );

        return new Context(
            $v['user_id'],
            '2026-11-13 00:00:00',
            $v['currency'],
            1.0,
            [ new ContextLineItem( $v['product'], null, [ $v['category'] ], 1, 10.0, 10.0 ) ],
            10.0,
            0.0,
            [],
            $v['roles'],
            99,
            null,
            [
                Context::EXTRA_PAYMENT_METHOD  => $v['payment'],
                Context::EXTRA_BILLING_COUNTRY => $v['billing'],
            ],
            new CustomerFacts( $v['lifetime'], $v['orders'], [ 'USD' => $v['spend'] ] )
        );
    }

    private function set( string $type, array $values ): array {
        return [
            'type'     => $type,
            'operator' => 'in',
            'value'    => $values,
        ];
    }

    private function numeric( string $type, int $at_least ): array {
        return [
            'type'     => $type,
            'operator' => 'gte',
            'value'    => $at_least,
        ];
    }
}
