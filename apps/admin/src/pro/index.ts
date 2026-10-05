/**
 * The only import surface for src/pro/, which is tree-shaken out of the
 * Lite bundle. Never deep-import `@/pro/...` from shared code: it survives
 * into Lite, which the sentinel below detects. `@/components/pro/` is
 * different and is kept in Lite.
 */

/**
 * Marker validate-build.php greps for: absent from Lite proves tree-shaking,
 * present in Pro proves the check is not vacuous.
 */
export const PRO_BUNDLE_SENTINEL = 'yayloyalty-pro-bundle-a7f3c1';

/**
 * Consumers must guard the JSX with IS_PRO_BUILD. <ProGate> alone is not
 * enough: JSX evaluates children eagerly and drags the subtree into Lite.
 */
export { ConditionsPreview } from './campaigns/ConditionsPreview';
export { CampaignTemplatePicker } from './campaigns/add/CampaignTemplatePicker';
export { CampaignTable } from './campaigns/CampaignTable';
export { CampaignCreator, CampaignEditor } from './campaigns/editor/CampaignEditor';
export { CampaignPerformance } from './campaigns/performance/CampaignPerformance';
export { CustomersSavedSegmentRow } from './customers/CustomersSavedSegmentRow';
export { DashboardProPanels } from './dashboard/DashboardProPanels';
export { DesignPanelSection } from './design/DesignPanelSection';
export { DesignTierSection } from './design/DesignTierSection';
export { DesignTypographySection } from './design/DesignTypographySection';
export { AdvancedFraudLimitsForm } from './settings/AdvancedFraudLimitsForm';

/** Keeps the sentinel reachable code rather than a literal a minifier may discard. */
export function proBundleMarker(): string {
  return PRO_BUNDLE_SENTINEL;
}

/**
 * Pro's entry point, called from main.tsx behind the build flag. Registers
 * only the sentinel, which keeps it reachable in a Pro build.
 */
export function registerPro(): void {
  // A window assignment stops a minifier from treating the call as removable.
  (window as unknown as Record<string, string>).__yayLoyaltyPro = proBundleMarker();
}
