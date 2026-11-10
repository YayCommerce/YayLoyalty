<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\License;

defined('ABSPATH') || exit;
/**
 * EDD HTTP client — all calls go through wp_remote_get.
 *
 * Ported from YayMail Pro LicenseAPI.php — YAYCOMMERCE_SELLER_SITE_URL constant
 * replaced with $store_url parameter sourced from LicenseConfigAdapter::get_store_url().
 */
class LicenseAPI
{
    public static function activate_license(string $store_url, int $item_id, string $license_key): array
    {
        try {
            $url = $store_url . '?edd_action=activate_license&item_id=' . $item_id . '&license=' . rawurlencode($license_key) . '&url=' . home_url();
            $raw = wp_remote_get($url);
            if (is_wp_error($raw)) {
                throw new \Error('WP HTTP error', 1);
            }
            $response = json_decode(wp_remote_retrieve_body($raw));
            if (isset($response->success) && $response->success) {
                return ['success' => \true, 'license' => $response->license ?? 'valid', 'expires' => $response->expires ?? null, 'license_limit' => $response->license_limit ?? 0, 'payment_id' => $response->payment_id ?? '', 'customer_name' => $response->customer_name ?? ''];
            }
            return ['success' => \false, 'message' => $response->error ?? 'unknown_error'];
        } catch (\Error $error) {
            return ['success' => \false, 'message' => 'server_error', 'is_server_error' => \true];
        }
    }
    public static function deactivate_license(string $store_url, int $item_id, string $license_key): array
    {
        try {
            $url = $store_url . '?edd_action=deactivate_license&item_id=' . $item_id . '&license=' . rawurlencode($license_key) . '&url=' . home_url();
            $raw = wp_remote_get($url);
            if (is_wp_error($raw)) {
                return ['success' => \false, 'is_server_error' => \true];
            }
            $response = json_decode(wp_remote_retrieve_body($raw));
            return ['success' => isset($response->success) && $response->success, 'license' => $response->license ?? 'deactivated'];
        } catch (\Error $error) {
            return ['success' => \false, 'is_server_error' => \true];
        }
    }
    public static function check_license(string $store_url, int $item_id, $license_key): array
    {
        try {
            $url = $store_url . '?edd_action=check_license&item_id=' . $item_id . '&license=' . rawurlencode((string) $license_key) . '&url=' . home_url();
            $raw = wp_remote_get($url);
            if (is_wp_error($raw)) {
                throw new \Error('WP HTTP error', 1);
            }
            $response = json_decode(wp_remote_retrieve_body($raw));
            if (isset($response->success) && \true === $response->success) {
                if ('valid' === $response->license || 'expired' === $response->license) {
                    return ['success' => \true, 'license' => $response->license, 'expires' => $response->expires ?? null, 'license_limit' => $response->license_limit ?? 0, 'payment_id' => $response->payment_id ?? '', 'customer_name' => $response->customer_name ?? ''];
                }
            }
            return ['success' => \false, 'license' => isset($response->license) ? (string) $response->license : '', 'error' => isset($response->error) ? (string) $response->error : '', 'expires' => $response->expires ?? null];
        } catch (\Error $error) {
            return ['success' => \false, 'is_server_error' => \true];
        }
    }
    public static function get_version(string $store_url, int $item_id, ?string $license_key = null)
    {
        try {
            $url = $store_url . '?edd_action=get_version&item_id=' . $item_id;
            if (!empty($license_key)) {
                $url .= '&license=' . rawurlencode($license_key);
            }
            $raw = wp_remote_get($url);
            $response = json_decode(wp_remote_retrieve_body($raw));
            if (isset($response->new_version)) {
                return (array) $response;
            }
            return \false;
        } catch (\Error $error) {
            return \false;
        }
    }
    public static function get_error_message(string $message): string
    {
        $messages = ['missing' => "License doesn't exist", 'license_not_activable' => "Attempting to activate a bundle's parent license", 'disabled' => 'License key revoked', 'no_activations_left' => 'No activations left', 'expired' => 'License has expired', 'key_mismatch' => 'License is not valid for this product', 'item_name_mismatch' => 'License is not valid for this product', 'server_error' => 'Your license could not be activated because of server error.'];
        return $messages[$message] ?? 'Your license could not be activated.';
    }
}
