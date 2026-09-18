<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Models\CampaignCondition;
use YayLoyalty\Models\CampaignConditionSet;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a campaign's `conditions` (a flat ANDed list) against a customer.
 * Attached to Lite through the yayloyalty_campaign_conditions_evaluator seam.
 *
 * Unknowns FAIL here, while Lite (no evaluator) lets them match: Pro can
 * evaluate, so an unrecognised type is a real error. Do not harmonise them.
 * Only customer facts are evaluable: order-scoped conditions (currency,
 * country, payment_method) are unsupported until the seam passes the order,
 * and product/category_in_cart use purchase history as an interim.
 */
final class ConditionsEvaluator {

    /** The customer's fact and the condition value are both lists. */
    private const SET_OPERATORS = [ CampaignConditionType::OP_IN, CampaignConditionType::OP_NOT_IN ];

    /** The customer's fact is a number. */
    private const NUMERIC_OPERATORS = [
        CampaignConditionType::OP_EQ,
        CampaignConditionType::OP_GT,
        CampaignConditionType::OP_GTE,
        CampaignConditionType::OP_LT,
        CampaignConditionType::OP_LTE,
        CampaignConditionType::OP_BETWEEN,
    ];

    /** Statuses that count as a purchase; processing counts because the money has changed hands. */
    private const PAID_STATUSES = [ 'wc-processing', 'wc-completed' ];

    /** Newest orders scanned for purchase-history facts; evaluation is on the award hot path. */
    private const ORDER_SCAN_LIMIT = 100;

    /** @var array<int, \WC_Order[]> Memoised so several purchase-history conditions scan once. */
    private $orders_by_user = [];

    /** @var array<int, int[]> */
    private $purchased_products = [];

    /** @var array<string, float> Keyed by "user_id:CURRENCY". */
    private $spend_by_currency = [];

    /** @var array<int, int> */
    private $points_by_user = [];

    /**
     * Whether every condition matches.
     *
     * @param array $conditions Flat list of {type, operator, value} objects.
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
     * Per-condition results for the preview endpoint. evaluate() folds over
     * this, so the preview cannot disagree with award time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function explain( array $conditions, int $user_id ): array {
        $results = [];

        foreach ( CampaignConditionSet::from_array( $conditions )->all() as $condition ) {
            $results[] = $this->evaluate_one( $condition, $user_id );
        }

        return $results;
    }

    /**
     * @return array<string, mixed> {type, operator, value, matched, reason}
     */
    private function evaluate_one( CampaignCondition $condition, int $user_id ): array {
        $type     = $condition->get_type();
        $operator = $condition->get_operator();
        $value    = $condition->get_value();

        $result = [
            'type'     => $type,
            'operator' => $operator,
            'value'    => $value,
            'matched'  => false,
            'reason'   => '',
        ];

        switch ( $type ) {
            case CampaignConditionType::USER_ROLE:
                return $this->apply_set( $result, $this->user_roles( $user_id ), $operator, $value );

            case CampaignConditionType::CUSTOMER_IDS:
                return $this->apply_set( $result, [ (string) $user_id ], $operator, $value );

            case CampaignConditionType::PRODUCT_IN_CART:
                return $this->apply_set( $result, $this->purchased_product_ids( $user_id ), $operator, $value );

            case CampaignConditionType::CATEGORY_IN_CART:
                return $this->apply_set( $result, $this->purchased_category_ids( $user_id ), $operator, $value );

            case CampaignConditionType::LIFETIME_POINTS:
                return $this->apply_numeric( $result, (float) $this->lifetime_points( $user_id ), $operator, $value );

            case CampaignConditionType::ORDER_COUNT:
                return $this->apply_numeric( $result, (float) $this->order_count( $user_id ), $operator, $value );

            case CampaignConditionType::LIFETIME_SPEND:
                return $this->evaluate_lifetime_spend( $result, $user_id, $operator, $value );
        }

        // Known but not yet evaluable (needs order context or tiers), distinct from a typo.
        return $this->reject(
            $result,
            CampaignConditionType::is_known( $type ) ? 'unsupported_condition_type' : 'unknown_condition_type'
        );
    }

    /**
     * Value is { amount, currency }; only orders in that currency count, no conversion.
     *
     * @param array<string, mixed> $result
     * @param mixed                $value
     * @return array<string, mixed>
     */
    private function evaluate_lifetime_spend( array $result, int $user_id, string $operator, $value ): array {
        if ( ! is_array( $value ) || ! isset( $value['currency'] ) ) {
            return $this->reject( $result, 'malformed_value' );
        }

        $currency = strtoupper( (string) $value['currency'] );
        $actual   = $this->spend_in_currency( $user_id, $currency );

        return $this->apply_numeric( $result, $actual, $operator, $value['amount'] ?? null );
    }

    /**
     * Compares a list-valued fact against a list-valued condition; `in` means ANY.
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

        $result['matched'] = CampaignConditionType::OP_IN === $operator ? $intersects : ! $intersects;
        $result['reason']  = $result['matched'] ? 'matched' : 'no_overlap';

        return $result;
    }

    /**
     * Compares a numeric fact against the condition value: a single number, or an inclusive [low, high] for `between`.
     *
     * @param array<string, mixed> $result
     * @param mixed                $value
     * @return array<string, mixed>
     */
    private function apply_numeric( array $result, float $actual, string $operator, $value ): array {
        if ( ! in_array( $operator, self::NUMERIC_OPERATORS, true ) ) {
            return $this->reject( $result, 'unsupported_operator' );
        }

        if ( CampaignConditionType::OP_BETWEEN === $operator ) {
            if ( ! is_array( $value ) || ! isset( $value[0], $value[1] )
                || ! is_numeric( $value[0] ) || ! is_numeric( $value[1] ) ) {
                return $this->reject( $result, 'non_numeric_value' );
            }

            $matched = $actual >= (float) $value[0] && $actual <= (float) $value[1];

            $result['matched'] = $matched;
            $result['reason']  = $matched ? 'matched' : 'out_of_range';
            $result['actual']  = $actual;

            return $result;
        }

        if ( ! is_numeric( $value ) ) {
            return $this->reject( $result, 'non_numeric_value' );
        }

        $expected = (float) $value;

        switch ( $operator ) {
            case CampaignConditionType::OP_EQ:
                $matched = abs( $actual - $expected ) < 0.00001;
                break;
            case CampaignConditionType::OP_GT:
                $matched = $actual > $expected;
                break;
            case CampaignConditionType::OP_GTE:
                $matched = $actual >= $expected;
                break;
            case CampaignConditionType::OP_LT:
                $matched = $actual < $expected;
                break;
            default: // OP_LTE
                $matched = $actual <= $expected;
                break;
        }

        $result['matched'] = $matched;
        $result['reason']  = $matched ? 'matched' : 'out_of_range';
        $result['actual']  = $actual;

        return $result;
    }

    /**
     * Fails a condition with a reason. Logged, not thrown: one bad condition must not break every other campaign's award.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function reject( array $result, string $reason ): array {
        $result['matched'] = false;
        $result['reason']  = $reason;

        // Debug, not warning: one misconfigured campaign would otherwise log a line per order.
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

    /** @return string[] */
    private function user_roles( int $user_id ): array {
        $user = get_userdata( $user_id );

        return $user ? array_values( (array) $user->roles ) : [];
    }

    private function lifetime_points( int $user_id ): int {
        if ( isset( $this->points_by_user[ $user_id ] ) ) {
            return $this->points_by_user[ $user_id ];
        }

        $customer = ( new CustomerRepository() )->find_by_user_id( $user_id );

        // lifetime_earned, not balance: spending must not drop a customer below a threshold.
        $this->points_by_user[ $user_id ] = null === $customer ? 0 : $customer->get_lifetime_earned();

        return $this->points_by_user[ $user_id ];
    }

    private function order_count( int $user_id ): int {
        return function_exists( 'wc_get_customer_order_count' )
            ? (int) wc_get_customer_order_count( $user_id )
            : 0;
    }

    /** The customer's total in one currency over the scanned paid orders; no conversion. */
    private function spend_in_currency( int $user_id, string $currency ): float {
        $key = $user_id . ':' . $currency;

        if ( isset( $this->spend_by_currency[ $key ] ) ) {
            return $this->spend_by_currency[ $key ];
        }

        $total = 0.0;

        foreach ( $this->paid_orders( $user_id ) as $order ) {
            if ( strtoupper( (string) $order->get_currency() ) === $currency ) {
                $total += (float) $order->get_total();
            }
        }

        $this->spend_by_currency[ $key ] = $total;

        return $total;
    }

    /**
     * The customer's most recent paid orders, up to ORDER_SCAN_LIMIT.
     *
     * @return \WC_Order[]
     */
    private function paid_orders( int $user_id ): array {
        if ( isset( $this->orders_by_user[ $user_id ] ) ) {
            return $this->orders_by_user[ $user_id ];
        }

        $orders = [];

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
        }

        $this->orders_by_user[ $user_id ] = is_array( $orders ) ? $orders : [];

        return $this->orders_by_user[ $user_id ];
    }

    /**
     * Product ids the customer has bought; variations report their parent.
     * Interim for product_in_cart until the seam passes the order.
     *
     * @return int[]
     */
    private function purchased_product_ids( int $user_id ): array {
        if ( isset( $this->purchased_products[ $user_id ] ) ) {
            return $this->purchased_products[ $user_id ];
        }

        $ids = [];

        foreach ( $this->paid_orders( $user_id ) as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_id = (int) $item->get_product_id();

                if ( $product_id > 0 ) {
                    $ids[ $product_id ] = $product_id;
                }
            }
        }

        $this->purchased_products[ $user_id ] = array_values( $ids );

        return $this->purchased_products[ $user_id ];
    }

    /**
     * Category ids the customer has bought from. Same interim caveat as purchased_product_ids().
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
