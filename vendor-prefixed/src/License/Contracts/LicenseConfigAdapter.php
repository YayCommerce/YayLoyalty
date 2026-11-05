<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\License\Contracts;

use YayLoyaltyScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter;
/**
 * Per-plugin configuration for the license subsystem.
 * Extends PluginMenuAdapter — pro plugins implement this single interface.
 *
 * APPEND-ONLY — adding methods requires a MAJOR version bump per CONTRIBUTING.md.
 */
interface LicenseConfigAdapter extends PluginMenuAdapter
{
    /**
     * Plugin slug used as the wp_options key prefix.
     *
     * MUST be byte-exact wp_options prefix — changing this loses customer license keys.
     * Quirks:
     *   - yay-wholesale-b2b-pro (hyphens are valid in option keys)
     *   - yay_currency (underscore between yay and currency, NOT yaycurrency)
     *   - yay_swatches (same underscore pattern)
     *
     * Option keys derived: {slug}_license_key, {slug}_license_info
     */
    public function get_plugin_slug(): string;
    /**
     * Human-readable display name for the license card,
     * e.g. 'YayMail Pro – WooCommerce Email Customizer'.
     */
    public function get_plugin_name(): string;
    /**
     * Current plugin version string, e.g. '4.4'.
     */
    public function get_plugin_version(): string;
    /**
     * Absolute path to the main plugin file.
     */
    public function get_plugin_file(): string;
    /**
     * EDD download ID (numeric).
     */
    public function get_item_id(): int;
    /**
     * EDD store URL. Default: 'https://yaycommerce.com/'.
     */
    public function get_store_url(): string;
    /**
     * Product page URL used for "Buy a license" CTA.
     */
    public function get_store_link(): string;
}
