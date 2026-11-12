<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Design field declaration: defaults and the group/field/rule whitelist DesignHelper::apply_schema() coerces
 * against.
 */
class DesignSchema {

    public const SCOPE_ALL_PAGES        = 'all_pages';
    public const SCOPE_SHOP_AND_PRODUCT = 'shop_and_product';
    public const SCOPE_CUSTOM_PAGES     = 'custom_pages';

    public static function get_default_design(): array {
        return [
            'launcher'        => [
                'brand_color'   => '#3858e9',
                'corner_radius' => 8,
            ],
            'visibility'      => [
                'scope'                  => self::SCOPE_ALL_PAGES,
                'include_page_ids'       => [],
                'hide_on_mobile'         => false,
                'hide_for_roles'         => [],
                'show_product_message'   => true,
                'show_thankyou_message'  => true,
                'show_bonus_offers'      => true, // Pro: conditional campaigns teased under Ways to earn.
                'show_cart_earn_preview' => true, // The cart and checkout row with the points the order will earn.
            ],
            // {points} and {points_label} are filled in when the line is shown (LauncherCopy::points_sentence_html()).
            'storefront_text' => [
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                'cart_earn_line' => __( "You'll earn {points} {points_label} with this order", 'yayloyalty' ),
            ],
            'panel'           => [ // Pro
                'layout'              => 'compact',
                'show_reward_catalog' => false,
            ],
            'custom_css'      => [ // Pro
                // Stored and sanitised, but rendered nowhere yet.
                // TODO(custom-css): close the CSS exfiltration risk before the storefront renders this field.
                'custom_css' => '',
            ],
        ];
    }

    /**
     * Every field's rule: its type plus any limits. Anything absent is dropped in both directions.
     * Declares every field in both builds so a Lite downgrade never wipes a merchant's stored Pro design.
     * DesignCoercion::coerce() documents each type and its default limits.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function schema(): array {
        return [
            'launcher'        => [
                'brand_color'   => [ 'type' => 'color' ],
                'corner_radius' => [
                    'type' => 'int',
                    'min'  => 0,
                    'max'  => 24,
                ],
            ],
            'visibility'      => [
                'scope'                  => [
                    'type'    => 'choice',
                    'options' => [ self::SCOPE_ALL_PAGES, self::SCOPE_SHOP_AND_PRODUCT, self::SCOPE_CUSTOM_PAGES ],
                ],
                // Read only when scope is custom_pages: the pages the launcher shows on.
                'include_page_ids'       => [ 'type' => 'id_list' ],
                'hide_on_mobile'         => [ 'type' => 'bool' ],
                'hide_for_roles'         => [ 'type' => 'slug_list' ],
                'show_product_message'   => [ 'type' => 'bool' ],
                'show_thankyou_message'  => [ 'type' => 'bool' ],
                'show_bonus_offers'      => [ 'type' => 'bool' ],
                'show_cart_earn_preview' => [ 'type' => 'bool' ],
            ],
            'storefront_text' => [
                'cart_earn_line' => [
                    'type'     => 'text',
                    'required' => true,
                ],
            ],
            'panel'           => [ // Pro
                'layout'              => [
                    'type'    => 'choice',
                    'options' => [ 'compact', 'full' ],
                ],
                'show_reward_catalog' => [ 'type' => 'bool' ],
            ],
            'custom_css'      => [ // Pro
                'custom_css' => [ 'type' => 'textarea' ],
            ],
        ];
    }
}
