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
                'position'      => 'bottom_right',
                'brand_color'   => '#3858e9',
                'corner_radius' => 8,
            ],
            // Real, editable defaults. An empty save is literal, never "use the default".
            // {points_label} becomes the merchant's points label (LauncherCopy::storefront_text(); the lines with a {points} count through LauncherCopy::points_sentence()), so no default names "points".
            'storefront_text' => [
                'launcher_label'        => __( 'Rewards', 'yayloyalty' ),
                /* translators: keep {points_label} as it is; it becomes the store's points label, as "Points". */
                'balance_heading'       => __( 'Your {points_label}', 'yayloyalty' ),
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                'earn_rate_line'        => __( '{points} {points_label} / {amount}', 'yayloyalty' ),
                /* translators: keep {points_label} as it is; it becomes the store's points label, as "Points". */
                'redeem_button'         => __( 'Apply {points_label}', 'yayloyalty' ),
                /* translators: keep {points_label} as it is; it becomes the store's points label, as "Points". */
                'signed_out_prompt'     => __( 'Sign in to earn {points_label} on this order', 'yayloyalty' ),
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                'product_earn_line'     => __( 'Earn {points} {points_label} with this purchase', 'yayloyalty' ),
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                'thankyou_pending_line' => __( '+{points} {points_label} from this order', 'yayloyalty' ),
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                'thankyou_earned_line'  => __( '+{points} {points_label} from this order', 'yayloyalty' ),
            ],
            'visibility'      => [
                'scope'            => self::SCOPE_ALL_PAGES,
                'include_page_ids' => [],
                'hide_on_mobile'   => false,
                'hide_for_roles'   => [ 'administrator', 'shop_manager' ],
                'product_message'  => true,
                'thankyou_message' => true,
            ],
            'panel'           => [ // Pro
                'layout'              => 'compact',
                'show_reward_catalog' => false,
            ],
            'typography'      => [ // Pro
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
                'position'      => [
                    'type'    => 'choice',
                    'options' => [ 'bottom_right', 'bottom_left' ],
                ],
                'brand_color'   => [ 'type' => 'color' ],
                'corner_radius' => [
                    'type' => 'int',
                    'min'  => 0,
                    'max'  => 24,
                ],
            ],
            'storefront_text' => [
                'launcher_label'        => [
                    'type'     => 'text',
                    'required' => true,
                ],
                'balance_heading'       => [
                    'type'     => 'text',
                    'required' => true,
                ],
                'earn_rate_line'        => [ 'type' => 'text' ],
                'redeem_button'         => [
                    'type'     => 'text',
                    'required' => true,
                ],
                'signed_out_prompt'     => [ 'type' => 'text' ],
                'product_earn_line'     => [
                    'type'     => 'text',
                    'required' => true,
                ],
                'thankyou_pending_line' => [
                    'type'     => 'text',
                    'required' => true,
                ],
                'thankyou_earned_line'  => [
                    'type'     => 'text',
                    'required' => true,
                ],
            ],
            'visibility'      => [
                'scope'            => [
                    'type'    => 'choice',
                    'options' => [ self::SCOPE_ALL_PAGES, self::SCOPE_SHOP_AND_PRODUCT, self::SCOPE_CUSTOM_PAGES ],
                ],
                // Read only when scope is custom_pages: the pages the launcher shows on.
                'include_page_ids' => [ 'type' => 'id_list' ],
                'hide_on_mobile'   => [ 'type' => 'bool' ],
                'hide_for_roles'   => [ 'type' => 'slug_list' ],
                'product_message'  => [ 'type' => 'bool' ],
                'thankyou_message' => [ 'type' => 'bool' ],
            ],
            'panel'           => [ // Pro
                'layout'              => [
                    'type'    => 'choice',
                    'options' => [ 'compact', 'full' ],
                ],
                'show_reward_catalog' => [ 'type' => 'bool' ],
            ],
            'typography'      => [ // Pro
                'custom_css' => [ 'type' => 'textarea' ],
            ],
        ];
    }
}
