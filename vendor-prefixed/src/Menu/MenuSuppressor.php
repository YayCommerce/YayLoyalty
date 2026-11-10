<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Menu;

use YayLoyaltyScoped\YayCommerce\AdminShell\Support\AdminContext;
/**
 * Strategy (a) — removes legacy per-plugin top-level menu slugs before
 * the shared YayCommerce menu is registered.
 *
 * NOTE: In practice, the real protection against duplicate top-level menus
 * is the isset($admin_page_hooks['yaycommerce']) guard in TopLevelMenu.
 * This class is a safety backstop for edge cases where other plugins register
 * a top-level menu under a DIFFERENT slug that conflicts with our nav tree.
 *
 * The one known legacy slug is 'yaycommerce' itself — handled by TopLevelMenu's
 * guard. The suppressor list here exists for future-proofing.
 *
 * Fallback path: if strategy (a) causes problems in Phase 02 pilot,
 * disable this suppressor and coexist with legacy menus (strategy b).
 */
class MenuSuppressor
{
    /**
     * Known legacy top-level menu slugs that should be suppressed.
     * Add slugs here if future plugins register conflicting top-level menus.
     *
     * @var string[]
     */
    private array $slugs_to_suppress;
    /**
     * @param string[]|null $slugs Override the default list (mainly for testing).
     */
    public function __construct(?array $slugs = null)
    {
        // Default: empty — the 'yaycommerce' slug is already handled by
        // TopLevelMenu's first-to-register guard. Keep this list explicit.
        $this->slugs_to_suppress = $slugs ?? [];
    }
    /**
     * Register hooks. Runs on admin_menu / network_admin_menu priority 5
     * (before standard priority 10).
     */
    public function init(): void
    {
        AdminContext::bind_menu([$this, 'suppress_legacy_menus'], 5);
    }
    /**
     * Remove any legacy top-level menus from the slug list.
     */
    public function suppress_legacy_menus(): void
    {
        foreach ($this->slugs_to_suppress as $slug) {
            remove_menu_page($slug);
        }
    }
    /**
     * Return the list of slugs this suppressor will remove.
     *
     * @return string[]
     */
    public function get_slugs(): array
    {
        return $this->slugs_to_suppress;
    }
}
