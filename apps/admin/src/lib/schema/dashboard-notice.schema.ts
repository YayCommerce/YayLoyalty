import * as v from 'valibot';

import { type DashboardNotice, type DashboardNoticeKey } from './dashboard.schema';

/**
 * Mirrors DashboardNotices, the source of truth.
 * These are the per-key `notice.data` shapes.
 */

export const negativeBalanceDataSchema = v.object({
  count: v.pipe(v.number(), v.integer()),
});

export const defaultCampaignPausedDataSchema = v.object({
  campaign_id: v.pipe(v.number(), v.integer()),
  /** UTC MySQL datetime of the pause (the campaign's `updated_at`). */
  paused_since: v.string(),
  /** Completed orders since the pause minus orders that still earned. */
  orders_without_points: v.pipe(v.number(), v.integer()),
});

export const pointsJumpWhatChangedSchema = v.object({
  campaign_id: v.pipe(v.number(), v.integer()),
  name: v.string(),
  updated_at: v.string(),
  /** '' when the editor is unknown. */
  updated_by_name: v.string(),
  orders_affected: v.pipe(v.number(), v.integer()),
  points_issued: v.pipe(v.number(), v.integer()),
});

export const pointsJumpDataSchema = v.object({
  /** Site-timezone `Y-m-d`. */
  yesterday: v.string(),
  yesterday_points: v.pipe(v.number(), v.integer()),
  daily_average: v.pipe(v.number(), v.integer()),
  multiple: v.number(),
  /** 13 days ending yesterday. */
  daily: v.array(v.object({ date: v.string(), issued: v.pipe(v.number(), v.integer()) })),
  threshold: v.object({ multiple: v.number(), min_orders: v.pipe(v.number(), v.integer()) }),
  /** WooCommerce Orders list filtered to the month; the day is named in the link text. */
  orders_admin_url: v.string(),
  /** May be empty: the jump is real regardless of cause. Who edited when, never what. */
  what_changed: v.array(pointsJumpWhatChangedSchema),
});

export type NegativeBalanceData = v.InferOutput<typeof negativeBalanceDataSchema>;
export type DefaultCampaignPausedData = v.InferOutput<typeof defaultCampaignPausedDataSchema>;
export type PointsJumpData = v.InferOutput<typeof pointsJumpDataSchema>;
export type PointsJumpWhatChanged = v.InferOutput<typeof pointsJumpWhatChangedSchema>;

export type TypedNotice =
  | { key: 'negative_balance'; data: NegativeBalanceData }
  | { key: 'default_campaign_paused'; data: DefaultCampaignPausedData }
  | { key: 'points_jump'; data: PointsJumpData };

const DATA_SCHEMAS = {
  negative_balance: negativeBalanceDataSchema,
  default_campaign_paused: defaultCampaignPausedDataSchema,
  points_jump: pointsJumpDataSchema,
} as const;

/** Narrows a wire notice to its per-key data; null on mismatch so the strip skips it rather than white-screening. */
export function parseNoticeData(notice: DashboardNotice): TypedNotice | null {
  const schema = isKnownKey(notice.key) ? DATA_SCHEMAS[notice.key] : null;
  const result = schema ? v.safeParse(schema, notice.data) : null;

  return result?.success ? ({ key: notice.key, data: result.output } as TypedNotice) : null;
}

function isKnownKey(key: string): key is DashboardNoticeKey {
  return key in DATA_SCHEMAS;
}

/** The points_jump notice, if present and well-formed. */
export function findPointsJump(notices: DashboardNotice[]): { notice: DashboardNotice; data: PointsJumpData } | null {
  const notice = notices.find((n) => n.key === 'points_jump');
  const parsed = notice ? v.safeParse(pointsJumpDataSchema, notice.data) : null;

  return notice && parsed?.success ? { notice, data: parsed.output } : null;
}
