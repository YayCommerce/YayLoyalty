<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Registry;

use YayLoyaltyScoped\YayCommerce\AdminShell\License\License;
use YayLoyaltyScoped\YayCommerce\AdminShell\License\LicenseState;
use YayLoyaltyScoped\YayCommerce\AdminShell\License\RestAPI;
use YayLoyaltyScoped\YayCommerce\AdminShell\Support\Slug;
/**
 * Reads a plugin-specific addon licensing filter and bridges entries
 * into the LicenseRegistry. Renders license cards for addon entries.
 *
 * Similar to LegacyBridge but for plugin-specific addon filters
 * (e.g., yaymail_available_licensing_plugins) rather than the global
 * yaycommerce_licensing_plugins filter.
 *
 * Pattern mirrors LegacyBridge — derive_status() logic intentionally
 * duplicated (only two consumers; extract if a third appears).
 */
class AddonBridge
{
    private string $filter_name;
    private LicenseRegistry $registry;
    /** @var string[] Slugs this bridge registered (for rendering). */
    private array $addon_slugs = [];
    /** @var array<string, array> Raw addon data keyed by slug (for rendering). */
    private array $addon_data = [];
    public function __construct(string $filter_name, LicenseRegistry $registry)
    {
        $this->filter_name = $filter_name;
        $this->registry = $registry;
    }
    /**
     * Hook into plugins_loaded at priority 9999 (same as LegacyBridge)
     * and yaycommerce_licenses_page at priority 100 for card rendering.
     */
    public function init(): void
    {
        add_action('plugins_loaded', [$this, 'load_addon_plugins'], 9999);
        if (is_admin()) {
            add_action('yaycommerce_licenses_page', [$this, 'render_addon_cards'], 100);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_addon_scripts']);
        }
    }
    /**
     * Read addon filter and convert entries to PluginLicenseInfo stubs.
     * Addon format matches yaycommerce_licensing_plugins:
     *   [ 'slug', 'name', 'basename', 'file', 'url', 'item_id' ]
     */
    public function load_addon_plugins(): void
    {
        $addons = apply_filters($this->filter_name, []);
        foreach ($addons as $plugin) {
            $slug = $plugin['slug'] ?? '';
            if (empty($slug) || $this->registry->has($slug)) {
                continue;
            }
            $info = $this->build_addon_info($plugin);
            $this->registry->register($info);
            $this->addon_slugs[] = $slug;
            $this->addon_data[$slug] = $plugin;
            // Register REST routes so license card buttons (activate/update/deactivate) work.
            new RestAPI($this->build_addon_adapter($plugin));
        }
    }
    /**
     * Build a PluginLicenseInfo from an addon filter entry.
     * Reads {slug}_license_info from wp_options for current status.
     * Pattern mirrors LegacyBridge::build_legacy_info().
     */
    private function build_addon_info(array $plugin): PluginLicenseInfo
    {
        $slug = $plugin['slug'] ?? '';
        $raw_info = get_option($slug . '_license_info', []);
        if (is_string($raw_info)) {
            $raw_info = json_decode($raw_info, \true) ?: [];
        }
        $license_key = (string) get_option($slug . '_license_key', '');
        $expires_raw = $raw_info['expires'] ?? null;
        $status = $this->derive_status($license_key, $raw_info);
        $info = new PluginLicenseInfo();
        $info->slug = $slug;
        $info->name = $plugin['name'] ?? $slug;
        $info->version = '';
        $info->basename = $plugin['basename'] ?? '';
        $info->item_id = (int) ($plugin['item_id'] ?? 0);
        $info->store_link = $plugin['url'] ?? '';
        $info->license_key = $license_key;
        $info->status = $status;
        $info->expires_at = $expires_raw && 'Not updated' !== $expires_raw ? $expires_raw : null;
        $info->activations_used = (int) ($raw_info['site_count'] ?? 0);
        $info->activations_limit = (int) ($raw_info['license_limit'] ?? 0);
        $info->is_legacy = \true;
        $info->raw_info = $raw_info;
        return $info;
    }
    /**
     * Derive normalized status from wp_options data.
     * Mirrors LegacyBridge::derive_status().
     */
    private function derive_status(string $license_key, array $raw_info): string
    {
        if (empty($license_key)) {
            return 'inactive';
        }
        return LicenseState::is_past_expiry($raw_info['expires'] ?? null) ? 'expired' : 'valid';
    }
    /**
     * Render license cards for addon entries this bridge registered.
     * Builds a minimal LicenseConfigAdapter per addon so the License
     * class and card templates work without modification.
     */
    public function render_addon_cards(): void
    {
        foreach ($this->addon_slugs as $slug) {
            $plugin_data = $this->addon_data[$slug] ?? [];
            if (empty($plugin_data)) {
                continue;
            }
            $adapter = $this->build_addon_adapter($plugin_data);
            $license = new License($adapter);
            $_plugin = ['slug' => $slug, 'name' => $plugin_data['name'] ?? $slug];
            if ($license->is_active()) {
                include __DIR__ . '/../../views/license-card.php';
            } else {
                include __DIR__ . '/../../views/license-activate-card.php';
            }
        }
    }
    /**
     * Enqueue license.js and localize data for each addon slug.
     * Mirrors LicenseHandler::enqueue_license_scripts().
     *
     * NOTE: Addon must register REST routes at {slug}/v1/license/*
     * for the activate/update/deactivate buttons to work.
     */
    public function enqueue_addon_scripts(): void
    {
        if (!isset($_GET['page']) || 'yaycommerce-licenses' !== $_GET['page']) {
            // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        if (empty($this->addon_slugs)) {
            return;
        }
        $assets_url = plugin_dir_url(__FILE__) . '../../assets/';
        wp_enqueue_script('yaycommerce-license', $assets_url . 'js/license.js', ['jquery'], '1.0', \true);
        foreach ($this->addon_slugs as $slug) {
            wp_localize_script('yaycommerce-license', Slug::to_var_name($slug) . 'LicenseData', ['slug' => $slug, 'apiSettings' => ['restNonce' => wp_create_nonce('wp_rest'), 'restUrl' => esc_url_raw(rest_url(Slug::to_var_name($slug) . '/v1')), 'adminUrl' => admin_url()]]);
        }
    }
    /**
     * Build a minimal LicenseConfigAdapter from addon filter data.
     * Used by License constructor + card templates.
     */
    private function build_addon_adapter(array $plugin_data): AddonLicenseAdapter
    {
        return new AddonLicenseAdapter($plugin_data);
    }
}
