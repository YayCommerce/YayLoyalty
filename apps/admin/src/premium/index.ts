/**
 * Premium features — the ONLY import surface for this directory.
 *
 * THIS ENTIRE DIRECTORY IS DELETED FOR THE LITE BUILD.
 *
 * Do not deep-import from `@/premium/...` anywhere in shared code. Everything
 * must come through this barrel, imported behind `__IS_PREMIUM__`, so the
 * bundler can drop the whole subtree when the constant is false. A deep import
 * from a shared file defeats tree-shaking and survives into the Lite bundle —
 * which is exactly what the sentinel below is there to detect.
 *
 * Not to be confused with `@/components/premium/`, which is KEPT in the Lite
 * build because Lite still has to render the upgrade badge and overlay.
 */

/**
 * Marker string proving whether premium code reached a bundle.
 *
 * scripts/validate-build.php greps the built main.js for this:
 *   - absent from the Lite bundle  → tree-shaking worked
 *   - present in the Premium bundle → the check is not vacuous
 *
 * The second assertion matters as much as the first. A sentinel accidentally
 * eliminated from BOTH builds would make the Lite assertion pass forever while
 * proving nothing.
 *
 * Referenced by premiumBundleMarker() below so it cannot be dropped as unused.
 */
export const PREMIUM_BUNDLE_SENTINEL = 'yayloyalty-premium-bundle-a7f3c1';

/**
 * Returns the sentinel. Exists so the constant is genuinely reachable code
 * rather than an unreferenced literal a minifier is free to discard.
 */
export function premiumBundleMarker(): string {
  return PREMIUM_BUNDLE_SENTINEL;
}

/**
 * Premium's SPA entry point, called from main.tsx behind `__IS_PREMIUM__`.
 *
 * The one place premium code becomes reachable from the app, which is what
 * makes the whole subtree tree-shakeable: in a Lite build the call site is
 * eliminated, this module has no importer, and Rollup drops it entirely.
 *
 * Currently registers nothing — the premium campaign surfaces land in a later
 * phase. It is not dead weight even so: it keeps the sentinel genuinely
 * reachable in a Premium build, without which validate-build.php's "absent from
 * Lite" assertion would pass vacuously.
 */
export function registerPremium(): void {
  // Referencing the marker keeps it in the Premium bundle. Assigning to a
  // window property rather than discarding the value stops a minifier from
  // treating the whole call as removable.
  ( window as unknown as Record<string, string> ).__yayLoyaltyPremium = premiumBundleMarker();
}
