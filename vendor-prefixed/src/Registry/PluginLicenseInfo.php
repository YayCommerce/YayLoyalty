<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Registry;

/**
 * Frozen data contract for a registered plugin's license state.
 *
 * APPEND-ONLY — existing public properties must never be renamed or removed.
 * New properties may be added in MINOR versions; removing/renaming requires MAJOR bump.
 */
final class PluginLicenseInfo
{
    /** Plugin slug — byte-exact wp_options prefix. */
    public string $slug = '';
    /** Human-readable display name. */
    public string $name = '';
    /** Plugin version string. */
    public string $version = '';
    /** WP plugin basename, e.g. 'yaymail-pro/yaymail.php'. */
    public string $basename = '';
    /** EDD download ID. */
    public int $item_id = 0;
    /** Product page URL. */
    public string $store_link = '';
    /**
     * License key (raw). Redacted when serialized via toArray().
     * Never log or expose this field directly.
     */
    public string $license_key = '';
    /**
     * License status: 'valid'|'invalid'|'expired'|'inactive'|'disabled'|'unknown'
     */
    public string $status = 'unknown';
    /** Expiry in ISO 8601 format, 'lifetime', or null if not retrieved. */
    public ?string $expires_at = null;
    /** Number of activations used. */
    public int $activations_used = 0;
    /** Maximum activations allowed (0 = unlimited). */
    public int $activations_limit = 0;
    /** True when sourced via LegacyBridge (yaycommerce_licensing_plugins filter). */
    public bool $is_legacy = \false;
    /** Raw EDD response blob for debugging. Never expose to end-users. */
    public array $raw_info = [];
    /**
     * Return array representation suitable for passing to JS/REST.
     * Redacts license_key to first 8 chars + asterisks.
     */
    public function toArray(): array
    {
        $key_display = '';
        if (!empty($this->license_key)) {
            $visible = substr($this->license_key, 0, 8);
            $hidden_len = max(0, strlen($this->license_key) - 8);
            $key_display = $visible . str_repeat('*', min($hidden_len, 20));
        }
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'basename' => $this->basename,
            'item_id' => $this->item_id,
            'store_link' => $this->store_link,
            'license_key' => $key_display,
            // redacted
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'activations_used' => $this->activations_used,
            'activations_limit' => $this->activations_limit,
            'is_legacy' => $this->is_legacy,
        ];
    }
}
