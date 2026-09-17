import { z } from 'zod';

import { type DashboardNotice, type DashboardNoticeKey } from './dashboard.schema';

/** Per-key notice.data shapes, mirroring DashboardNotices and the fixture-only keys. Copy is composed client-side. */

export const negativeBalanceDataSchema = z.object({
  count: z.number().int(),
});

export const defaultCampaignPausedDataSchema = z.object({
  campaign_id: z.number().int(),
  /** UTC MySQL datetime of the pause (the campaign's `updated_at`). */
  paused_since: z.string(),
  /** Completed orders since the pause minus orders that still earned. */
  orders_without_points: z.number().int(),
});

export const pointsJumpWhatChangedSchema = z.object({
  campaign_id: z.number().int(),
  name: z.string(),
  updated_at: z.string(),
  /** '' when the editor is unknown. */
  updated_by_name: z.string(),
  orders_affected: z.number().int(),
  points_issued: z.number().int(),
});

export const pointsJumpDataSchema = z.object({
  /** Site-timezone `Y-m-d`. */
  yesterday: z.string(),
  yesterday_points: z.number().int(),
  daily_average: z.number().int(),
  multiple: z.number(),
  /** 13 days ending yesterday. */
  daily: z.array(z.object({ date: z.string(), issued: z.number().int() })),
  threshold: z.object({ multiple: z.number(), min_orders: z.number().int() }),
  /** WooCommerce Orders list filtered to the month; the day is named in the link text. */
  orders_admin_url: z.string(),
  /** May be empty: the jump is real regardless of cause. Who edited when, never what. */
  what_changed: z.array(pointsJumpWhatChangedSchema),
});

export const mailFailuresDataSchema = z.object({
  failed: z.number().int(),
  since: z.string(),
});

export const balanceRebuildDataSchema = z.object({
  processed: z.number().int(),
  total: z.number().int(),
});

export type NegativeBalanceData = z.infer<typeof negativeBalanceDataSchema>;
export type DefaultCampaignPausedData = z.infer<typeof defaultCampaignPausedDataSchema>;
export type PointsJumpData = z.infer<typeof pointsJumpDataSchema>;
export type PointsJumpWhatChanged = z.infer<typeof pointsJumpWhatChangedSchema>;
export type MailFailuresData = z.infer<typeof mailFailuresDataSchema>;
export type BalanceRebuildData = z.infer<typeof balanceRebuildDataSchema>;

export type TypedNotice =
  | { key: 'negative_balance'; data: NegativeBalanceData }
  | { key: 'default_campaign_paused'; data: DefaultCampaignPausedData }
  | { key: 'points_jump'; data: PointsJumpData }
  | { key: 'mail_failures'; data: MailFailuresData }
  | { key: 'balance_rebuild'; data: BalanceRebuildData };

const DATA_SCHEMAS = {
  negative_balance: negativeBalanceDataSchema,
  default_campaign_paused: defaultCampaignPausedDataSchema,
  points_jump: pointsJumpDataSchema,
  mail_failures: mailFailuresDataSchema,
  balance_rebuild: balanceRebuildDataSchema,
} as const;

/** Narrows a wire notice to its per-key data; null on mismatch so the strip skips it rather than white-screening. */
export function parseNoticeData(notice: DashboardNotice): TypedNotice | null {
  const schema = isKnownKey(notice.key) ? DATA_SCHEMAS[notice.key] : null;
  const result = schema?.safeParse(notice.data);

  return result?.success ? ({ key: notice.key, data: result.data } as TypedNotice) : null;
}

function isKnownKey(key: string): key is DashboardNoticeKey {
  return key in DATA_SCHEMAS;
}

/** The points_jump notice, if present and well-formed. */
export function findPointsJump(notices: DashboardNotice[]): { notice: DashboardNotice; data: PointsJumpData } | null {
  const notice = notices.find((n) => n.key === 'points_jump');
  const parsed = notice ? pointsJumpDataSchema.safeParse(notice.data) : null;

  return notice && parsed?.success ? { notice, data: parsed.data } : null;
}
