<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\License;

use YayLoyaltyScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter;
defined('ABSPATH') || exit;
/**
 * License state model.
 *
 * Option keys are derived from the adapter slug — NEVER hardcoded.
 * Ported from YayMail Pro License.php — YAYMAIL_* constants replaced with adapter.
 */
class License
{
    protected LicenseConfigAdapter $adapter;
    /** Cached license key (may be false if not set). */
    protected $license_key = null;
    /** Cached license info array. */
    protected $license_info = null;
    public function __construct(LicenseConfigAdapter $adapter)
    {
        $this->adapter = $adapter;
        $this->license_key = $this->get_license_key();
        $this->license_info = $this->get_license_info();
    }
    public function update_license_info(array $license_info): void
    {
        unset($license_info['success']);
        update_option($this->adapter->get_plugin_slug() . '_license_info', $license_info, \false);
        $this->license_info = $license_info;
    }
    public function update_license_key(string $license_key): void
    {
        update_option($this->adapter->get_plugin_slug() . '_license_key', $license_key);
        $this->license_key = $license_key;
    }
    public function get_license_key()
    {
        return get_option($this->adapter->get_plugin_slug() . '_license_key');
    }
    public function get_license_info(): array
    {
        $default = ['expires' => 'Not updated'];
        $info = get_option($this->adapter->get_plugin_slug() . '_license_info');
        $info = is_string($info) ? json_decode($info, \true) : $info;
        return $info ?: $default;
    }
    public function remove_license_key(): void
    {
        delete_option($this->adapter->get_plugin_slug() . '_license_key');
        $this->license_key = null;
    }
    public function remove_license_info(): void
    {
        delete_option($this->adapter->get_plugin_slug() . '_license_info');
        $this->license_info = ['expires' => 'Not updated'];
    }
    public function activate(string $license_key): array
    {
        $activate_response = LicenseAPI::activate_license($this->adapter->get_store_url(), $this->adapter->get_item_id(), $license_key);
        if ($activate_response['success']) {
            $this->update_license_key($license_key);
            $this->update_license_info($activate_response);
        }
        LicenseHandler::remove_site_plugin_check($this->adapter);
        return $activate_response;
    }
    public function update(): array
    {
        $license_key = $this->get_license_key();
        $response = LicenseAPI::check_license($this->adapter->get_store_url(), $this->adapter->get_item_id(), $license_key);
        if ($response['success']) {
            $this->update_license_info($response);
        } elseif (self::reports_expired($response)) {
            // Expired keeps the key: features keep working, updates and support stop.
            $this->mark_expired($response);
        } elseif (empty($response['is_server_error'])) {
            $this->remove();
        }
        LicenseHandler::remove_site_plugin_check($this->adapter);
        return $response;
    }
    public function remove(): void
    {
        // Deactivate on EDD side to free the activation slot
        $license_key = $this->get_license_key();
        if (!empty($license_key)) {
            LicenseAPI::deactivate_license($this->adapter->get_store_url(), $this->adapter->get_item_id(), $license_key);
        }
        LicenseHandler::remove_site_plugin_check($this->adapter);
        $this->remove_license_key();
        $this->remove_license_info();
    }
    public function is_active(): bool
    {
        return (bool) $this->license_key;
    }
    public function is_expired(): bool
    {
        if ($this->is_active()) {
            return LicenseState::is_past_expiry($this->get_license_info()['expires'] ?? null);
        }
        return \false;
    }
    /**
     * Whether a failed EDD check says the key is expired (EDD puts it in 'license' or 'error').
     */
    private static function reports_expired(array $response): bool
    {
        return 'expired' === ($response['license'] ?? '') || 'expired' === ($response['error'] ?? '');
    }
    /**
     * Store the expired status on the existing license info, keeping the key.
     */
    private function mark_expired(array $response): void
    {
        $info = $this->get_license_info();
        $info['license'] = 'expired';
        if (!empty($response['expires'])) {
            $info['expires'] = $response['expires'];
        }
        $this->update_license_info($info);
    }
    public function format_license_key(int $group = 8, string $separator = '-', int $hidden = 20): string
    {
        $key = (string) $this->license_key;
        $len = strlen($key);
        if ($len <= $hidden) {
            return $key;
        }
        for ($i = $len - $hidden; $i < $len; $i++) {
            $key[$i] = '*';
        }
        $formatted = '';
        for ($i = 0; $i < $len; $i++) {
            $formatted .= $key[$i];
            if (0 === ($i + 1) % $group && $i + 1 >= $group && $i !== $len - 1) {
                $formatted .= $separator;
            }
        }
        return $formatted;
    }
    public function get_renewal_url(): string
    {
        return $this->adapter->get_store_url() . 'checkout/?edd_license_key=' . $this->license_key . '&download_id=' . $this->adapter->get_item_id();
    }
    public function get_upgrade_url(): string
    {
        $payment_id = $this->license_info['payment_id'] ?? '';
        return $this->adapter->get_store_url() . 'checkout/purchase-history/?view=upgrades&action=manage_licenses&payment_id=' . $payment_id;
    }
}
