import * as v from 'valibot';

import { campaignTriggerSchema } from './campaign.schema';
import { dashboardRangeSchema } from './dashboard.schema';

/** Mirrors DashboardProRestController, the source of truth. */

export const dashboardCampaignSeriesPointSchema = v.object({
  date: v.string(),
  campaign_id: v.pipe(v.number(), v.integer()),
  issued: v.pipe(v.number(), v.integer()),
});

export type DashboardCampaignSeriesPoint = v.InferOutput<typeof dashboardCampaignSeriesPointSchema>;

/** Enough of the campaign row to compose the meta line client-side; no server labels. */
export const dashboardTopCampaignSchema = v.object({
  campaign_id: v.pipe(v.number(), v.integer()),
  name: v.string(),
  /** Issued in the window, earn-set rows carrying this campaign_id. */
  points: v.pipe(v.number(), v.integer()),
  trigger: campaignTriggerSchema,
  priority: v.pipe(v.number(), v.integer()),
  config: v.record(v.string(), v.unknown()),
  conditions: v.union([v.record(v.string(), v.unknown()), v.array(v.unknown())]),
});

export type DashboardTopCampaign = v.InferOutput<typeof dashboardTopCampaignSchema>;

export const dashboardProResponseSchema = v.object({
  range: dashboardRangeSchema,
  /** Zero-filled per (day, campaign) for the top campaigns. */
  campaign_series: v.array(dashboardCampaignSeriesPointSchema),
  /** Max 3, points desc. */
  top_campaigns: v.array(dashboardTopCampaignSchema),
});

export type DashboardProResponse = v.InferOutput<typeof dashboardProResponseSchema>;
