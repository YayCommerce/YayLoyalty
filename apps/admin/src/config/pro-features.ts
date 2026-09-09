/**
 * Catalogue of UI surfaces that are pro.
 *
 * KEPT IN THE LITE BUILD — Lite needs it to know which nav items and controls
 * to badge as upgrades. It describes *surfaces*, not entitlements: there is no
 * per-feature licensing here, and if tiered pricing ever appears that is a
 * different mechanism entirely.
 *
 * Declaring them in one place stops `isPro` booleans scattering through
 * components, and gives the Lite build a single list to render upsells from.
 */

export const PRO_FEATURES = {
  /** Campaign triggers beyond purchase / signup / review. */
  campaignTriggers: 'campaign-triggers',
  /** Per-order and per-day point caps. */
  campaignCaps: 'campaign-caps',
  /** Eligibility rules evaluated against the customer. */
  campaignConditions: 'campaign-conditions',
} as const;

export type ProFeature = (typeof PRO_FEATURES)[keyof typeof PRO_FEATURES];

/**
 * Human labels for the upgrade prompts.
 *
 * User-facing copy says "Pro" even though the code says Pro — see
 * docs/code-standards.md.
 */
export const PRO_FEATURE_LABELS: Record<ProFeature, string> = {
  [PRO_FEATURES.campaignTriggers]: 'Additional earning triggers',
  [PRO_FEATURES.campaignCaps]: 'Point caps',
  [PRO_FEATURES.campaignConditions]: 'Eligibility conditions',
};
