<?php
namespace YayLoyalty\Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Storefront\LauncherVisibility;
use YayLoyalty\Helpers\DesignSchema;

/**
 * Test LauncherVisibility::is_visible() with all scope and role combinations.
 *
 * A pure function that evaluates visibility based on configuration and context.
 */
final class LauncherVisibilityTest extends TestCase {

    public function test_all_pages_scope_shows_everywhere(): void {
        $visibility = [
            'scope'          => DesignSchema::SCOPE_ALL_PAGES,
            'hide_for_roles' => [],
        ];

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, false, 0, [] ),
            'All pages scope should show on non-shop pages for guests'
        );

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, true, 0, [] ),
            'All pages scope should show on shop pages for guests'
        );

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, false, 123, [ 'customer' ] ),
            'All pages scope should show on custom pages for members'
        );
    }

    public function test_shop_and_product_scope_only_shows_on_shop(): void {
        $visibility = [
            'scope'          => DesignSchema::SCOPE_SHOP_AND_PRODUCT,
            'hide_for_roles' => [],
        ];

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, true, 0, [] ),
            'Shop scope should show on shop pages'
        );

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, false, 0, [] ),
            'Shop scope should hide on non-shop pages'
        );

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, false, 123, [] ),
            'Shop scope should hide on custom pages'
        );
    }

    public function test_custom_pages_scope_with_page_ids(): void {
        $visibility = [
            'scope'            => DesignSchema::SCOPE_CUSTOM_PAGES,
            'include_page_ids' => [ '5', '10', '15' ],
            'hide_for_roles'   => [],
        ];

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, false, 5, [] ),
            'Custom pages scope should show on included page'
        );

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, false, 10, [] ),
            'Custom pages scope should show on another included page'
        );

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, false, 20, [] ),
            'Custom pages scope should hide on non-included page'
        );

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, true, 5, [] ),
            'Custom pages scope should check page_id regardless of is_shop_page flag'
        );
    }

    public function test_custom_pages_scope_with_no_page_ids(): void {
        $visibility = [
            'scope'            => DesignSchema::SCOPE_CUSTOM_PAGES,
            'include_page_ids' => [],
            'hide_for_roles'   => [],
        ];

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, false, 0, [] ),
            'Custom pages scope with no ids should hide when no page'
        );

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, false, 5, [] ),
            'Custom pages scope with no ids should hide even on a real page'
        );
    }

    public function test_hide_for_roles_hides_regardless_of_scope(): void {
        $visibility = [
            'scope'          => DesignSchema::SCOPE_ALL_PAGES,
            'hide_for_roles' => [ 'administrator', 'shop_manager' ],
        ];

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, true, 0, [ 'administrator' ] ),
            'Should hide for administrator regardless of scope'
        );

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, true, 0, [ 'customer', 'shop_manager' ] ),
            'Should hide if any role is in hide list'
        );

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, true, 0, [ 'customer' ] ),
            'Should show when user has no hidden roles'
        );
    }

    public function test_guest_has_empty_roles(): void {
        $visibility = [
            'scope'          => DesignSchema::SCOPE_ALL_PAGES,
            'hide_for_roles' => [],
        ];

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, true, 0, [] ),
            'Guest with empty roles should be visible when no roles hidden'
        );
    }

    public function test_hide_for_roles_does_not_affect_guest(): void {
        $visibility = [
            'scope'          => DesignSchema::SCOPE_ALL_PAGES,
            'hide_for_roles' => [ 'administrator' ],
        ];

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, true, 0, [] ),
            'Guest should not match any hide_for_roles'
        );
    }

    public function test_default_scope_is_all_pages(): void {
        $visibility = [
            'hide_for_roles' => [],
        ];

        $this->assertTrue(
            LauncherVisibility::is_visible( $visibility, false, 0, [] ),
            'Missing scope should default to all_pages'
        );
    }

    public function test_multiple_user_roles_any_match_hides(): void {
        $visibility = [
            'scope'          => DesignSchema::SCOPE_ALL_PAGES,
            'hide_for_roles' => [ 'administrator' ],
        ];

        $this->assertFalse(
            LauncherVisibility::is_visible( $visibility, true, 0, [ 'customer', 'administrator', 'editor' ] ),
            'Should hide if any of multiple roles match'
        );
    }
}
