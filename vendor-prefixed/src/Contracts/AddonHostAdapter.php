<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Contracts;

/**
 * Optional interface for plugins that host licensed addons.
 *
 * When a plugin adapter implements this alongside PluginMenuAdapter,
 * AdminShell creates an AddonBridge that reads the declared filter,
 * registers addon entries in the LicenseRegistry, and renders
 * license cards for them on the Licenses page.
 *
 * Example: YayMail Lite's adapter implements both PluginMenuAdapter
 * and AddonHostAdapter, returning 'yaymail_available_licensing_plugins'.
 * All existing addons continue using that filter unchanged.
 */
interface AddonHostAdapter
{
    /**
     * WordPress filter name that addons use to register their licensing data.
     *
     * The filter must return an array of entries, each with:
     *   [ 'slug', 'name', 'basename', 'file', 'url', 'item_id' ]
     *
     * Same format as yaycommerce_licensing_plugins.
     */
    public function get_addon_licensing_filter(): string;
}
