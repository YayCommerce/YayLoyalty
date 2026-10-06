<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Design field declaration: defaults and the group/field/rule whitelist DesignHelper::apply_schema() coerces
 * against. The launcher and tier badge icons are separate vocabularies: different UI elements, free to diverge.
 */
class DesignSchema {

    public const LAUNCHER_ICONS = [ 'default', 'circle', 'diamond', 'bar', 'ring', 'line', 'custom' ];
    public const BADGE_ICONS    = [ 'ring', 'diamond', 'square' ];

    public const SCOPE_ALL_PAGES        = 'all_pages';
    public const SCOPE_SHOP_AND_PRODUCT = 'shop_and_product';
    public const SCOPE_CUSTOM_PAGES     = 'custom_pages';

    public static function get_default_design(): array {
        return [
            'launcher'        => [
                'position'       => 'bottom_right',
                'button_style'   => 'both',
                'brand_color'    => '#1f6fb2',
                'corner_radius'  => 8,
                // Only 'custom' uses icon_upload_id. Every other value ignores the upload.
                'icon'           => 'default',
                'icon_upload_id' => 0,
            ],
            // Real, editable defaults. An empty save is literal, never "use the default".
            'storefront_text' => [
                'launcher_label'        => __( 'Rewards', 'yayloyalty' ),
                'balance_heading'       => __( 'Your points', 'yayloyalty' ),
                /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
                'earn_rate_line'        => __( 'Earn {points} {points_label} per {amount} spent', 'yayloyalty' ),
                'redeem_button'         => __( 'Apply points', 'yayloyalty' ),
                'signed_out_prompt'     => __( 'Sign in to earn points on this order', 'yayloyalty' ),
                'referral_card_heading' => __( 'Invite a friend, get {amount} points', 'yayloyalty' ), // Pro
                'tier_progress_line'    => __( '{points} points to {tier}', 'yayloyalty' ), // Pro
            ],
            'visibility'      => [
                'scope'            => self::SCOPE_ALL_PAGES,
                'include_page_ids' => [],
                'hide_on_mobile'   => false,
                'hide_for_roles'   => [ 'administrator', 'shop_manager' ],
            ],
            'tier'            => [ // Pro
                'badges' => [
                    [
                        'tier_id' => 'explorer',
                        'color'   => '#c8c8c8',
                        'icon'    => 'ring',
                        'label'   => __( 'Explorer', 'yayloyalty' ),
                    ],
                    [
                        'tier_id' => 'summit',
                        'color'   => '#8a8a8a',
                        'icon'    => 'diamond',
                        'label'   => __( 'Summit', 'yayloyalty' ),
                    ],
                    [
                        'tier_id' => 'alpine',
                        'color'   => '#2a2a2a',
                        'icon'    => 'square',
                        'label'   => __( 'Alpine', 'yayloyalty' ),
                    ],
                ],
            ],
            'panel'           => [ // Pro
                'layout'                     => 'compact',
                'show_referral_card'         => true,
                'show_progress_to_next_tier' => true,
                'show_reward_catalog'        => false,
            ],
            'typography'      => [ // Pro
                'mode'       => 'inherit',
                'font'       => '',
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
                'position'       => [
                    'type'    => 'choice',
                    'options' => [ 'bottom_right', 'bottom_left', 'top_right', 'top_left' ],
                ],
                'button_style'   => [
                    'type'    => 'choice',
                    'options' => [ 'icon', 'label', 'both' ],
                ],
                'brand_color'    => [ 'type' => 'color' ],
                'corner_radius'  => [
                    'type' => 'int',
                    'min'  => 0,
                    'max'  => 24,
                ],
                'icon'           => [
                    'type'    => 'choice',
                    'options' => self::LAUNCHER_ICONS,
                ],
                'icon_upload_id' => [ 'type' => 'attachment_id' ],
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
                'referral_card_heading' => [ 'type' => 'text' ], // Pro
                'tier_progress_line'    => [ 'type' => 'text' ], // Pro
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
            ],
            'tier'            => [ 'badges' => [ 'type' => 'badge_list' ] ], // Pro
            'panel'           => [ // Pro
                'layout'                     => [
                    'type'    => 'choice',
                    'options' => [ 'compact', 'full' ],
                ],
                'show_referral_card'         => [ 'type' => 'bool' ],
                'show_progress_to_next_tier' => [ 'type' => 'bool' ],
                'show_reward_catalog'        => [ 'type' => 'bool' ],
            ],
            'typography'      => [ // Pro
                'mode'       => [
                    'type'    => 'choice',
                    'options' => [ 'inherit', 'custom' ],
                ],
                'font'       => [
                    'type' => 'text',
                    'max'  => 120,
                ],
                'custom_css' => [ 'type' => 'textarea' ],
            ],
        ];
    }
}
