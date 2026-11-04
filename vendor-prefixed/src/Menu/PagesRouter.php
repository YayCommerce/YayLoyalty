<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Menu;

use YayLoyaltyScoped\YayCommerce\AdminShell\Pages\LicensesPage;
use YayLoyaltyScoped\YayCommerce\AdminShell\Pages\RecommendedPluginsPage;
use YayLoyaltyScoped\YayCommerce\AdminShell\Pages\HelpPage;
use YayLoyaltyScoped\YayCommerce\AdminShell\Registry\LicenseRegistry;
use YayLoyaltyScoped\YayCommerce\AdminShell\Support\AdminContext;
/**
 * Registers Licenses, Other Plugins, and Help submenus under 'yaycommerce'.
 * Ported from YayMail Pro RegisterMenu::add_submenus() — de-branded and
 * adapter-driven (no hardcoded cross-plugin class_exists() probes).
 */
class PagesRouter
{
    private LicenseRegistry $registry;
    public function __construct(LicenseRegistry $registry)
    {
        $this->registry = $registry;
    }
    public function init(): void
    {
        // Bind on both admin_menu and network_admin_menu so the shell pages
        // (Help, Licenses, Recommended) appear in the Multisite Network Admin too.
        AdminContext::bind_menu([$this, 'register_submenus'], 11);
        // Instantiate RecommendedPluginsPage to register its wp_ajax_* handlers.
        RecommendedPluginsPage::get_instance();
    }
    public function register_submenus(): void
    {
        $submenus = $this->get_submenus();
        foreach ($submenus as $slug => $submenu) {
            $page_id = add_submenu_page($submenu['parent'], $submenu['name'], $submenu['name'], $submenu['capability'], $slug, $submenu['render_callback'], $submenu['position'] ?? null);
            if ($submenu['load_callback']) {
                add_action('load-' . $page_id, $submenu['load_callback']);
            }
        }
    }
    private function get_submenus(): array
    {
        $submenus = [];
        // Plugin submenus use position 0-99 (via adapter).
        // Shell pages use 900+ so they always appear at the bottom.
        // In Network Admin the capability is elevated to manage_network.
        $capability = AdminContext::capability('manage_options');
        $submenus['yaycommerce-help'] = ['parent' => 'yaycommerce', 'name' => __('Help', 'yaycommerce'), 'capability' => $capability, 'render_callback' => [HelpPage::class, 'render'], 'load_callback' => [HelpPage::class, 'load_data'], 'position' => 900];
        // Licenses submenu — only shown if any plugins are registered.
        $has_any = !empty($this->registry->all());
        if ($has_any) {
            $submenus['yaycommerce-licenses'] = ['parent' => 'yaycommerce', 'name' => __('Licenses', 'yaycommerce'), 'capability' => $capability, 'render_callback' => [LicensesPage::class, 'render'], 'load_callback' => [LicensesPage::class, 'load_data'], 'position' => 910];
        }
        $submenus['yaycommerce-other-plugins'] = ['parent' => 'yaycommerce', 'name' => __('Recommended Plugins', 'yaycommerce'), 'capability' => $capability, 'render_callback' => [RecommendedPluginsPage::class, 'render'], 'load_callback' => [RecommendedPluginsPage::class, 'load_data'], 'position' => 920];
        return $submenus;
    }
}
