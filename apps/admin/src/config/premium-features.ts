/**
 * Catalogue of UI surfaces that are premium.
 *
 * KEPT IN THE LITE BUILD — Lite needs it to know which nav items and controls
 * to badge as upgrades. It describes *surfaces*, not entitlements: there is no
 * per-feature licensing here, and if tiered pricing ever appears that is a
 * different mechanism entirely.
 *
 * Declaring them in one place stops `isPremium` booleans scattering through
 * components, and gives the Lite build a single list to render upsells from.
 */

export const PREMIUM_FEATURES = {
  /** Campaign triggers beyond purchase / signup / review. */
  campaignTriggers: 'campaign-triggers',
  /** Per-order and per-day point caps. */
  campaignCaps: 'campaign-caps',
  /** Eligibility rules evaluated against the customer. */
  campaignConditions: 'campaign-conditions',
} as const;

export type PremiumFeature = (typeof PREMIUM_FEATURES)[keyof typeof PREMIUM_FEATURES];

/**
 * Human labels for the upgrade prompts.
 *
 * User-facing copy says "Pro" even though the code says Premium — see
 * docs/code-standards.md.
 */
export const PREMIUM_FEATURE_LABELS: Record<PremiumFeature, string> = {
  [PREMIUM_FEATURES.campaignTriggers]: 'Additional earning triggers',
  [PREMIUM_FEATURES.campaignCaps]: 'Point caps',
  [PREMIUM_FEATURES.campaignConditions]: 'Eligibility conditions',
};
