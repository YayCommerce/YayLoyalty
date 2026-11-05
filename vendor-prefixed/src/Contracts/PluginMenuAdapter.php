<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Contracts;

/**
 * Minimal per-plugin config for menu registration.
 * All plugins (lite + pro) implement this.
 * Pro plugins implement LicenseConfigAdapter which extends this.
 */
interface PluginMenuAdapter
{
    /**
     * Short name for the admin sidebar submenu item, e.g. 'YayMail'.
     */
    public function get_menu_title(): string;
    /**
     * Browser tab title for the settings page, e.g. 'YayMail Pro - Settings'.
     */
    public function get_page_title(): string;
    /**
     * Menu slug under YayCommerce, e.g. 'yaymail-settings'.
     * Return empty string if plugin has no settings page.
     */
    public function get_menu_slug(): string;
    /**
     * Render callback for the settings page.
     * Return null to redirect to the Licenses page (pro) or show nothing (lite).
     */
    public function get_settings_page_callback(): ?callable;
    /**
     * Ordering rank of the plugin submenu under the YayCommerce menu.
     * Lower numbers appear first; ties keep registration order. Values may be
     * sparse (e.g. 10, 20, 21, 40). Return null to append after positioned items.
     */
    public function get_settings_page_position(): ?int;
    /**
     * WP capability required. Default: 'manage_options'.
     */
    public function get_capability(): string;
    /**
     * WP plugin basename, e.g. 'yaymail-pro/yaymail.php'.
     */
    public function get_plugin_basename(): string;
    /**
     * Label for the settings action link on the Plugins page.
     */
    public function get_settings_label(): string;
    /**
     * Documentation URL. Return empty string to hide the link.
     */
    public function get_docs_url(): string;
    /**
     * "Go Pro" upgrade URL (for lite plugins). Return empty string to hide.
     */
    public function get_pro_url(): string;
    /*
     * ── Optional capability methods (NOT part of the interface contract) ──
     *
     * These are detected at runtime via method_exists() (see AdminContext),
     * so adapters MAY implement them without breaking the append-only contract.
     * Declaring them here would force every shipped adapter to implement them.
     *
     * Multisite Network Admin placement (defaults preserve legacy behavior):
     *
     *   // Submenu shows on each site's dashboard. Absent ⇒ true.
     *   public function wants_site_menu(): bool;
     *
     *   // Submenu shows in the Multisite Network Admin. Absent ⇒ false.
     *   public function wants_network_menu(): bool;
     *
     * WooCommerce dependency gate (see PluginSubmenu + Pages\WooCommerceRequiredPage):
     *
     *   // Render the shared "WooCommerce required" screen in place of the settings
     *   // page. Absent ⇒ false. The adapter owns the runtime check (typically
     *   // `! class_exists( 'WooCommerce' )`) so it evaluates un-prefixed under
     *   // PHP-Scoper — the adapter class is excluded from scoping in each plugin.
     *   // The license redirect takes precedence: unlicensed pro plugins still go
     *   // to the Licenses page even when this returns true.
     *   public function needs_woocommerce_screen(): bool;
     *
     *   // Optional copy overrides for that screen: { icon, title, text, button, hint }.
     *   // Absent ⇒ generic de-branded defaults are used.
     *   public function get_woocommerce_screen_copy(): array;
     */
}
