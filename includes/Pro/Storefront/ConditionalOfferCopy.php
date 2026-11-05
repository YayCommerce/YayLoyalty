<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\CampaignCondition;
use YayLoyalty\Models\CampaignConditionSet;

defined( 'ABSPATH' ) || exit;

/**
 * A conditional campaign in words, for shoppers. Describes only what it can say truly and publicly: a campaign
 * holding any other condition has no words, so it is never advertised. That keeps out customer lists and roles,
 * which name internal programs, and conditions on what a shopper must not have, which read poorly as an offer.
 */
final class ConditionalOfferCopy {

    /** Names a clause lists before "and more". */
    private const MAX_NAMES = 3;

    /** "Orders with Shoes, after 5 orders", or null when the campaign can't be described. */
    public static function label( Campaign $campaign ): ?string {
        $noun    = self::noun( $campaign->get_trigger() );
        $clauses = [];

        foreach ( self::conditions( $campaign ) as $condition ) {
            $clause = self::clause( $condition, $campaign->get_trigger() );

            if ( null === $clause ) {
                return null;
            }

            $clauses[] = $clause;
        }

        if ( null === $noun || [] === $clauses ) {
            return null;
        }

        /* translators: 1: "Orders" or "Reviews", 2: what they need, as "with Shoes, after 5 orders". */
        return sprintf( _x( '%1$s %2$s', 'bonus offer', 'yayloyalty' ), $noun, implode( ', ', $clauses ) );
    }

    /** The categories or products a campaign asks the cart to hold, as "Shoes, Bags"; null when it asks for none. */
    public static function basket_names( Campaign $campaign ): ?string {
        foreach ( self::conditions( $campaign ) as $condition ) {
            if ( CampaignConditionType::OP_IN !== $condition->get_operator() ) {
                continue;
            }

            if ( CampaignConditionType::CATEGORY_IN_CART === $condition->get_type() ) {
                return self::names( array_map( [ self::class, 'category_name' ], (array) $condition->get_value() ) );
            }

            if ( CampaignConditionType::PRODUCT_IN_CART === $condition->get_type() ) {
                return self::names( array_map( [ self::class, 'product_name' ], (array) $condition->get_value() ) );
            }
        }

        return null;
    }

    /** @return CampaignCondition[] */
    private static function conditions( Campaign $campaign ): array {
        return CampaignConditionSet::from_array( $campaign->get_conditions() )->all();
    }

    private static function noun( string $trigger ): ?string {
        switch ( $trigger ) {
            case CampaignTrigger::PURCHASE:
                return __( 'Orders', 'yayloyalty' );
            case CampaignTrigger::REVIEW:
                return __( 'Reviews', 'yayloyalty' );
        }

        return null;
    }

    private static function clause( CampaignCondition $condition, string $trigger ): ?string {
        $type = $condition->get_type();

        if ( in_array( $type, [ CampaignConditionType::ORDER_COUNT, CampaignConditionType::LIFETIME_POINTS, CampaignConditionType::LIFETIME_SPEND ], true ) ) {
            return self::history_clause( $condition );
        }

        // A review has no order, so a condition on one never pays it.
        if ( CampaignTrigger::PURCHASE !== $trigger || CampaignConditionType::OP_IN !== $condition->get_operator() ) {
            return null;
        }

        return self::order_clause( $type, $condition->get_value() );
    }

    /**
     * @param string $type  A CampaignConditionType constant.
     * @param mixed  $value The condition's value.
     */
    private static function order_clause( string $type, $value ): ?string {
        switch ( $type ) {
            case CampaignConditionType::CATEGORY_IN_CART:
                $names = self::names( array_map( [ self::class, 'category_name' ], (array) $value ) );
                /* translators: %s: category or product names, as "Shoes, Bags". */
                $format = __( 'with %s', 'yayloyalty' );
                break;
            case CampaignConditionType::PRODUCT_IN_CART:
                $names = self::names( array_map( [ self::class, 'product_name' ], (array) $value ) );
                /* translators: %s: category or product names, as "Shoes, Bags". */
                $format = __( 'with %s', 'yayloyalty' );
                break;
            case CampaignConditionType::COUNTRY:
                $names = self::names( array_map( [ self::class, 'country_name' ], (array) ( $value['countries'] ?? [] ) ) );
                /* translators: %s: country names, as "Vietnam, Japan". */
                $format = __( 'in %s', 'yayloyalty' );
                break;
            case CampaignConditionType::CURRENCY:
                $names = self::names( array_map( 'strval', (array) $value ) );
                /* translators: %s: currency codes, as "EUR, USD". */
                $format = __( 'paid in %s', 'yayloyalty' );
                break;
            case CampaignConditionType::PAYMENT_METHOD:
                $names = self::names( array_map( [ self::class, 'gateway_name' ], (array) $value ) );
                /* translators: %s: payment method names, as "Stripe". */
                $format = __( 'paid with %s', 'yayloyalty' );
                break;
            default:
                return null;
        }

        return null === $names ? null : sprintf( $format, $names );
    }

    /** A threshold the shopper can still reach; anything else is no offer. */
    private static function history_clause( CampaignCondition $condition ): ?string {
        $operator = $condition->get_operator();

        if ( ! in_array( $operator, [ CampaignConditionType::OP_GTE, CampaignConditionType::OP_GT ], true ) ) {
            return null;
        }

        if ( CampaignConditionType::LIFETIME_SPEND === $condition->get_type() ) {
            return self::spend_clause( $condition->get_value(), $operator );
        }

        $threshold = (int) $condition->get_value() + ( CampaignConditionType::OP_GT === $operator ? 1 : 0 );

        if ( $threshold < 1 ) {
            return null;
        }

        if ( CampaignConditionType::ORDER_COUNT === $condition->get_type() ) {
            /* translators: %s: a number of orders, as "5". */
            return sprintf( _n( 'after %s order', 'after %s orders', $threshold, 'yayloyalty' ), SettingsHelper::format_number( $threshold ) );
        }

        /* translators: %s: points with the store's label, as "500 Points". */
        return sprintf( __( 'after earning %s', 'yayloyalty' ), SettingsHelper::points_text( $threshold ) );
    }

    /**
     * Only in the shopper's currency: the condition counts spend in one currency, with no conversion.
     *
     * @param mixed  $value    { amount, currency }.
     * @param string $operator OP_GTE or OP_GT.
     */
    private static function spend_clause( $value, string $operator ): ?string {
        if ( ! is_array( $value ) || strtoupper( (string) ( $value['currency'] ?? '' ) ) !== get_woocommerce_currency() ) {
            return null;
        }

        $amount = (float) ( $value['amount'] ?? 0 );

        if ( $amount <= 0 ) {
            return null;
        }

        return CampaignConditionType::OP_GT === $operator
            /* translators: %s: money amount, as "$100". */
            ? sprintf( __( 'after spending more than %s', 'yayloyalty' ), LauncherCopy::money( $amount ) )
            /* translators: %s: money amount, as "$100". */
            : sprintf( __( 'after spending %s', 'yayloyalty' ), LauncherCopy::money( $amount ) );
    }

    /**
     * "Shoes, Bags, Hats and more"; null when there are none, or one has no public name.
     *
     * @param array<int, string|null> $names
     */
    private static function names( array $names ): ?string {
        if ( [] === $names || in_array( null, $names, true ) ) {
            return null;
        }

        $list = implode( ', ', array_slice( $names, 0, self::MAX_NAMES ) );

        /* translators: %s: names, as "Shoes, Bags, Hats". */
        return count( $names ) > self::MAX_NAMES ? sprintf( __( '%s and more', 'yayloyalty' ), $list ) : $list;
    }

    /** @param mixed $id A category id from a condition. */
    private static function category_name( $id ): ?string {
        $term = get_term( (int) $id, 'product_cat' );

        return $term instanceof \WP_Term ? $term->name : null;
    }

    /** @param mixed $id A product id from a condition. */
    private static function product_name( $id ): ?string {
        $product = wc_get_product( (int) $id );

        return $product && 'publish' === $product->get_status() ? $product->get_name() : null;
    }

    /** @param mixed $code A country code from a condition. */
    private static function country_name( $code ): ?string {
        return WC()->countries->get_countries()[ (string) $code ] ?? null;
    }

    /** @param mixed $id A gateway id from a condition. */
    private static function gateway_name( $id ): ?string {
        $gateway = WC()->payment_gateways()->payment_gateways()[ (string) $id ] ?? null;

        return $gateway && 'yes' === $gateway->enabled ? $gateway->get_title() : null;
    }
}
