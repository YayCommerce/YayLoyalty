import { z } from 'zod';

import { campaignTriggerSchema } from './campaign.schema';
import { dashboardRangeSchema } from './dashboard.schema';

/** Mirrors DashboardProRestController, the source of truth. */

export const dashboardCampaignSeriesPointSchema = z.object({
  date: z.string(),
  campaign_id: z.number().int(),
  issued: z.number().int(),
});

export type DashboardCampaignSeriesPoint = z.infer<typeof dashboardCampaignSeriesPointSchema>;

/** Enough of the campaign row to compose the meta line client-side; no server labels. */
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
  /** Zero-filled per (day, campaign) for the top campaigns. */
  campaign_series: z.array(dashboardCampaignSeriesPointSchema),
  /** Max 3, points desc. */
  top_campaigns: z.array(dashboardTopCampaignSchema),
});

export type DashboardProResponse = z.infer<typeof dashboardProResponseSchema>;
