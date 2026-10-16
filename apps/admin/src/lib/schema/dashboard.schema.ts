import * as v from 'valibot';

import { ledgerEntrySchema } from './ledger.schema';

/**
 * Mirrors DashboardRestController::define_item_schema(), the source of truth.
 * A zero denominator yields null, never 0: null renders as "—" or "no previous period".
 * Notices carry no copy. dashboard-page.notice-copy.ts composes it from key and data.
 */

export const DASHBOARD_RANGES = [7, 30, 90] as const;

export const dashboardRangeSchema = v.union([v.literal(7), v.literal(30), v.literal(90)]);

export type DashboardRange = v.InferOutput<typeof dashboardRangeSchema>;

/** Percentage change vs the previous window; null = nothing to compare against. */
const prevDeltaPct = v.nullable(v.number());

export const dashboardStatSchema = v.object({
  value: v.pipe(v.number(), v.integer()),
  prev_delta_pct: prevDeltaPct,
});

export type DashboardStat = v.InferOutput<typeof dashboardStatSchema>;

export const dashboardLiabilitySchema = v.object({
  /** SUM(balance) over positive balances. */
  points: v.pipe(v.number(), v.integer()),
  prev_delta_pct: prevDeltaPct,
});

export type DashboardLiability = v.InferOutput<typeof dashboardLiabilitySchema>;

export const dashboardRateSchema = v.object({
  /** `orders_with_points / orders_total` as a percentage, 1 dp; `null` when no orders. */
  rate: v.nullable(v.number()),
  orders_with_points: v.pipe(v.number(), v.integer()),
  /** Completed orders only. */
  orders_total: v.pipe(v.number(), v.integer()),
  prev_delta_pct: prevDeltaPct,
});

export type DashboardRate = v.InferOutput<typeof dashboardRateSchema>;

export const dashboardStatsSchema = v.object({
  members: dashboardStatSchema,
  points_issued: dashboardStatSchema,
  points_redeemed: dashboardStatSchema,
  liability: dashboardLiabilitySchema,
  redemption_rate: dashboardRateSchema,
});

export type DashboardStats = v.InferOutput<typeof dashboardStatsSchema>;

/** One site-timezone day; the series always has exactly `range` rows. */
export const dashboardSeriesPointSchema = v.object({
  date: v.string(),
  issued: v.pipe(v.number(), v.integer()),
  redeemed: v.pipe(v.number(), v.integer()),
});

export type DashboardSeriesPoint = v.InferOutput<typeof dashboardSeriesPointSchema>;

export const dashboardTopEarnerSchema = v.object({
  user_id: v.pipe(v.number(), v.integer()),
  name: v.string(),
  balance: v.pipe(v.number(), v.integer()),
});

export type DashboardTopEarner = v.InferOutput<typeof dashboardTopEarnerSchema>;

/** A ledger row joined to the user's display name. */
export const dashboardActivityRowSchema = v.object({
  ...ledgerEntrySchema.entries,
  name: v.string(),
});

export type DashboardActivityRow = v.InferOutput<typeof dashboardActivityRowSchema>;

export const dashboardRecentActivitySchema = v.object({
  /** Ledger rows written since site-tz midnight; may exceed `rows.length`. */
  total_today: v.pipe(v.number(), v.integer()),
  rows: v.array(dashboardActivityRowSchema),
});

export type DashboardRecentActivity = v.InferOutput<typeof dashboardRecentActivitySchema>;

/** Mirrors the keys DashboardNotices emits, the source of truth. There is one per detector. */
export const DASHBOARD_NOTICE_KEYS = ['negative_balance', 'default_campaign_paused', 'points_jump'] as const;

/** Mirrors NoticeDismissals::DISMISSABLE, the source of truth. Only these reach `POST /dashboard/notices/dismiss`. */
export const DISMISSABLE_NOTICE_KEYS = [
  'negative_balance',
  'default_campaign_paused',
  'points_jump',
] as const satisfies readonly (typeof DASHBOARD_NOTICE_KEYS)[number][];

/** Server sort order: affected → waiting → progress. */
export const DASHBOARD_NOTICE_SEVERITIES = ['affected', 'waiting', 'progress'] as const;

export const dashboardNoticeKeySchema = v.picklist(DASHBOARD_NOTICE_KEYS);
export const dismissableNoticeKeySchema = v.picklist(DISMISSABLE_NOTICE_KEYS);
export const dashboardNoticeSeveritySchema = v.picklist(DASHBOARD_NOTICE_SEVERITIES);

export type DashboardNoticeKey = v.InferOutput<typeof dashboardNoticeKeySchema>;
export type DismissableNoticeKey = v.InferOutput<typeof dismissableNoticeKeySchema>;
export type DashboardNoticeSeverity = v.InferOutput<typeof dashboardNoticeSeveritySchema>;

export const dashboardNoticeSchema = v.object({
  /** Open on purpose: an unknown key must drop that one notice, not fail the page. */
  key: v.string(),
  severity: dashboardNoticeSeveritySchema,
  /** Dismissal identity; a dismissed notice re-surfaces when this changes. */
  fingerprint: v.string(),
  /** Per-key shape; narrowed by parseNoticeData() in dashboard-notice.schema.ts. */
  data: v.record(v.string(), v.unknown()),
});

export type DashboardNotice = v.InferOutput<typeof dashboardNoticeSchema>;

export const dashboardResponseSchema = v.object({
  range: dashboardRangeSchema,
  /** UTC, Dates::now() format. */
  generated_at: v.string(),
  /** Only above the member threshold where the transient cache is active. */
  cached: v.boolean(),
  /** UTC; present only when `cached`. */
  next_refresh_at: v.nullable(v.string()),
  stats: dashboardStatsSchema,
  series: v.array(dashboardSeriesPointSchema),
  /** Max 3, balance desc. */
  top_earners: v.array(dashboardTopEarnerSchema),
  recent_activity: dashboardRecentActivitySchema,
  notices: v.array(dashboardNoticeSchema),
});

export type DashboardResponse = v.InferOutput<typeof dashboardResponseSchema>;

export const dismissDashboardNoticeParamsSchema = v.object({
  key: dismissableNoticeKeySchema,
  fingerprint: v.pipe(v.string(), v.maxLength(64)),
});

export type DismissDashboardNoticeParams = v.InferOutput<typeof dismissDashboardNoticeParamsSchema>;
