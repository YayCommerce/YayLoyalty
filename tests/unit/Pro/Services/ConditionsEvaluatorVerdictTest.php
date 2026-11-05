<?php
namespace YayLoyalty\Tests\Unit\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextLineItem;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Tests\Unit\EngineTestCase;

/**
 * Before an order exists a condition is true, false or unknown: what the storefront may promise depends on which.
 */
final class ConditionsEvaluatorVerdictTest extends EngineTestCase {

    /** @var ConditionsEvaluator */
    private $evaluator;

    protected function setUp(): void {
        $this->evaluator = new ConditionsEvaluator();
    }

    public function test_category_is_known_from_the_lines_without_an_order(): void {
        $conditions = [
            [
                'type'     => 'category_in_cart',
                'operator' => 'in',
                'value'    => [ 5 ],
            ],
        ];

        $this->assertTrue( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0, null, 10, [ 5 ] ) ] ) ) );
        $this->assertFalse( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0, null, 10, [ 6 ] ) ] ) ) );
    }

    public function test_payment_method_is_unknown_without_an_order(): void {
        $conditions = [
            [
                'type'     => 'payment_method',
                'operator' => 'in',
                'value'    => [ 'stripe' ],
            ],
        ];

        $this->assertNull( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0 ) ] ) ) );
    }

    public function test_a_failed_condition_beats_an_unknown_one(): void {
        $conditions = [
            [
                'type'     => 'payment_method',
                'operator' => 'in',
                'value'    => [ 'stripe' ],
            ],
            [
                'type'     => 'category_in_cart',
                'operator' => 'in',
                'value'    => [ 5 ],
            ],
        ];

        $this->assertFalse( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0, null, 10, [ 6 ] ) ] ) ) );
    }

    public function test_a_guest_fails_a_customer_condition(): void {
        $conditions = [
            [
                'type'     => 'user_role',
                'operator' => 'in',
                'value'    => [ 'customer' ],
            ],
        ];

        $this->assertFalse( $this->evaluator->verdict( $conditions, $this->shopping( [ $this->line( 10.0 ) ], null ) ) );
    }

    public function test_no_order_and_no_lines_still_reports_needs_order(): void {
        $conditions = [
            [
                'type'     => 'product_in_cart',
                'operator' => 'in',
                'value'    => [ 10 ],
            ],
        ];

        $this->assertSame( 'needs_order', $this->evaluator->explain( $conditions, $this->shopping( [] ) )[0]['reason'] );
    }

    /**
     * A shopper with no order yet.
     *
     * @param ContextLineItem[] $lines
     */
    private function shopping( array $lines, ?int $user_id = 7 ): Context {
        return new Context( $user_id, self::AT, 'USD', 1.0, $lines, 10.0, 0.0, [], null === $user_id ? [] : [ 'customer' ] );
    }
}
