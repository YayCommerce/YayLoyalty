<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Menu;

use YayLoyaltyScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter;
use YayLoyaltyScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter;
use YayLoyaltyScoped\YayCommerce\AdminShell\License\License;
use YayLoyaltyScoped\YayCommerce\AdminShell\Pages\WooCommerceRequiredPage;
use YayLoyaltyScoped\YayCommerce\AdminShell\Support\AdminContext;
/**
 * Registers a plugin-named submenu under YayCommerce.
 * Works with any PluginMenuAdapter (lite or pro).
 *
 * For pro plugins: automatically redirects to Licenses page when license
 * is not active — no developer action needed.
 */
class PluginSubmenu
{
    private PluginMenuAdapter $adapter;
    public function __construct(PluginMenuAdapter $adapter)
    {
        $this->adapter = $adapter;
    }
    public function init(): void
    {
        // Bound to both hooks; the context gate in register() decides whether the
        // plugin actually registers in the current (site vs network) context.
        AdminContext::bind_menu([$this, 'register'], 10);
    }
    public function register(): void
    {
        // Context gate: register only where the plugin opted in. Network Admin
        // requires wants_network_menu(); site dashboard requires wants_site_menu().
        // Defaults (legacy adapters): site=true, network=false.
        if (AdminContext::is_network()) {
            if (!AdminContext::wants_network($this->adapter)) {
                return;
            }
        } elseif (!AdminContext::wants_site($this->adapter)) {
            return;
        }
        $menu_slug = $this->adapter->get_menu_slug();
        if (empty($menu_slug)) {
            return;
        }
        global $submenu;
        $has_menu = \false;
        $is_override = \false;
        if (isset($submenu['yaycommerce'])) {
            $yaycommerce_menu = $submenu['yaycommerce'];
            foreach ($yaycommerce_menu as $key => $value) {
                if ($value[2] === $menu_slug) {
                    if (method_exists($this->adapter, 'is_licensed') && $this->adapter->is_licensed() || !method_exists($this->adapter, 'is_licensed')) {
                        remove_submenu_page('yaycommerce', $menu_slug);
                        $is_override = \true;
                    } else {
                        $has_menu = \true;
                    }
                    break;
                }
            }
        }
        if ($has_menu) {
            return;
        }
        $callback = $this->adapter->get_settings_page_callback();
        // Guard: ensure callback is actually callable
        if (null !== $callback && !is_callable($callback)) {
            $callback = null;
        }
        // Pro plugins without active license → override callback to redirect
        $needs_redirect = \false;
        if ($this->adapter instanceof LicenseConfigAdapter) {
            $license = new License($this->adapter);
            if (!$license->is_active()) {
                $needs_redirect = \true;
                $callback = null;
            }
        }
        // WooCommerce dependency gate: when the plugin depends on WooCommerce and it
        // is inactive, render the shared "install WooCommerce" screen in place of the
        // settings page. Applied only on the real render path — the license redirect
        // above takes precedence, so unlicensed pro plugins still land on Licenses.
        // Opt-in via the optional needs_woocommerce_screen() adapter method; the
        // adapter owns the runtime WooCommerce check so it runs un-prefixed under
        // PHP-Scoper (the adapter class is excluded from scoping in each plugin).
        if (!$needs_redirect && method_exists($this->adapter, 'needs_woocommerce_screen') && $this->adapter->needs_woocommerce_screen()) {
            $screen_copy = method_exists($this->adapter, 'get_woocommerce_screen_copy') ? (array) $this->adapter->get_woocommerce_screen_copy() : [];
            $callback = static function () use ($screen_copy) {
                WooCommerceRequiredPage::render($screen_copy);
            };
        }
        $page_id = add_submenu_page(
            'yaycommerce',
            $this->adapter->get_page_title(),
            $this->adapter->get_menu_title(),
            // In Network Admin, elevate to manage_network (super-admin only).
            AdminContext::capability($this->adapter->get_capability()),
            $menu_slug,
            $callback ?? '__return_false',
            // Position intentionally null here. WP treats add_submenu_page()'s
            // $position as a fragile array insertion index, not an ordering rank.
            // Final ordering is applied later by SubmenuPositioner, which reorders
            // the assembled $submenu['yaycommerce'] from the shared position map.
            null
        );
        if ($is_override) {
            remove_all_actions('load-' . $page_id);
        }
        if ($needs_redirect) {
            add_action('load-' . $page_id, [__CLASS__, 'redirect_to_licenses']);
        }
    }
    public static function redirect_to_licenses(): void
    {
        // The load hook fires in the current context — target the matching dashboard
        // so a network-flagged pro plugin redirects within Network Admin, not the site.
        $path = 'admin.php?page=yaycommerce-licenses';
        $url = AdminContext::is_network() ? network_admin_url($path) : admin_url($path);
        wp_safe_redirect($url);
        exit;
    }
}
