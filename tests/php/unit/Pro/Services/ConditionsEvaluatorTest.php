<?php
namespace YayTests\Unit\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayTests\Unit\TestCase;

/**
 * @group P-1
 */
final class ConditionsEvaluatorTest extends TestCase {

    /**
     * @return array<string, array{string, float, int, bool}>
     */
    public function acceptance_matrix(): array {
        return [
            'US, spend only'       => [ 'US', 600.0, 0, true ],
            'US, orders only'      => [ 'US', 0.0, 5, true ],
            'US, neither'          => [ 'US', 499.0, 4, false ],
            'not US, spend+orders' => [ 'CA', 600.0, 5, false ],
        ];
    }

    /**
     * Country US (billing) AND ( lifetime spend >= 500 USD OR order count >= 5 ).
     *
     * @dataProvider acceptance_matrix
     */
    public function test_the_acceptance_example_matches_only_the_right_customers( string $country, float $spend, int $orders, bool $expected ) {
        $evaluator = new ConditionsEvaluator();
        $ctx       = $this->context( $country, $spend, $orders );
        $tree      = [
            'match'  => 'all',
            'groups' => [
                [
                    'match'      => 'all',
                    'conditions' => [ $this->country_us() ],
                ],
                [
                    'match'      => 'any',
                    'conditions' => [
                        [
                            'type'     => 'lifetime_spend',
                            'operator' => 'gte',
                            'value'    => [
                                'amount'   => 500,
                                'currency' => 'USD',
                            ],
                        ],
                        $this->order_count( 5 ),
                    ],
                ],
            ],
        ];

        $report = $evaluator->explain( $tree, $ctx );

        $this->assertSame( $expected, $report['matched'] );
        $this->assertSame( $report['matched'], $evaluator->evaluate( $tree, $ctx ) );
        $this->assertCount( 2, $report['groups'] );
        $this->assertSame( 'any', $report['groups'][1]['match'] );
        $this->assertSame( 'US' === $country, $report['groups'][0]['matched'] );
    }

    public function test_a_flat_list_still_ands_every_condition() {
        $evaluator = new ConditionsEvaluator();
        $flat      = [ $this->country_us(), $this->order_count( 5 ) ];

        $this->assertFalse( $evaluator->evaluate( $flat, $this->context( 'US', 0.0, 4 ) ) );
        $this->assertTrue( $evaluator->evaluate( $flat, $this->context( 'US', 0.0, 5 ) ) );

        $report = $evaluator->explain( $flat, $this->context( 'US', 0.0, 4 ) );
        $this->assertCount( 1, $report['groups'] );
        $this->assertSame( 'all', $report['groups'][0]['match'] );
        $this->assertSame( $report['matched'], $evaluator->evaluate( $flat, $this->context( 'US', 0.0, 4 ) ) );
    }

    public function test_ored_groups_match_when_one_group_matches() {
        $evaluator = new ConditionsEvaluator();
        $tree      = [
            'match'  => 'any',
            'groups' => [
                [
                    'match'      => 'all',
                    'conditions' => [ $this->order_count( 10 ) ],
                ],
                [
                    'match'      => 'all',
                    'conditions' => [ $this->country_us() ],
                ],
            ],
        ];

        $this->assertTrue( $evaluator->evaluate( $tree, $this->context( 'US', 0.0, 0 ) ) );
        $this->assertFalse( $evaluator->evaluate( $tree, $this->context( 'CA', 0.0, 0 ) ) );
    }

    private function context( string $billing_country, float $spend, int $orders ): Context {
        return new Context(
            7,
            '2026-11-13 00:00:00',
            'USD',
            1.0,
            [],
            0.0,
            0.0,
            [],
            [ 'customer' ],
            99,
            null,
            [ Context::EXTRA_BILLING_COUNTRY => $billing_country ],
            new CustomerFacts( 0, $orders, [ 'USD' => $spend ] )
        );
    }

    private function country_us(): array {
        return [
            'type'     => 'country',
            'operator' => 'in',
            'value'    => [
                'countries' => [ 'US' ],
                'source'    => 'billing',
            ],
        ];
    }

    private function order_count( int $count ): array {
        return [
            'type'     => 'order_count',
            'operator' => 'gte',
            'value'    => $count,
        ];
    }
}
