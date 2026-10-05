<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Models\CampaignCondition;
use YayLoyalty\Models\CampaignConditionSet;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a campaign's `conditions`, a flat ANDed list, against values already on the Context.
 * Queries nothing. Attached through PointsEngine::CONDITIONS_EVALUATOR_FILTER.
 *
 * Unknown conditions fail here, while Lite, with no evaluator, lets them match: Pro can evaluate,
 * so an unrecognised type is a real error. Do not harmonise them. A condition whose input is missing
 * fails too: `no_customer` for a guest, `needs_order` when the Context has no order (the admin preview).
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

    /**
     * Row reasons for a missing input. This evaluator's own vocabulary, like
     * `no_overlap`: `no_customer` shares a spelling with ProgramGate's reason
     * but is a separate code.
     */
    private const REASON_NO_CUSTOMER = 'no_customer';
    private const REASON_NEEDS_ORDER = 'needs_order';

    /**
     * Whether every condition matches the event.
     *
     * @param array $conditions Flat list of {type, operator, value} objects.
     */
    public function evaluate( array $conditions, Context $ctx ): bool {
        foreach ( $this->explain( $conditions, $ctx ) as $result ) {
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
    public function explain( array $conditions, Context $ctx ): array {
        $results = [];

        foreach ( CampaignConditionSet::from_array( $conditions )->all() as $condition ) {
            $results[] = $this->evaluate_one( $condition, $ctx );
        }

        return $results;
    }

    /**
     * @return array<string, mixed> {type, operator, value, matched, reason}
     */
    private function evaluate_one( CampaignCondition $condition, Context $ctx ): array {
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

        $missing = $this->missing_input( $type, $ctx );

        // Not reject(): a guest order or a customer-only preview is normal, not a misconfiguration worth a log line.
        if ( null !== $missing ) {
            $result['reason'] = $missing;

            return $result;
        }

        switch ( $type ) {
            case CampaignConditionType::USER_ROLE:
                return $this->apply_set( $result, $ctx->get_user_roles(), $operator, $value );

            case CampaignConditionType::CUSTOMER_IDS:
                return $this->apply_set( $result, [ (string) $ctx->get_user_id() ], $operator, $value );

            case CampaignConditionType::CURRENCY:
                return $this->apply_set( $result, [ $ctx->get_currency() ], $operator, $value );

            case CampaignConditionType::PAYMENT_METHOD:
                return $this->apply_set( $result, [ (string) $ctx->get_extra( Context::EXTRA_PAYMENT_METHOD, '' ) ], $operator, $value );

            case CampaignConditionType::COUNTRY:
                return $this->evaluate_country( $result, $ctx, $operator, $value );

            case CampaignConditionType::PRODUCT_IN_CART:
                return $this->apply_set( $result, $this->product_ids( $ctx ), $operator, $value );

            case CampaignConditionType::CATEGORY_IN_CART:
                return $this->apply_set( $result, $this->category_ids( $ctx ), $operator, $value );

            case CampaignConditionType::LIFETIME_POINTS:
                return $this->apply_numeric( $result, (float) $ctx->get_customer_facts()->get_lifetime_points(), $operator, $value );

            case CampaignConditionType::ORDER_COUNT:
                return $this->apply_numeric( $result, (float) $ctx->get_customer_facts()->get_order_count(), $operator, $value );

            case CampaignConditionType::LIFETIME_SPEND:
                return $this->evaluate_lifetime_spend( $result, $ctx->get_customer_facts(), $operator, $value );
        }

        // Known but not yet evaluable (tiers), distinct from a typo.
        return $this->reject(
            $result,
            CampaignConditionType::is_known( $type ) ? 'unsupported_condition_type' : 'unknown_condition_type'
        );
    }

    /** The reason a condition of $type cannot be evaluated on this Context, or null when it can. */
    private function missing_input( string $type, Context $ctx ): ?string {
        switch ( CampaignConditionType::input_for( $type ) ) {
            case CampaignConditionType::INPUT_CUSTOMER:
                return null === $ctx->get_user_id() ? self::REASON_NO_CUSTOMER : null;
            case CampaignConditionType::INPUT_FACTS:
                return null === $ctx->get_customer_facts() ? self::REASON_NO_CUSTOMER : null;
            case CampaignConditionType::INPUT_ORDER:
                return null === $ctx->get_order_id() ? self::REASON_NEEDS_ORDER : null;
        }

        return null;
    }

    /**
     * Value is { countries, source }; source picks billing, shipping or either.
     * An empty address (shipping on a virtual order) contributes nothing.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function evaluate_country( array $result, Context $ctx, string $operator, $value ): array {
        if ( ! is_array( $value ) ) {
            return $this->reject( $result, 'malformed_value' );
        }

        $billing  = (string) $ctx->get_extra( Context::EXTRA_BILLING_COUNTRY, '' );
        $shipping = (string) $ctx->get_extra( Context::EXTRA_SHIPPING_COUNTRY, '' );

        switch ( $value['source'] ?? '' ) {
            case CampaignConditionType::SOURCE_BILLING:
                $actual = [ $billing ];
                break;
            case CampaignConditionType::SOURCE_SHIPPING:
                $actual = [ $shipping ];
                break;
            default:
                $actual = [ $billing, $shipping ];
        }

        return $this->apply_set( $result, array_filter( $actual ), $operator, $value['countries'] ?? [] );
    }

    /**
     * Value is { amount, currency }; only spend in that currency counts, no conversion.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function evaluate_lifetime_spend( array $result, CustomerFacts $facts, string $operator, $value ): array {
        if ( ! is_array( $value ) || ! isset( $value['currency'] ) ) {
            return $this->reject( $result, 'malformed_value' );
        }

        $actual = $facts->get_spend_in( strtoupper( (string) $value['currency'] ) );

        return $this->apply_numeric( $result, $actual, $operator, $value['amount'] ?? null );
    }

    /**
     * Parents: a variation line reports its parent product id.
     *
     * @return int[]
     */
    private function product_ids( Context $ctx ): array {
        $ids = [];

        foreach ( $ctx->get_line_items() as $line ) {
            $ids[] = $line->get_product_id();
        }

        return $ids;
    }

    /** @return int[] */
    private function category_ids( Context $ctx ): array {
        $ids = [];

        foreach ( $ctx->get_line_items() as $line ) {
            $ids = array_merge( $ids, $line->get_category_ids() );
        }

        return $ids;
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
        } elseif ( ! is_numeric( $value ) ) {
            return $this->reject( $result, 'non_numeric_value' );
        } else {
            $matched = $this->compare( $actual, $operator, (float) $value );
        }

        $result['matched'] = $matched;
        $result['reason']  = $matched ? 'matched' : 'out_of_range';
        $result['actual']  = $actual;

        return $result;
    }

    /** One-sided numeric comparison; $operator is a NUMERIC_OPERATORS entry other than between. */
    private function compare( float $actual, string $operator, float $expected ): bool {
        switch ( $operator ) {
            case CampaignConditionType::OP_EQ:
                return abs( $actual - $expected ) < 0.00001;
            case CampaignConditionType::OP_GT:
                return $actual > $expected;
            case CampaignConditionType::OP_GTE:
                return $actual >= $expected;
            case CampaignConditionType::OP_LT:
                return $actual < $expected;
            default: // OP_LTE
                return $actual <= $expected;
        }
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
}
