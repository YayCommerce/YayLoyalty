<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Menu;

use YayLoyaltyScoped\YayCommerce\AdminShell\Support\AdminContext;
/**
 * Applies the intended ordering to the YayCommerce submenu.
 *
 * WordPress treats add_submenu_page()'s $position as an array INSERTION INDEX,
 * not an ordering rank, and silently appends any value >= the current submenu
 * count. Sparse positions (e.g. 10, 20, 21, 40) therefore collapse to plugin
 * load order. Rather than fight that at registration time (which can't be
 * coordinated across already-shipped scoped copies), we let every plugin
 * register however it likes, then reorder the FINAL assembled array once.
 *
 * Ownership: run only by the version-elected winner (via do_shell_registration),
 * so a single authority orders ALL submenus — including those registered by
 * older co-installed copies. Positions are read from a shared cross-scope map
 * populated by AdminShell::register_plugin().
 */
class SubmenuPositioner
{
    const PARENT = 'yaycommerce';
    const POSITION_KEY = 'yaycommerce_admin_shell_submenu_positions';
    public function init(): void
    {
        // Priority 9999: run after every plugin (<=10) and TopLevelMenu's parent
        // submenu cleanup (999) have finished, so the array is complete.
        // Bound to both hooks so reordering also applies in Network Admin.
        AdminContext::bind_menu([$this, 'reorder'], 9999);
    }
    /**
     * Reorder $submenu['yaycommerce'] by declared position.
     * Lower position first; ties and unknown/null positions keep their existing
     * order and sort after positioned items.
     */
    public function reorder(): void
    {
        global $submenu;
        if (empty($submenu[self::PARENT]) || !is_array($submenu[self::PARENT])) {
            return;
        }
        $positions = $GLOBALS[self::POSITION_KEY] ?? [];
        // Decorate with original index for a stable sort (usort is not stable
        // before PHP 8.0). Item shape: [ menu_title, capability, menu_slug, ... ].
        $decorated = [];
        foreach ($submenu[self::PARENT] as $index => $item) {
            $slug = $item[2] ?? '';
            $decorated[] = ['item' => $item, 'index' => $index, 'position' => $positions[$slug] ?? null];
        }
        usort($decorated, function ($a, $b) {
            $pa = $a['position'];
            $pb = $b['position'];
            if (null === $pa && null === $pb) {
                return $a['index'] <=> $b['index'];
            }
            if (null === $pa) {
                return 1;
                // unknown/null positions last
            }
            if (null === $pb) {
                return -1;
            }
            if ($pa === $pb) {
                return $a['index'] <=> $b['index'];
                // stable tiebreak
            }
            return $pa <=> $pb;
        });
        // Re-index to sequential keys; WP renders the submenu in array order.
        $submenu[self::PARENT] = array_values(array_column($decorated, 'item'));
    }
}
