<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Menu;

use YayLoyaltyScoped\YayCommerce\AdminShell\Pages\RecommendedPluginsPage;
defined('ABSPATH') || exit;
/**
 * Registers an "Other Plugins" submenu under an external plugin's own top-level menu.
 * Used for plugins (e.g. whatsapp, filester) that own their own menu hierarchy
 * and should NOT appear under the shared YayCommerce menu.
 */
class ExternalPluginMenuAdapter
{
    private string $parent_menu;
    private string $menu_title;
    private string $menu_capability;
    private string $menu_slug;
    public function __construct(array $config)
    {
        $this->parent_menu = $config['parent_menu'] ?? '';
        $this->menu_title = 'Recommended Plugins';
        $this->menu_capability = $config['menu_capability'] ?? 'manage_options';
        $this->menu_slug = $config['menu_slug'] ?? '';
    }
    public function init(): void
    {
        RecommendedPluginsPage::get_instance();
        if (!is_admin()) {
            return;
        }
        add_action('admin_menu', [$this, 'register_other_plugins_submenu'], 20);
    }
    public function register_other_plugins_submenu(): void
    {
        $page_id = add_submenu_page($this->parent_menu, $this->menu_title, $this->menu_title, $this->menu_capability, $this->menu_slug, [RecommendedPluginsPage::class, 'render']);
        add_action('load-' . $page_id, function () {
            RecommendedPluginsPage::load_data();
        });
    }
}
