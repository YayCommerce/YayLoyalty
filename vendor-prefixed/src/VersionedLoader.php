<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell;

/**
 * Highest-version-wins runtime election for the admin-shell package.
 *
 * When multiple plugins include different versions of this package
 * (before PHP-Scoper prefixing is applied), each plugin registers its
 * version during 'plugins_loaded'. After 'plugins_loaded', the highest
 * registered version wins — only that version's boot() runs.
 *
 * Usage (in each consuming plugin's main file):
 *   add_action('plugins_loaded', function() {
 *       \YayCommerce\AdminShell\VersionedLoader::register('1.2.0', function() {
 *           \YayCommerce\AdminShell\AdminShell::boot();
 *       });
 *   }, 0);
 */
class VersionedLoader
{
    /** @var array<string, callable> version => boot callback */
    private static array $candidates = [];
    /** @var bool Whether election has already run */
    private static bool $elected = \false;
    /**
     * Register this package version as a candidate to run the boot callback.
     *
     * If two plugins register the same version string, the FIRST registration
     * wins (deterministic first-wins, not last-wins). This prevents silent
     * callback replacement when two plugins ship identical admin-shell versions.
     *
     * @param string   $version  Semver string, e.g. '1.2.0'
     * @param callable $callback Boot callback — called only if this version wins.
     */
    public static function register(string $version, callable $callback): void
    {
        // First-wins: do not overwrite an already-registered version.
        if (isset(self::$candidates[$version])) {
            return;
        }
        self::$candidates[$version] = $callback;
        // Schedule election to run after all plugins_loaded callbacks.
        // We use priority 999 so all candidates at default priority register first.
        if (!self::$elected) {
            add_action('plugins_loaded', [self::class, 'elect'], 999);
        }
    }
    /**
     * Run after all plugins_loaded hooks. Pick highest version and invoke its callback.
     * Idempotent — second call is a no-op.
     */
    public static function elect(): void
    {
        if (self::$elected || empty(self::$candidates)) {
            return;
        }
        self::$elected = \true;
        $winner = null;
        foreach (array_keys(self::$candidates) as $version) {
            if ($winner === null || version_compare($version, $winner, '>')) {
                $winner = $version;
            }
        }
        if ($winner !== null && isset(self::$candidates[$winner])) {
            self::$candidates[$winner]();
        }
    }
    /**
     * Return the winning version string, or null if election hasn't run.
     */
    public static function get_elected_version(): ?string
    {
        if (!self::$elected || empty(self::$candidates)) {
            return null;
        }
        $winner = null;
        foreach (array_keys(self::$candidates) as $version) {
            if ($winner === null || version_compare($version, $winner, '>')) {
                $winner = $version;
            }
        }
        return $winner;
    }
    /**
     * Reset state — only for unit tests.
     */
    public static function reset(): void
    {
        self::$candidates = [];
        self::$elected = \false;
    }
}
