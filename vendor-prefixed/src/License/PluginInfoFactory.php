<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\License;

use YayLoyaltyScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter;
use YayLoyaltyScoped\YayCommerce\AdminShell\Registry\PluginLicenseInfo;
/**
 * Builds a PluginLicenseInfo data object from a LicenseHandler's current state.
 */
class PluginInfoFactory
{
    public static function from_adapter(LicenseConfigAdapter $adapter): PluginLicenseInfo
    {
        $license = new License($adapter);
        $raw_info = $license->get_license_info();
        $expires_raw = $raw_info['expires'] ?? null;
        $activations_used = (int) ($raw_info['site_count'] ?? 0);
        $activations_limit = (int) ($raw_info['license_limit'] ?? 0);
        $status = 'inactive';
        if ($license->is_active()) {
            $status = $license->is_expired() ? 'expired' : 'valid';
        }
        $info = new PluginLicenseInfo();
        $info->slug = $adapter->get_plugin_slug();
        $info->name = $adapter->get_plugin_name();
        $info->version = $adapter->get_plugin_version();
        $info->basename = $adapter->get_plugin_basename();
        $info->item_id = $adapter->get_item_id();
        $info->store_link = $adapter->get_store_link();
        $info->license_key = (string) $license->get_license_key();
        $info->status = $status;
        $info->expires_at = $expires_raw && 'Not updated' !== $expires_raw ? $expires_raw : null;
        $info->activations_used = $activations_used;
        $info->activations_limit = $activations_limit;
        $info->is_legacy = \false;
        $info->raw_info = $raw_info;
        return $info;
    }
}
