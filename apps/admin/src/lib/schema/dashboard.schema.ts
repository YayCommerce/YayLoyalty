import { z } from 'zod';

import { ledgerEntrySchema } from './ledger.schema';

/**
 * Mirrors DashboardRestController::define_item_schema(), the source of truth.
 * A zero denominator yields null, never 0: null renders as "—" or "no previous period".
 * Notices carry no copy. dashboard-page.notice-copy.ts composes it from key and data.
 */

export const DASHBOARD_RANGES = [7, 30, 90] as const;

export const dashboardRangeSchema = z.union([z.literal(7), z.literal(30), z.literal(90)]);

export type DashboardRange = z.infer<typeof dashboardRangeSchema>;

/** Percentage change vs the previous window; null = nothing to compare against. */
const prevDeltaPct = z.number().nullable();

export const dashboardStatSchema = z.object({
  value: z.number().int(),
  prev_delta_pct: prevDeltaPct,
});

export type DashboardStat = z.infer<typeof dashboardStatSchema>;

export const dashboardLiabilitySchema = z.object({
  /** SUM(balance) over positive balances. */
  points: z.number().int(),
  prev_delta_pct: prevDeltaPct,
});

export type DashboardLiability = z.infer<typeof dashboardLiabilitySchema>;

export const dashboardRateSchema = z.object({
  /** `orders_with_redemption / orders_total` as a percentage, 1 dp; `null` when no orders. */
  rate: z.number().nullable(),
  orders_with_redemption: z.number().int(),
  /** Completed orders only. */
  orders_total: z.number().int(),
  prev_delta_pct: prevDeltaPct,
});

export type DashboardRate = z.infer<typeof dashboardRateSchema>;

export const dashboardStatsSchema = z.object({
  members: dashboardStatSchema,
  points_issued: dashboardStatSchema,
  points_redeemed: dashboardStatSchema,
  liability: dashboardLiabilitySchema,
  redemption_rate: dashboardRateSchema,
});

export type DashboardStats = z.infer<typeof dashboardStatsSchema>;

/** One site-timezone day; the series always has exactly `range` rows. */
export const dashboardSeriesPointSchema = z.object({
  date: z.string(),
  issued: z.number().int(),
  redeemed: z.number().int(),
});

export type DashboardSeriesPoint = z.infer<typeof dashboardSeriesPointSchema>;

export const dashboardTopBalanceSchema = z.object({
  user_id: z.number().int(),
  name: z.string(),
  balance: z.number().int(),
});

export type DashboardTopBalance = z.infer<typeof dashboardTopBalanceSchema>;

/** A ledger row joined to the user's display name. */
export const dashboardActivityRowSchema = ledgerEntrySchema.extend({
  name: z.string(),
});

export type DashboardActivityRow = z.infer<typeof dashboardActivityRowSchema>;

export const dashboardRecentActivitySchema = z.object({
  /** Ledger rows written since site-tz midnight; may exceed `rows.length`. */
  total_today: z.number().int(),
  rows: z.array(dashboardActivityRowSchema),
});

export type DashboardRecentActivity = z.infer<typeof dashboardRecentActivitySchema>;

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

export const dashboardNoticeKeySchema = z.enum(DASHBOARD_NOTICE_KEYS);
export const dismissableNoticeKeySchema = z.enum(DISMISSABLE_NOTICE_KEYS);
export const dashboardNoticeSeveritySchema = z.enum(DASHBOARD_NOTICE_SEVERITIES);

export type DashboardNoticeKey = z.infer<typeof dashboardNoticeKeySchema>;
export type DismissableNoticeKey = z.infer<typeof dismissableNoticeKeySchema>;
export type DashboardNoticeSeverity = z.infer<typeof dashboardNoticeSeveritySchema>;

export const dashboardNoticeSchema = z.object({
  /** Open on purpose: an unknown key must drop that one notice, not fail the page. */
  key: z.string(),
  severity: dashboardNoticeSeveritySchema,
  /** Dismissal identity; a dismissed notice re-surfaces when this changes. */
  fingerprint: z.string(),
  /** Per-key shape; narrowed by parseNoticeData() in dashboard-notice.schema.ts. */
  data: z.record(z.string(), z.unknown()),
});

export type DashboardNotice = z.infer<typeof dashboardNoticeSchema>;

export const dashboardResponseSchema = z.object({
  range: dashboardRangeSchema,
  /** UTC, Dates::now() format. */
  generated_at: z.string(),
  /** Only above the member threshold where the transient cache is active. */
  cached: z.boolean(),
  /** UTC; present only when `cached`. */
  next_refresh_at: z.string().nullable(),
  stats: dashboardStatsSchema,
  series: z.array(dashboardSeriesPointSchema),
  /** Max 3, balance desc. */
  top_balances: z.array(dashboardTopBalanceSchema),
  recent_activity: dashboardRecentActivitySchema,
  notices: z.array(dashboardNoticeSchema),
});

export type DashboardResponse = z.infer<typeof dashboardResponseSchema>;

export const dismissDashboardNoticeParamsSchema = z.object({
  key: dismissableNoticeKeySchema,
  fingerprint: z.string().max(64),
});

export type DismissDashboardNoticeParams = z.infer<typeof dismissDashboardNoticeParamsSchema>;
