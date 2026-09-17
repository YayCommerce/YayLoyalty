/**
 * Pro features — the ONLY import surface for this directory.
 *
 * THIS ENTIRE DIRECTORY IS TREE-SHAKEN OUT OF THE LITE BUNDLE (nothing under
 * apps/ ships; run.sh deletes only includes/Pro).
 *
 * Do not deep-import from `@/pro/...` anywhere in shared code. Everything
 * must come through this barrel, imported behind the build-plan constant, so the
 * bundler can drop the whole subtree when the constant is false. A deep import
 * from a shared file defeats tree-shaking and survives into the Lite bundle —
 * which is exactly what the sentinel below is there to detect.
 *
 * Not to be confused with `@/components/pro/`, which is KEPT in the Lite
 * build because Lite still has to render the upgrade badge and overlay.
 */

/**
 * Marker string proving whether pro code reached a bundle.
 *
 * scripts/validate-build.php greps the built main.js for this:
 *   - absent from the Lite bundle  → tree-shaking worked
 *   - present in the Pro bundle → the check is not vacuous
 *
 * The second assertion matters as much as the first. A sentinel accidentally
 * eliminated from BOTH builds would make the Lite assertion pass forever while
 * proving nothing.
 *
 * Referenced by proBundleMarker() below so it cannot be dropped as unused.
 */
export const PRO_BUNDLE_SENTINEL = 'yayloyalty-pro-bundle-a7f3c1';

/**
 * Pro surfaces, re-exported so shared pages import them from here and never
 * deep-import from `@/pro/...`.
 *
 * A consumer must still guard the JSX with `IS_PRO_BUILD`. Wrapping a pro
 * component in <ProGate> alone is NOT enough: JSX evaluates its children
 * eagerly, so `<ProGate><ConditionsPreview /></ProGate>` references the
 * component at runtime and drags this whole subtree into the Lite bundle.
 * See CampaignsPage for the pattern that works.
 */
export { ConditionsPreview } from './campaigns/ConditionsPreview';
export { CampaignTable } from './campaigns/CampaignTable';
export { CustomersSavedSegmentRow } from './customers/CustomersSavedSegmentRow';
export { DashboardProPanels } from './dashboard/DashboardProPanels';

/**
 * Returns the sentinel. Exists so the constant is genuinely reachable code
 * rather than an unreferenced literal a minifier is free to discard.
 */
export function proBundleMarker(): string {
  return PRO_BUNDLE_SENTINEL;
}

/**
 * Pro's SPA entry point, called from main.tsx behind
 * `import.meta.env.VITE_IS_PRO === 'true'` (read directly there, not via
 * `IS_PRO_BUILD` — see main.tsx's comment for why).
 *
 * The one place pro code becomes reachable from the app, which is what
 * makes the whole subtree tree-shakeable: in a Lite build the call site is
 * eliminated, this module has no importer, and Rollup drops it entirely.
 *
 * Registers nothing beyond the sentinel: Pro surfaces are reached through
 * the barrel exports behind IS_PRO_BUILD at each call site. It is not dead
 * weight even so: it keeps the sentinel genuinely reachable in a Pro build,
 * without which validate-build.php's "absent from Lite" assertion would
 * pass vacuously.
 */
export function registerPro(): void {
  // Referencing the marker keeps it in the Pro bundle. Assigning to a
  // window property rather than discarding the value stops a minifier from
  // treating the whole call as removable.
  (window as unknown as Record<string, string>).__yayLoyaltyPro = proBundleMarker();
}
