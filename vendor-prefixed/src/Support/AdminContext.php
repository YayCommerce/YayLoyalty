<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Support;

use YayLoyaltyScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter;
defined('ABSPATH') || exit;
/**
 * Resolves the current admin context (site dashboard vs Multisite Network Admin)
 * and the per-plugin opt-in for each context.
 *
 * WordPress fires `admin_menu` on a site dashboard and `network_admin_menu` in
 * Network Admin — never both in one request. Menu components bind to BOTH hooks
 * and consult this helper at callback time (NOT at registration time) to decide
 * the correct hook, capability, and whether a plugin opted into the context.
 *
 * Opt-in is read via optional adapter methods detected with method_exists() —
 * the same backward-compatible pattern PluginSubmenu uses for is_licensed().
 * Adapters that predate these methods keep the safe defaults (site-only).
 */
class AdminContext
{
    /** Network Admin capability — super-admins only. */
    const NETWORK_CAPABILITY = 'manage_network';
    /**
     * Both admin-menu hooks. WP fires exactly one per request:
     * `admin_menu` on a site dashboard, `network_admin_menu` in Network Admin.
     */
    const MENU_HOOKS = ['admin_menu', 'network_admin_menu'];
    /**
     * Bind a menu callback to BOTH admin-menu hooks at the same priority.
     *
     * Only the hook matching the current request fires, so the other binding
     * is an inert no-op. Binding both (rather than branching on context) keeps
     * registration timing-proof — callers may run as early as `plugins_loaded`,
     * before the admin context is reliably known.
     *
     * @param callable $callback
     */
    public static function bind_menu($callback, int $priority): void
    {
        foreach (self::MENU_HOOKS as $hook) {
            add_action($hook, $callback, $priority);
        }
    }
    /**
     * True when the current request is the Multisite Network Admin.
     *
     * Guarded with function_exists() so the class is usable in non-WP unit
     * context, where the function is absent and site context is assumed.
     */
    public static function is_network(): bool
    {
        return \function_exists('YayLoyaltyScoped\is_network_admin') && \YayLoyaltyScoped\is_network_admin();
    }
    /**
     * The admin_menu hook that fires for the current context.
     */
    public static function hook(): string
    {
        return self::is_network() ? 'network_admin_menu' : 'admin_menu';
    }
    /**
     * Capability required for menus/pages in the current context.
     * Network Admin requires `manage_network`; site dashboard keeps $default.
     */
    public static function capability(string $default): string
    {
        return self::is_network() ? self::NETWORK_CAPABILITY : $default;
    }
    /**
     * Whether the plugin wants its submenu on the per-site dashboard.
     * Optional method — absent ⇒ true (legacy adapters stay site-visible).
     */
    public static function wants_site(PluginMenuAdapter $adapter): bool
    {
        if (!\method_exists($adapter, 'wants_site_menu')) {
            return \true;
        }
        return (bool) $adapter->wants_site_menu();
    }
    /**
     * Whether the plugin wants its submenu in Network Admin.
     * Optional method — absent ⇒ false (legacy adapters never go to network).
     */
    public static function wants_network(PluginMenuAdapter $adapter): bool
    {
        if (!\method_exists($adapter, 'wants_network_menu')) {
            return \false;
        }
        return (bool) $adapter->wants_network_menu();
    }
}
