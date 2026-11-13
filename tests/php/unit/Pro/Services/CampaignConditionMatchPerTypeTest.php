<?php
namespace YayTests\Unit\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextLineItem;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
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
