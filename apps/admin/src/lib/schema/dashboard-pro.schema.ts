import { z } from 'zod';

import { campaignTriggerSchema } from './campaign.schema';
import { dashboardRangeSchema } from './dashboard.schema';

/**
 * Mirrors the Pro-only DashboardProRestController (`GET /dashboard/pro`,
 * rest base `dashboard-pro`). THAT PHP CLASS IS THE SOURCE OF TRUTH; the
 * contract is frozen in `plans/260916-1558-admin-dashboard-page/plan.md`.
 *
 * `referrals` / `tier_distribution` are `null` until their tables exist; the
 * Pro SPA substitutes `pro/dashboard/dashboard-pro-dummy.ts` for a null.
 */

/** `null` until the referrals table exists — the SPA substitutes its dummy. */
export const dashboardReferralsSchema = z
  .object({
    completed: z.number().int(),
    prev_delta_pct: z.number().nullable(),
  })
  .nullable();

export type DashboardReferrals = z.infer<typeof dashboardReferralsSchema>;

/** `null` until the tiers table exists — the SPA substitutes its dummy. */
export const dashboardTierDistributionSchema = z
  .array(
    z.object({
      name: z.string(),
      members: z.number().int(),
    }),
  )
  .nullable();

export type DashboardTierDistribution = z.infer<typeof dashboardTierDistributionSchema>;

export const dashboardCampaignSeriesPointSchema = z.object({
  date: z.string(),
  campaign_id: z.number().int(),
  issued: z.number().int(),
});

export type DashboardCampaignSeriesPoint = z.infer<typeof dashboardCampaignSeriesPointSchema>;

/**
 * Enough of the campaign row for the SPA to compose its meta line with
 * `givesLabel()` / `conditionsLabel()` (pro/campaigns/campaign-labels.ts) —
 * no server-composed labels. Field is `trigger` (wire), not the DB column
 * `trigger_type`; see Models\Campaign.
 */
export const dashboardTopCampaignSchema = z.object({
  campaign_id: z.number().int(),
  name: z.string(),
  /** Issued in the window, earn-set rows carrying this campaign_id. */
  points: z.number().int(),
  trigger: campaignTriggerSchema,
  priority: z.number().int(),
  config: z.record(z.string(), z.unknown()),
  conditions: z.union([z.record(z.string(), z.unknown()), z.array(z.unknown())]),
});

export type DashboardTopCampaign = z.infer<typeof dashboardTopCampaignSchema>;

export const dashboardProResponseSchema = z.object({
  range: dashboardRangeSchema,
  referrals: dashboardReferralsSchema,
  tier_distribution: dashboardTierDistributionSchema,
  /** Zero-filled per (day, campaign) for the top campaigns. */
  campaign_series: z.array(dashboardCampaignSeriesPointSchema),
  /** Max 3, points desc. */
  top_campaigns: z.array(dashboardTopCampaignSchema),
});

export type DashboardProResponse = z.infer<typeof dashboardProResponseSchema>;
