<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\OfferLines;
use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\CampaignCondition;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Words a campaign's or reward's conditions as the clause of a storefront teaser: "for VIP members and once
 * you've earned 500 Points". Only conditions a shopper can act on, stated positively, are worded; one that
 * isn't (a customer list, a `not_in`, a range) leaves the whole clause null, so a teaser never tells half the rule.
 */
final class ConditionPhrases {

    /** Most product or category names listed before "and N more". */
    private const MAX_NAMES = 3;

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( OfferLines::PHRASE_FILTER, [ $this, 'phrase' ], 10, 2 );
    }

    /**
     * @param string|null $phrase     A clause another subscriber already wrote; kept.
     * @param array       $conditions Flat list of {type, operator, value}.
     */
    public function phrase( ?string $phrase, array $conditions ): ?string {
        if ( is_string( $phrase ) || [] === $conditions ) {
            return $phrase;
        }

        $clauses = [];

        foreach ( $conditions as $data ) {
            $clause = is_array( $data ) ? $this->clause( CampaignCondition::from_array( $data ) ) : null;

            if ( null === $clause ) {
                return null;
            }

            $clauses[] = $clause;
        }

        /* translators: joins two conditions of one offer, as "for VIP members and when you pay with PayPal". */
        return implode( ' ' . __( 'and', 'yayloyalty' ) . ' ', $clauses );
    }

    private function clause( CampaignCondition $condition ): ?string {
        $operator = $condition->get_operator();
        $value    = $condition->get_value();

        switch ( $condition->get_type() ) {
            case CampaignConditionType::USER_ROLE:
                return $this->list_clause(
                    $operator,
                    $value,
                    [ $this, 'role_name' ],
                    /* translators: %s: role names, as "VIP and Wholesale". */
                    __( 'for %s members', 'yayloyalty' )
                );

            case CampaignConditionType::LIFETIME_POINTS:
                return CampaignConditionType::OP_GTE === $operator && is_numeric( $value )
                    /* translators: %s: points with the store's label, as "500 Points". */
                    ? sprintf( __( "once you've earned %s", 'yayloyalty' ), SettingsHelper::points_text( (int) $value ) )
                    : null;

            case CampaignConditionType::ORDER_COUNT:
                return CampaignConditionType::OP_GTE === $operator && is_numeric( $value )
                    /* translators: %s: number of orders. */
                    ? sprintf( _n( 'after %s order', 'after %s orders', (int) $value, 'yayloyalty' ), SettingsHelper::format_number( (int) $value ) )
                    : null;

            case CampaignConditionType::LIFETIME_SPEND:
                return CampaignConditionType::OP_GTE === $operator && is_array( $value ) && isset( $value['amount'], $value['currency'] ) && is_numeric( $value['amount'] )
                    /* translators: %s: an amount of money, as "$500". */
                    ? sprintf( __( "once you've spent %s", 'yayloyalty' ), LauncherCopy::money( (float) $value['amount'], (string) $value['currency'] ) )
                    : null;

            case CampaignConditionType::CURRENCY:
                return $this->list_clause(
                    $operator,
                    $value,
                    'strtoupper',
                    /* translators: %s: currency codes, as "USD and EUR". */
                    __( 'when you pay in %s', 'yayloyalty' )
                );

            case CampaignConditionType::PAYMENT_METHOD:
                return $this->list_clause(
                    $operator,
                    $value,
                    [ $this, 'gateway_name' ],
                    /* translators: %s: payment method names, as "PayPal or Cash on delivery". */
                    __( 'when you pay with %s', 'yayloyalty' )
                );

            case CampaignConditionType::COUNTRY:
                return $this->country_clause( $operator, $value );

            case CampaignConditionType::PRODUCT_IN_CART:
                return $this->list_clause(
                    $operator,
                    $value,
                    [ $this, 'product_name' ],
                    /* translators: %s: product names, as "Hoodie and Cap". */
                    __( 'when your order includes %s', 'yayloyalty' ),
                    true
                );

            case CampaignConditionType::CATEGORY_IN_CART:
                return $this->list_clause(
                    $operator,
                    $value,
                    [ $this, 'term_name' ],
                    /* translators: %s: product category names, as "Shoes and Bags". */
                    __( 'when your order includes %s', 'yayloyalty' ),
                    true
                );
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function list_clause( string $operator, $value, callable $name, string $format, bool $truncate = false ): ?string {
        if ( CampaignConditionType::OP_IN !== $operator ) {
            return null;
        }

        $names = $this->names( $value, $name, $truncate );

        return null === $names ? null : sprintf( $format, $names );
    }

    /**
     * @param mixed $value {countries, source}.
     */
    private function country_clause( string $operator, $value ): ?string {
        if ( CampaignConditionType::OP_IN !== $operator || ! is_array( $value ) ) {
            return null;
        }

        $names = $this->names( $value['countries'] ?? [], [ $this, 'country_name' ] );

        if ( null === $names ) {
            return null;
        }

        switch ( $value['source'] ?? '' ) {
            case CampaignConditionType::SOURCE_SHIPPING:
                /* translators: %s: country names, as "Vietnam and Thailand". */
                return sprintf( __( 'for orders shipped to %s', 'yayloyalty' ), $names );
            case CampaignConditionType::SOURCE_BILLING:
                /* translators: %s: country names, as "Vietnam and Thailand". */
                return sprintf( __( 'for orders billed to %s', 'yayloyalty' ), $names );
            default:
                /* translators: %s: country names, as "Vietnam and Thailand". */
                return sprintf( __( 'for orders from %s', 'yayloyalty' ), $names );
        }
    }

    /**
     * The value's items named and joined, "A, B and C"; null for an empty list. With $truncate, the first
     * MAX_NAMES then "and N more".
     *
     * @param mixed $value
     */
    private function names( $value, callable $name, bool $truncate = false ): ?string {
        $items = array_values( array_filter( array_map( 'strval', is_array( $value ) ? $value : [ $value ] ), 'strlen' ) );

        if ( [] === $items ) {
            return null;
        }

        $more  = $truncate ? max( 0, count( $items ) - self::MAX_NAMES ) : 0;
        $names = array_map( $name, $more > 0 ? array_slice( $items, 0, self::MAX_NAMES ) : $items );

        if ( $more > 0 ) {
            /* translators: %s: how many more names are left out of the list. */
            $names[] = sprintf( _n( '%s more', '%s more', $more, 'yayloyalty' ), SettingsHelper::format_number( $more ) );
        }

        return wp_sprintf_l( '%l', $names );
    }

    private function role_name( string $slug ): string {
        $names = wp_roles()->get_names();

        return isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : $slug;
    }

    private function gateway_name( string $id ): string {
        $gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];

        return isset( $gateways[ $id ] ) ? Text::plain( $gateways[ $id ]->get_title() ) : $id;
    }

    private function country_name( string $code ): string {
        $countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
        $code      = strtoupper( $code );

        return isset( $countries[ $code ] ) ? Text::plain( $countries[ $code ] ) : $code;
    }

    private function product_name( string $id ): string {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;

        return $product ? Text::plain( $product->get_name() ) : $id;
    }

    private function term_name( string $id ): string {
        $term = get_term( (int) $id, 'product_cat' );

        return $term instanceof \WP_Term ? Text::plain( $term->name ) : $id;
    }
}
