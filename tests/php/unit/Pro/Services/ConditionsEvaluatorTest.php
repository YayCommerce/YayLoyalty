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
     * @return array<string, array{string, int, bool, bool}>
     */
    public function match_matrix(): array {
        return [
            'all, both hold' => [ 'all', 5, true, true ],
            'all, one holds' => [ 'all', 4, true, false ],
            'any, one holds' => [ 'any', 4, true, true ],
            'any, neither'   => [ 'any', 4, false, false ],
        ];
    }

    /**
     * Country US (billing) and order count >= 5, joined by `match`.
     *
     * @dataProvider match_matrix
     */
    public function test_the_match_joins_the_conditions( string $match_mode, int $orders, bool $is_us, bool $expected ) {
        $evaluator  = new ConditionsEvaluator();
        $ctx        = $this->context( $is_us ? 'US' : 'CA', 0.0, $orders );
        $conditions = [
            'match'      => $match_mode,
            'conditions' => [ $this->country_us(), $this->order_count( 5 ) ],
        ];

        $report = $evaluator->explain( $conditions, $ctx );

        $this->assertSame( $expected, $report['matched'] );
        $this->assertSame( $report['matched'], $evaluator->evaluate( $conditions, $ctx ) );
        $this->assertSame( $match_mode, $report['match'] );
        $this->assertCount( 2, $report['conditions'] );
    }

    public function test_a_flat_list_still_ands_every_condition() {
        $evaluator = new ConditionsEvaluator();
        $flat      = [ $this->country_us(), $this->order_count( 5 ) ];

        $this->assertFalse( $evaluator->evaluate( $flat, $this->context( 'US', 0.0, 4 ) ) );
        $this->assertTrue( $evaluator->evaluate( $flat, $this->context( 'US', 0.0, 5 ) ) );
        $this->assertSame( 'all', $evaluator->explain( $flat, $this->context( 'US', 0.0, 4 ) )['match'] );
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
