<?php
namespace YayLoyalty\Tests\Unit\Pro;

use YayLoyalty\Engine\Points\CapPolicy;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextLineItem;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Enums\CampaignConditionType as T;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Tests\Unit\EngineTestCase;

/** Every condition is a comparison against the Context; nothing is queried. */
final class ConditionsEvaluatorTest extends EngineTestCase {

    /** An order by customer 7 (role customer): variation 11 of product 10 in category 5, paid by card, billed to US, shipped to CA. */
    private function order_ctx( ?int $user_id = 7, array $extra = [] ): Context {
        $line  = new ContextLineItem( 10, 11, [ 5 ], 1, 100.0, 100.0 );
        $facts = null === $user_id ? null : new CustomerFacts( 500, 2, [ 'USD' => 250.0 ] );
        $extra = array_merge(
            [
                Context::EXTRA_PAYMENT_METHOD   => 'card',
                Context::EXTRA_BILLING_COUNTRY  => 'US',
                Context::EXTRA_SHIPPING_COUNTRY => 'CA',
            ],
            $extra
        );

        return $this->context( [ $line ], 0.0, [], 1.0, 0, $user_id, $extra, null, $facts );
    }

    /** What the admin preview builds: a customer with facts, no order. */
    private function customer_ctx(): Context {
        return new Context( 7, self::AT, 'USD', 1.0, [], 0.0, 0.0, 0.0, [], [ 'customer' ], null, null, 0, [], new CustomerFacts( 500, 2, [] ) );
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function row( string $type, string $operator, $value, ?Context $ctx = null ): array {
        return ( new ConditionsEvaluator() )->explain(
            [
                [
                    'type'     => $type,
                    'operator' => $operator,
                    'value'    => $value,
                ],
            ],
            $ctx ?? $this->order_ctx()
        )[0];
    }

    private function is_met( string $type, string $operator, $value, ?Context $ctx = null ): bool {
        return $this->row( $type, $operator, $value, $ctx )['matched'];
    }

    /** @return array<string, mixed> A country condition value. */
    private function country( string $code, string $source ): array {
        return [
            'countries' => [ $code ],
            'source'    => $source,
        ];
    }

    /** @return array<string, mixed> A lifetime_spend condition value. */
    private function money( int $amount, string $currency ): array {
        return [
            'amount'   => $amount,
            'currency' => $currency,
        ];
    }

    public function test_user_role(): void {
        $this->assertTrue( $this->is_met( T::USER_ROLE, T::OP_IN, [ 'customer' ] ) );
        $this->assertFalse( $this->is_met( T::USER_ROLE, T::OP_NOT_IN, [ 'customer' ] ) );
    }

    public function test_customer_ids(): void {
        $this->assertTrue( $this->is_met( T::CUSTOMER_IDS, T::OP_IN, [ 7, 8 ] ) );
        $this->assertFalse( $this->is_met( T::CUSTOMER_IDS, T::OP_IN, [ 8 ] ) );
    }

    public function test_lifetime_points_between(): void {
        $this->assertTrue( $this->is_met( T::LIFETIME_POINTS, T::OP_BETWEEN, [ 100, 500 ] ) );
        $this->assertFalse( $this->is_met( T::LIFETIME_POINTS, T::OP_BETWEEN, [ 501, 900 ] ) );
    }

    /** The facts already mean "paid orders before this one": no +1 for the order being scored. */
    public function test_order_count_is_the_facts_value(): void {
        $this->assertTrue( $this->is_met( T::ORDER_COUNT, T::OP_GTE, 2 ) );
        $this->assertFalse( $this->is_met( T::ORDER_COUNT, T::OP_GTE, 3 ) );
    }

    public function test_lifetime_spend_counts_only_its_currency(): void {
        $this->assertTrue( $this->is_met( T::LIFETIME_SPEND, T::OP_GTE, $this->money( 250, 'usd' ) ) );
        $this->assertFalse( $this->is_met( T::LIFETIME_SPEND, T::OP_GT, $this->money( 0, 'EUR' ) ) );
    }

    public function test_currency_and_payment_method_come_from_the_order(): void {
        $this->assertTrue( $this->is_met( T::CURRENCY, T::OP_IN, [ 'USD' ] ) );
        $this->assertTrue( $this->is_met( T::PAYMENT_METHOD, T::OP_IN, [ 'card' ] ) );
        $this->assertFalse( $this->is_met( T::PAYMENT_METHOD, T::OP_IN, [ 'cod' ] ) );
    }

    public function test_country_by_source(): void {
        $this->assertTrue( $this->is_met( T::COUNTRY, T::OP_IN, $this->country( 'US', 'billing' ) ) );
        $this->assertFalse( $this->is_met( T::COUNTRY, T::OP_IN, $this->country( 'US', 'shipping' ) ) );
        $this->assertTrue( $this->is_met( T::COUNTRY, T::OP_IN, $this->country( 'CA', 'any' ) ) );
    }

    public function test_empty_shipping_country_never_matches(): void {
        $virtual = $this->order_ctx( 7, [ Context::EXTRA_SHIPPING_COUNTRY => '' ] );

        $this->assertFalse( $this->is_met( T::COUNTRY, T::OP_IN, $this->country( 'CA', 'shipping' ), $virtual ) );
    }

    public function test_product_in_cart_matches_the_parent_of_a_variation_line(): void {
        $this->assertTrue( $this->is_met( T::PRODUCT_IN_CART, T::OP_IN, [ 10 ] ) );
        $this->assertFalse( $this->is_met( T::PRODUCT_IN_CART, T::OP_IN, [ 11 ] ) );
    }

    public function test_category_in_cart(): void {
        $this->assertTrue( $this->is_met( T::CATEGORY_IN_CART, T::OP_IN, [ 5 ] ) );
        $this->assertTrue( $this->is_met( T::CATEGORY_IN_CART, T::OP_NOT_IN, [ 6 ] ) );
    }

    /** Without the no_customer guard a guest's empty role list would satisfy not_in. */
    public function test_guest_fails_customer_conditions_with_no_customer(): void {
        $guest = $this->order_ctx( null );

        $this->assertSame( 'no_customer', $this->row( T::USER_ROLE, T::OP_NOT_IN, [ 'administrator' ], $guest )['reason'] );
        $this->assertSame( 'no_customer', $this->row( T::ORDER_COUNT, T::OP_GTE, 0, $guest )['reason'] );
        $this->assertTrue( $this->is_met( T::CURRENCY, T::OP_IN, [ 'USD' ], $guest ) );
    }

    public function test_no_order_fails_order_conditions_with_needs_order(): void {
        $row = $this->row( T::PRODUCT_IN_CART, T::OP_IN, [ 10 ], $this->customer_ctx() );

        $this->assertFalse( $row['matched'] );
        $this->assertSame( 'needs_order', $row['reason'] );
        $this->assertTrue( $this->is_met( T::ORDER_COUNT, T::OP_EQ, 2, $this->customer_ctx() ) );
    }

    public function test_malformed_values_fail(): void {
        $this->assertSame( 'malformed_value', $this->row( T::COUNTRY, T::OP_IN, 'US' )['reason'] );
        $this->assertSame( 'malformed_value', $this->row( T::LIFETIME_SPEND, T::OP_GTE, 100 )['reason'] );
    }

    public function test_unknown_and_unsupported_types_fail(): void {
        $this->assertSame( 'unknown_condition_type', $this->row( 'made_up', T::OP_IN, [ 1 ] )['reason'] );
        $this->assertSame( 'unsupported_condition_type', $this->row( T::TIER, T::OP_IN, [ 1 ] )['reason'] );
    }

    public function test_engine_falls_through_to_the_campaign_whose_conditions_match(): void {
        $evaluator = new ConditionsEvaluator();
        add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, static fn() => $evaluator );

        $high = $this->with_conditions(
            $this->purchase_campaign( [], 10, 2 ),
            [
                [
                    'type'     => T::PRODUCT_IN_CART,
                    'operator' => T::OP_IN,
                    'value'    => [ 99 ],
                ],
            ]
        );
        $low  = $this->purchase_campaign( [], 0, 1 );

        $repository = $this->repository( [ $high, $low ] );
        $engine     = new PointsEngine( Registry::with_lite_handlers( $repository ), $repository, new CapPolicy() );

        $this->assertSame( 1, $engine->evaluate( CampaignTrigger::PURCHASE, $this->order_ctx() )->get_campaign()->get_id() );
    }

    public function test_facts_are_not_loaded_when_no_history_condition_asks(): void {
        $loads = 0;
        $ctx   = $this->lazy_ctx( $loads, new CustomerFacts( 500, 2, [] ) );

        $this->assertTrue( $this->is_met( T::USER_ROLE, T::OP_IN, [ 'customer' ], $ctx ) );
        $this->assertSame( 0, $loads );
    }

    public function test_facts_are_loaded_once_however_many_history_conditions_ask(): void {
        $loads      = 0;
        $ctx        = $this->lazy_ctx( $loads, new CustomerFacts( 500, 2, [ 'USD' => 250.0 ] ) );
        $conditions = [
            [
                'type'     => T::ORDER_COUNT,
                'operator' => T::OP_GTE,
                'value'    => 2,
            ],
            [
                'type'     => T::LIFETIME_SPEND,
                'operator' => T::OP_GTE,
                'value'    => [
                    'amount'   => 100,
                    'currency' => 'USD',
                ],
            ],
        ];

        $this->assertTrue( ( new ConditionsEvaluator() )->evaluate( $conditions, $ctx ) );
        $this->assertTrue( ( new ConditionsEvaluator() )->evaluate( $conditions, $ctx ) );
        $this->assertSame( 1, $loads );
    }

    public function test_a_loader_returning_null_reads_as_no_customer(): void {
        $loads = 0;

        $this->assertSame( 'no_customer', $this->row( T::ORDER_COUNT, T::OP_GTE, 1, $this->lazy_ctx( $loads, null ) )['reason'] );
    }

    /** A customer Context whose facts come from a loader that counts its calls. */
    private function lazy_ctx( int &$loads, ?CustomerFacts $facts ): Context {
        $loader = static function () use ( &$loads, $facts ): ?CustomerFacts {
            ++$loads;

            return $facts;
        };

        return new Context( 7, self::AT, 'USD', 1.0, [], 0.0, 0.0, 0.0, [], [ 'customer' ], null, null, 0, [], $loader );
    }

    private function with_conditions( Campaign $campaign, array $conditions ): Campaign {
        return new Campaign(
            $campaign->get_id(),
            $campaign->get_name(),
            $campaign->get_slug(),
            $campaign->get_trigger(),
            $campaign->get_status(),
            $campaign->get_priority(),
            $campaign->get_config(),
            $conditions
        );
    }
}
