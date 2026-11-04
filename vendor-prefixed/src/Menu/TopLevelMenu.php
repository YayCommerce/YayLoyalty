<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Menu;

use YayLoyaltyScoped\YayCommerce\AdminShell\Support\AdminContext;
/**
 * Registers the shared YayCommerce top-level admin menu.
 *
 * First-to-register-wins: uses $admin_page_hooks['yaycommerce'] guard so only
 * ONE instance runs across all co-installed plugins. Ported from YayMail Pro
 * RegisterMenu.php — cross-plugin class_exists() probes removed because
 * LicenseRegistry now handles plugin discovery.
 *
 * Position 56 (after WooCommerce), capability manage_options, slug yaycommerce.
 */
class TopLevelMenu
{
    public static int $position = 56;
    public static string $capability = 'manage_options';
    public function init(): void
    {
        AdminContext::bind_menu([$this, 'register_menu'], 9);
    }
    /**
     * Register top-level menu. Guard ensures only the first plugin wins.
     */
    public function register_menu(): void
    {
        global $admin_page_hooks;
        // First-to-register-wins — if another plugin already registered the
        // 'yaycommerce' top-level menu, skip. This replaces the old cross-plugin
        // class_exists() probes that existed in RegisterMenu.php lines 88-105.
        if (isset($admin_page_hooks['yaycommerce'])) {
            return;
        }
        add_menu_page(
            'yaycommerce',
            'YayCommerce',
            self::$capability,
            'yaycommerce',
            null,
            // no callback — submenus render content
            self::get_logo_url(),
            self::$position
        );
        // Remove the auto-created "YayCommerce" submenu AFTER all submenus are registered.
        // This runs inside the firing menu hook, so the context is known — bind only
        // to the current hook (admin_menu or network_admin_menu) rather than both.
        add_action(AdminContext::hook(), [__CLASS__, 'remove_parent_submenu'], 999);
    }
    /**
     * WP auto-creates a submenu matching the parent slug. Remove it late
     * so it runs after all plugins have registered their submenus.
     */
    public static function remove_parent_submenu(): void
    {
        remove_submenu_page('yaycommerce', 'yaycommerce');
    }
    /**
     * Inline base64 SVG logo — copied as-is from YayMail Pro RegisterMenu.php.
     */
    public static function get_logo_url(): string
    {
        return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjQiIGhlaWdodD0iNjQiIHZpZXdCb3g9IjAgMCA2NCA2NCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTQ2LjI0NzYgNi40MDg5NkM0Ni4yNDc2IDkuOTQ4MTYgNDMuMzc3OCAxMi44MTc5IDM5LjgzODYgMTIuODE3OUMzNi4yOTk0IDEyLjgxNzkgMzMuNDI5NyA5Ljk0ODE2IDMzLjQyOTcgNi40MDg5NkMzMy40Mjk3IDIuODY5NzYgMzYuMjk4MSAwIDM5LjgzODYgMEM0My4zNzkxIDAgNDYuMjQ3NiAyLjg2OTc2IDQ2LjI0NzYgNi40MDg5NlpNMS4xNjQ3MSAyMi45OTI2Qy0wLjIxODk3MiAyMy4xMzIyIC0wLjQzNzg1MiAyNS4wNTg2IDAuODc5MjY4IDI1LjUwNEM5LjI1NDMxIDI4LjMzNjYgMjEuMzAwNCAzMC45OTUyIDI3LjI0OTggMzIuMjM0MkMyOS4yOTkxIDMyLjY2MDUgMzAuNjIzOSAzNC42NDgzIDMwLjI0MTIgMzYuNzA2NkMyOC41NDUyIDQ1LjgwNjEgMjUuMzc4NSA1NS41Mjc3IDIzLjM2ODkgNjIuMzM0N0MyMi45ODYyIDYzLjYzMTQgMjQuNTk5IDY0LjU3MjIgMjUuNTM4NSA2My42MDA2QzQ3LjIxMDIgNDEuMjAxOSA1OS4zODk0IDE4LjE5OSA2My44Njk0IDguNzIzMkM2NC40MjM2IDcuNTUwNzIgNjMuMTAwMSA2LjM4NzIgNjIuMDA0NCA3LjA4MDk2QzQ1LjM5MTMgMTcuNjA2NCAxMy44NTU5IDIxLjcxNzggMS4xNjQ3MSAyMi45OTI2WiIgZmlsbD0iI0E3QUFBRCIvPgo8L3N2Zz4=';
    }
}
