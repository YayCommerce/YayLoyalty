<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Helpers\DesignSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the launcher renders on this request, from the Design tab's visibility group. Pure: the caller
 * reads WordPress and passes the answers in, so the rules are testable without a WordPress bootstrap.
 * `hide_on_mobile` is a CSS media query instead, so a page cached for one device still behaves on another.
 */
final class LauncherVisibility {

    /**
     * @param array    $visibility   DesignHelper::get_design()['visibility'].
     * @param bool     $is_shop_page Shop, product, product category/tag archive.
     * @param int      $page_id      The queried object id; 0 when none.
     * @param string[] $roles        The signed-in user's roles; empty for a guest.
     */
    public static function is_visible( array $visibility, bool $is_shop_page, int $page_id, array $roles ): bool {
        if ( array_intersect( $roles, (array) ( $visibility['hide_for_roles'] ?? [] ) ) ) {
            return false;
        }

        switch ( $visibility['scope'] ?? DesignSchema::SCOPE_ALL_PAGES ) {
            case DesignSchema::SCOPE_SHOP_AND_PRODUCT:
                return $is_shop_page;

            case DesignSchema::SCOPE_CUSTOM_PAGES:
                return $page_id > 0 && in_array( $page_id, array_map( 'intval', (array) ( $visibility['include_page_ids'] ?? [] ) ), true );

            default:
                return true;
        }
    }
}
