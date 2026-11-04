<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Registry;

use YayLoyaltyScoped\YayCommerce\AdminShell\License\LicenseState;
/**
 * Reads the legacy yaycommerce_licensing_plugins filter at priority 999
 * (after all legacy plugins register at priority 100) and converts each
 * legacy registration into a PluginLicenseInfo stub with is_legacy=true.
 *
 * This enables the unified Licenses page to display un-migrated plugins
 * alongside migrated ones during incremental rollout.
 */
class LegacyBridge
{
    private LicenseRegistry $registry;
    public function __construct(LicenseRegistry $registry)
    {
        $this->registry = $registry;
    }
    /**
     * Hook into plugins_loaded at priority 9999 so the registry is populated
     * before admin_menu fires. This guarantees the Licenses submenu renders
     * even when only un-migrated (legacy) plugins are active.
     *
     * Priority 9999 runs AFTER VersionedLoader::elect() at 999 (which calls
     * AdminShell::boot() and fires yaycommerce_admin_shell_booted) AND after
     * all legacy plugins register via yaycommerce_licensing_plugins at 100.
     *
     * INVARIANT: if VersionedLoader priority changes, update this priority too.
     */
    public function init(): void
    {
        add_action('plugins_loaded', [$this, 'load_legacy_plugins'], 9999);
    }
    /**
     * Read legacy filter and convert entries to PluginLicenseInfo stubs.
     *
     * Legacy format (each entry):
     *   [ 'slug', 'basename', 'file', 'url', 'item_id', 'dir_path', 'name' ]
     */
    public function load_legacy_plugins(): void
    {
        $legacy_plugins = apply_filters('yaycommerce_licensing_plugins', []);
        foreach ($legacy_plugins as $plugin) {
            $slug = $plugin['slug'] ?? '';
            if (empty($slug)) {
                continue;
            }
            // Skip if already registered by migrated code.
            if ($this->registry->has($slug)) {
                continue;
            }
            $info = $this->build_legacy_info($plugin);
            $this->registry->register($info);
        }
    }
    /**
     * Build a PluginLicenseInfo from a legacy filter entry.
     * Reads {slug}_license_info from wp_options to get current status.
     */
    private function build_legacy_info(array $plugin): PluginLicenseInfo
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
        // legacy plugins don't expose version here
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
     * Derive a normalized status string from legacy option data.
     */
    private function derive_status(string $license_key, array $raw_info): string
    {
        if (empty($license_key)) {
            return 'inactive';
        }
        return LicenseState::is_past_expiry($raw_info['expires'] ?? null) ? 'expired' : 'valid';
    }
}
