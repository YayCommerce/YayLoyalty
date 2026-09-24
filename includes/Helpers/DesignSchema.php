<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Design field declaration: defaults and the group/field/type whitelist DesignHelper::apply_schema() coerces
 * against. The launcher and tier badge icons are separate vocabularies: different UI elements, free to diverge.
 */
class DesignSchema {

    public const LAUNCHER_ICONS = [ 'default', 'circle', 'diamond', 'bar', 'ring', 'line', 'custom' ];
    public const BADGE_ICONS    = [ 'ring', 'diamond', 'square' ];

    public static function get_default_design(): array {
        return [
            'launcher'        => [
                'position'       => 'bottom_right',
                'button_style'   => 'both',
                'brand_color'    => '#1f6fb2',
                'corner_radius'  => 8,
                // 'custom' is the only value giving icon_upload_id meaning; every other value ignores the upload.
                'icon'           => 'default',
                'icon_upload_id' => 0,
            ],
            // Real, editable defaults — an empty save is literal, never "use the default"; see DesignHelper's docblock.
            'storefront_text' => [
                'launcher_label'        => __( 'Rewards', 'yayloyalty' ),
                'balance_heading'       => __( 'Your points', 'yayloyalty' ),
                'earn_rate_line'        => __( 'Earn {points} points per {amount} spent', 'yayloyalty' ),
                'redeem_button'         => __( 'Apply points', 'yayloyalty' ),
                'signed_out_prompt'     => __( 'Sign in to earn points on this order', 'yayloyalty' ),
                'referral_card_heading' => __( 'Invite a friend, get {amount} points', 'yayloyalty' ), // Pro
                'tier_progress_line'    => __( '{points} points to {tier}', 'yayloyalty' ), // Pro
            ],
            'visibility'      => [
                'scope'            => 'all_pages',
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
                // Stored and sanitised, but rendered nowhere yet — CSS exfiltration is an open risk, deferred to
                // whoever ships storefront CSS rendering.
                'custom_css' => '',
            ],
        ];
    }

    /**
     * Type map by group then field; anything absent is dropped in both directions. An array value is a
     * closed choice list. Declares every field in both builds so a Lite downgrade never wipes a merchant's
     * stored Pro design (code-standards.md §13).
     *
     * @return array<string, array<string, string|string[]>>
     */
    public static function schema(): array {
        return [
            'launcher'        => [
                'position'       => [ 'bottom_right', 'bottom_left', 'top_right', 'top_left' ],
                'button_style'   => [ 'icon', 'label', 'both' ],
                'brand_color'    => 'color',
                'corner_radius'  => 'radius',
                'icon'           => self::LAUNCHER_ICONS,
                'icon_upload_id' => 'attachment_id',
            ],
            'storefront_text' => [
                'launcher_label'        => 'text',
                'balance_heading'       => 'text',
                'earn_rate_line'        => 'text',
                'redeem_button'         => 'text',
                'signed_out_prompt'     => 'text',
                'referral_card_heading' => 'text', // Pro
                'tier_progress_line'    => 'text', // Pro
            ],
            'visibility'      => [
                'scope'            => [ 'all_pages', 'shop_and_product', 'custom_pages' ],
                // Read only when scope is custom_pages: the pages the launcher shows on.
                'include_page_ids' => 'id_list',
                'hide_on_mobile'   => 'bool',
                'hide_for_roles'   => 'slug_list',
            ],
            'tier'            => [ 'badges' => 'badge_list' ], // Pro
            'panel'           => [ // Pro
                'layout'                     => [ 'compact', 'full' ],
                'show_referral_card'         => 'bool',
                'show_progress_to_next_tier' => 'bool',
                'show_reward_catalog'        => 'bool',
            ],
            'typography'      => [ // Pro
                'mode'       => [ 'inherit', 'custom' ],
                'font'       => 'text',
                'custom_css' => 'textarea',
            ],
        ];
    }
}
