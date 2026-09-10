<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a campaign's `conditions` against a customer.
 *
 * Attached to Lite through the `yayloyalty_campaign_conditions_evaluator`
 * seam. Lite stores conditions but cannot evaluate them, so with no evaluator
 * attached Campaign::conditions_match() returns true.
 *
 * THE TWO BUILDS DISAGREE ON UNKNOWNS, DELIBERATELY.
 *
 *   Lite: a condition it cannot evaluate MATCHES. Lite has no evaluator at
 *         all, so treating conditions as blocking would silently disable every
 *         conditional campaign the moment a site downgraded.
 *   Pro:  a condition it cannot evaluate FAILS. Pro *can* evaluate, so an
 *         unrecognised type or operator is a real error — a typo or a
 *         forward-dated config — and awarding points on it would be worse than
 *         withholding them.
 *
 * Different reasons, different answers. Do not "harmonise" them.
 *
 * Conditions are ANDed. There is no OR and no nesting: the data model
 * specifies a flat list of {type, operator, value} triples, and a boolean
 * expression tree is a different feature with a different UI.
 */
final class ConditionsEvaluator {

    /* --- Condition types --- */

    public const TYPE_ROLE             = 'role';
    public const TYPE_PRODUCT          = 'product';
    public const TYPE_PRODUCT_CATEGORY = 'product_category';
    public const TYPE_LIFETIME_SPEND   = 'lifetime_spend';
    public const TYPE_ORDER_COUNT      = 'order_count';

    /* --- Operators --- */

    /** Set operators: the customer's fact is a list, the condition value is a list. */
    private const SET_OPERATORS = [ 'in', 'not_in' ];

    /** Numeric operators: the customer's fact is a number. */
    private const NUMERIC_OPERATORS = [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte' ];

    /**
     * Order statuses that count as a purchase.
     *
     * Pending and failed orders are not purchases, and cancelled or refunded
     * ones have been undone. `processing` counts: the money has changed hands.
     */
    private const PAID_STATUSES = [ 'wc-processing', 'wc-completed' ];

    /**
     * How many of a customer's orders to scan for product and category facts.
     *
     * A ceiling, not a page size. Conditions are evaluated on the hot path of
     * awarding points, and a customer with thousands of orders would otherwise
     * make every award scan all of them. Newest first, so the cap trims the
     * oldest history — the part least likely to decide eligibility.
     */
    private const ORDER_SCAN_LIMIT = 100;

    /**
     * Purchased product ids, keyed by user id.
     *
     * Memoised per request: `product` and `product_category` conditions in the
     * same set would otherwise each trigger their own order scan.
     *
     * @var array<int, int[]>
     */
    private $purchased_products = [];

    /**
     * Do all conditions match?
     *
     * @param array $conditions Flat list of {type, operator, value} triples.
     */
    public function evaluate( array $conditions, int $user_id ): bool {
        foreach ( $this->explain( $conditions, $user_id ) as $result ) {
            if ( ! $result['matched'] ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Per-condition results, for the preview endpoint.
     *
     * Same evaluation path as evaluate() — evaluate() is a fold over this — so
     * the preview can never disagree with what actually happens at award time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function explain( array $conditions, int $user_id ): array {
        $results = [];

        foreach ( $conditions as $condition ) {
            $results[] = $this->evaluate_one( is_array( $condition ) ? $condition : [], $user_id );
        }

        return $results;
    }

    /**
     * @param array $condition
     * @return array<string, mixed> {type, operator, value, matched, reason}
     */
    private function evaluate_one( array $condition, int $user_id ): array {
        $type     = isset( $condition['type'] ) ? (string) $condition['type'] : '';
        $operator = isset( $condition['operator'] ) ? (string) $condition['operator'] : '';
        $value    = $condition['value'] ?? null;

        $result = [
            'type'     => $type,
            'operator' => $operator,
            'value'    => $value,
            'matched'  => false,
            'reason'   => '',
        ];

        switch ( $type ) {
            case self::TYPE_ROLE:
                return $this->apply_set( $result, $this->user_roles( $user_id ), $operator, $value );

            case self::TYPE_PRODUCT:
                return $this->apply_set( $result, $this->purchased_product_ids( $user_id ), $operator, $value );

            case self::TYPE_PRODUCT_CATEGORY:
                return $this->apply_set( $result, $this->purchased_category_ids( $user_id ), $operator, $value );

            case self::TYPE_LIFETIME_SPEND:
                return $this->apply_numeric( $result, $this->lifetime_spend( $user_id ), $operator, $value );

            case self::TYPE_ORDER_COUNT:
                return $this->apply_numeric( $result, (float) $this->order_count( $user_id ), $operator, $value );
        }

        return $this->reject( $result, 'unknown_condition_type' );
    }

    /**
     * Compare a list-valued customer fact against a list-valued condition.
     *
     * `in` matches when the customer has ANY of the listed values, which is
     * what a merchant means by "customers who bought from these categories".
     *
     * @param array<string, mixed> $result
     * @param array                $actual Customer's values.
     * @param mixed                $value  Condition's values.
     * @return array<string, mixed>
     */
    private function apply_set( array $result, array $actual, string $operator, $value ): array {
        if ( ! in_array( $operator, self::SET_OPERATORS, true ) ) {
            return $this->reject( $result, 'unsupported_operator' );
        }

        $wanted = array_map(
            static function ( $item ) {
                return is_scalar( $item ) ? (string) $item : '';
            },
            is_array( $value ) ? $value : [ $value ]
        );

        $actual = array_map(
            static function ( $item ) {
                return (string) $item;
            },
            $actual
        );

        $intersects = [] !== array_intersect( $wanted, $actual );

        $result['matched'] = 'in' === $operator ? $intersects : ! $intersects;
        $result['reason']  = $result['matched'] ? 'matched' : 'no_overlap';

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param mixed                $value
     * @return array<string, mixed>
     */
    private function apply_numeric( array $result, float $actual, string $operator, $value ): array {
        if ( ! in_array( $operator, self::NUMERIC_OPERATORS, true ) ) {
            return $this->reject( $result, 'unsupported_operator' );
        }

        if ( ! is_numeric( $value ) ) {
            return $this->reject( $result, 'non_numeric_value' );
        }

        $expected = (float) $value;

        switch ( $operator ) {
            case 'eq':
                $matched = abs( $actual - $expected ) < 0.00001;
                break;
            case 'neq':
                $matched = abs( $actual - $expected ) >= 0.00001;
                break;
            case 'gt':
                $matched = $actual > $expected;
                break;
            case 'gte':
                $matched = $actual >= $expected;
                break;
            case 'lt':
                $matched = $actual < $expected;
                break;
            default: // lte
                $matched = $actual <= $expected;
                break;
        }

        $result['matched'] = $matched;
        $result['reason']  = $matched ? 'matched' : 'out_of_range';
        $result['actual']  = $actual;

        return $result;
    }

    /**
     * Fail a condition and say why.
     *
     * Logged rather than thrown: one malformed condition should withhold that
     * campaign's points, not break the award of every other campaign.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function reject( array $result, string $reason ): array {
        $result['matched'] = false;
        $result['reason']  = $reason;

        // Debug, not warning: this runs once per condition per award, so a
        // single misconfigured campaign on a busy store would otherwise write a
        // line per order. The preview endpoint is the surface that surfaces it.
        Log::debug(
            'ConditionsEvaluator',
            sprintf(
                '%s (type="%s", operator="%s"). Condition treated as NOT matching.',
                $reason,
                $result['type'],
                $result['operator']
            )
        );

        return $result;
    }

    /* ─── Customer facts ────────────────────────────────────────────── */

    /** @return string[] */
    private function user_roles( int $user_id ): array {
        $user = get_userdata( $user_id );

        return $user ? array_values( (array) $user->roles ) : [];
    }

    private function lifetime_spend( int $user_id ): float {
        return function_exists( 'wc_get_customer_total_spent' )
            ? (float) wc_get_customer_total_spent( $user_id )
            : 0.0;
    }

    private function order_count( int $user_id ): int {
        return function_exists( 'wc_get_customer_order_count' )
            ? (int) wc_get_customer_order_count( $user_id )
            : 0;
    }

    /**
     * Product ids this customer has bought.
     *
     * Variations report their parent product, because a merchant targeting a
     * product means the product, not one of its sizes.
     *
     * @return int[]
     */
    private function purchased_product_ids( int $user_id ): array {
        if ( isset( $this->purchased_products[ $user_id ] ) ) {
            return $this->purchased_products[ $user_id ];
        }

        $ids = [];

        if ( function_exists( 'wc_get_orders' ) ) {
            $orders = wc_get_orders(
                [
                    'customer_id' => $user_id,
                    'status'      => self::PAID_STATUSES,
                    'limit'       => self::ORDER_SCAN_LIMIT,
                    'orderby'     => 'date',
                    'order'       => 'DESC',
                ]
            );

            foreach ( $orders as $order ) {
                foreach ( $order->get_items() as $item ) {
                    $product_id = (int) $item->get_product_id();

                    if ( $product_id > 0 ) {
                        $ids[ $product_id ] = $product_id;
                    }
                }
            }
        }

        $this->purchased_products[ $user_id ] = array_values( $ids );

        return $this->purchased_products[ $user_id ];
    }

    /**
     * Category ids the customer has bought from.
     *
     * @return int[]
     */
    private function purchased_category_ids( int $user_id ): array {
        if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
            return [];
        }

        $categories = [];

        foreach ( $this->purchased_product_ids( $user_id ) as $product_id ) {
            foreach ( wc_get_product_term_ids( $product_id, 'product_cat' ) as $term_id ) {
                $categories[ (int) $term_id ] = (int) $term_id;
            }
        }

        return array_values( $categories );
    }
}
