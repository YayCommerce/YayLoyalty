<?php
namespace YayLoyalty\Tests\Unit\Pro;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextLineItem;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Tests\Unit\EngineTestCase;

/**
 * Order conditions on a Context with no order yet (a product page, a cart): evaluated when their input is on the
 * Context, `needs_order` otherwise. A Context with an order must explain exactly as before, so awards don't move.
 */
final class ConditionsEvaluatorDraftContextTest extends EngineTestCase {

    /** explain()'s `matched` and `reason` for all_order_conditions() on order_context(), recorded before draft Contexts were evaluated. */
    private const EXPECTED_ORDER_RESULTS = [
        [ true, 'matched' ],
        [ false, 'no_overlap' ],
        [ true, 'matched' ],
        [ false, 'no_overlap' ],
        [ false, 'no_overlap' ],
        [ true, 'matched' ],
        [ true, 'matched' ],
        [ false, 'no_overlap' ],
        [ true, 'matched' ],
        [ false, 'no_overlap' ],
        [ true, 'matched' ],
        [ false, 'no_overlap' ],
        [ true, 'matched' ],
        [ false, 'no_overlap' ],
    ];

    public function test_order_context_results_are_unchanged(): void {
        $results = ( new ConditionsEvaluator() )->explain( self::all_order_conditions(), $this->order_context() );

        $this->assertSame( self::EXPECTED_ORDER_RESULTS, self::matched_and_reasons( $results ) );
    }

    public function test_order_context_without_lines_or_extras_is_unchanged(): void {
        $results = ( new ConditionsEvaluator() )->explain( self::all_order_conditions(), $this->context( [] ) );

        $this->assertNotContains( 'needs_order', array_column( $results, 'reason' ) );
    }

    public function test_draft_with_lines_evaluates_product_category_and_currency(): void {
        $results = ( new ConditionsEvaluator() )->explain(
            [
                self::condition( 'product_in_cart', 'in', [ 10 ] ),
                self::condition( 'category_in_cart', 'in', [ 6 ] ),
                self::condition( 'currency', 'in', [ 'USD' ] ),
            ],
            $this->draft( [ $this->line( 100, null, 10, [ 5 ] ) ], [] )
        );

        $this->assertSame( [ true, false, true ], array_column( $results, 'matched' ) );
        $this->assertSame( [ 'matched', 'no_overlap', 'matched' ], array_column( $results, 'reason' ) );
    }

    public function test_draft_without_payment_or_country_needs_the_order(): void {
        $results = ( new ConditionsEvaluator() )->explain(
            [
                self::condition( 'payment_method', 'in', [ 'cod' ] ),
                self::condition(
                    'country',
                    'in',
                    [
                        'countries' => [ 'VN' ],
                        'source'    => 'shipping',
                    ]
                ),
                self::condition(
                    'country',
                    'in',
                    [
                        'countries' => [ 'VN' ],
                        'source'    => 'any',
                    ]
                ),
            ],
            $this->draft(
                [ $this->line( 100 ) ],
                [
                    Context::EXTRA_PAYMENT_METHOD  => '',
                    Context::EXTRA_BILLING_COUNTRY => 'VN',
                ]
            )
        );

        $this->assertSame( [ 'needs_order', 'needs_order', 'matched' ], array_column( $results, 'reason' ) );
        $this->assertTrue( $results[2]['matched'] );
    }

    public function test_draft_with_a_payment_method_evaluates_it(): void {
        $results = ( new ConditionsEvaluator() )->explain(
            [ self::condition( 'payment_method', 'not_in', [ 'cod' ] ) ],
            $this->draft( [], [ Context::EXTRA_PAYMENT_METHOD => 'bacs' ] )
        );

        $this->assertTrue( $results[0]['matched'] );
    }

    public function test_draft_without_lines_needs_the_order_for_products(): void {
        // The admin preview's Context (from_user): no lines, no order.
        $results = ( new ConditionsEvaluator() )->explain(
            [
                self::condition( 'product_in_cart', 'not_in', [ 10 ] ),
                self::condition( 'category_in_cart', 'in', [ 5 ] ),
            ],
            $this->draft( [], [] )
        );

        $this->assertSame( [ 'needs_order', 'needs_order' ], array_column( $results, 'reason' ) );
        $this->assertSame( [ false, false ], array_column( $results, 'matched' ) );
    }

    /** An order (id 555) with one line in category 5, paid by cod, billed to VN, with no shipping address. */
    private function order_context(): Context {
        return $this->context(
            [ $this->line( 100, null, 10, [ 5 ] ) ],
            [],
            1.0,
            7,
            [
                Context::EXTRA_PAYMENT_METHOD   => 'cod',
                Context::EXTRA_BILLING_COUNTRY  => 'VN',
                Context::EXTRA_SHIPPING_COUNTRY => '',
            ]
        );
    }

    /**
     * A product page's or cart's Context: a customer, no order yet.
     *
     * @param ContextLineItem[]    $lines
     * @param array<string, mixed> $extra
     */
    private function draft( array $lines, array $extra ): Context {
        $subtotal = 0.0;

        foreach ( $lines as $line ) {
            $subtotal += $line->get_subtotal();
        }

        return new Context( 7, self::AT, 'USD', 1.0, $lines, $subtotal, 0.0, [], [ 'customer' ], null, null, $extra );
    }

    /** One `in` and one `not_in` condition per order type, each country source included. */
    private static function all_order_conditions(): array {
        $conditions = [];
        $values     = [
            'currency'         => [ 'USD' ],
            'payment_method'   => [ 'cod' ],
            'country:shipping' => [
                'countries' => [ 'VN' ],
                'source'    => 'shipping',
            ],
            'country:billing'  => [
                'countries' => [ 'VN' ],
                'source'    => 'billing',
            ],
            'country:any'      => [
                'countries' => [ 'VN' ],
                'source'    => 'any',
            ],
            'product_in_cart'  => [ 10 ],
            'category_in_cart' => [ 5 ],
        ];

        foreach ( $values as $key => $value ) {
            $type = explode( ':', $key )[0];

            $conditions[] = self::condition( $type, 'in', $value );
            $conditions[] = self::condition( $type, 'not_in', $value );
        }

        return $conditions;
    }

    /** @param mixed $value */
    private static function condition( string $type, string $operator, $value ): array {
        return [
            'type'     => $type,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    /** @return array<int, array{0: bool, 1: string}> */
    private static function matched_and_reasons( array $results ): array {
        return array_map(
            static function ( array $result ): array {
                return [ $result['matched'], $result['reason'] ];
            },
            $results
        );
    }
}
