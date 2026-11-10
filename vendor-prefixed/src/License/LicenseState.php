<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\License;

defined('ABSPATH') || exit;
/**
 * What a stored license allows: the licensing policy, in one place.
 *
 * An expired license stops updates and support. Pro features keep working.
 * Features stop only when no key was activated on this site, or the key was
 * revoked ('disabled').
 *
 * Plugin adapters call allows_features() from their static is_licensed(), and
 * the plugins gate their Pro code on that.
 */
final class LicenseState
{
    /** Stored license statuses that switch Pro features off. */
    const BLOCKING_STATUSES = ['disabled'];
    /**
     * Pro features may run: a key was activated on this site and has not been revoked.
     */
    public static function allows_features(string $slug): bool
    {
        $key = get_option($slug . '_license_key', '');
        $info = self::read_info($slug);
        if (empty($key) || empty($info)) {
            return \false;
        }
        return !in_array($info['license'] ?? 'valid', self::BLOCKING_STATUSES, \true);
    }
    /**
     * Updates and support: features are allowed and the license is not past its expiry date.
     */
    public static function allows_updates(string $slug): bool
    {
        return self::allows_features($slug) && !self::is_past_expiry(self::read_info($slug)['expires'] ?? null);
    }
    /**
     * Whether an EDD 'expires' value is in the past. 'lifetime', 'Not updated' and empty never expire.
     *
     * @param mixed $expires
     */
    public static function is_past_expiry($expires): bool
    {
        if (!is_string($expires) || '' === $expires || 'lifetime' === $expires || 'Not updated' === $expires) {
            return \false;
        }
        return strtotime($expires) < time();
    }
    /**
     * The stored {slug}_license_info, decoded when it was saved as a JSON string.
     */
    private static function read_info(string $slug): array
    {
        $info = get_option($slug . '_license_info', []);
        if (is_string($info)) {
            $info = json_decode($info, \true);
        }
        return is_array($info) ? $info : [];
    }
}
