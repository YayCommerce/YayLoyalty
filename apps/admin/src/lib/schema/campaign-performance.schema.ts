import * as v from 'valibot';

import { campaignSchema, CampaignTrigger } from './campaign.schema';

/**
 * Mirrors YayLoyalty\Pro\Controllers\CampaignPerformanceRestController, the source of truth.
 * A null field means "not applicable to this trigger" or "not computable yet", never 0. Render it as "—".
 * `totals` is lifetime. `window` and `daily` cover the last 30 days, clamped to the campaign's run.
 */

/**
 * Mirrors `CampaignPerformanceRestController::TRIGGERS`, the source of truth. These are the triggers the performance page supports.
 * It matches `LITE_CAMPAIGN_TRIGGERS` in `campaign.schema.ts` by coincidence, so it stays its own constant.
 * A trigger outside this list 404s on both routes.
 */
export const CAMPAIGN_PERFORMANCE_TRIGGERS = [
  'purchase',
  'signup',
  'review',
] as const satisfies readonly CampaignTrigger[];

export type CampaignPerformanceTrigger = (typeof CAMPAIGN_PERFORMANCE_TRIGGERS)[number];

export function hasPerformancePage(trigger: CampaignTrigger): trigger is CampaignPerformanceTrigger {
  return (CAMPAIGN_PERFORMANCE_TRIGGERS as readonly CampaignTrigger[]).includes(trigger);
}

/** Why an order in the reporting window didn't earn from this campaign. */
export const CAMPAIGN_SKIP_REASONS = ['higher_priority', 'guest', 'excluded'] as const;

export type CampaignSkipReason = (typeof CAMPAIGN_SKIP_REASONS)[number];

/** One line of "where the other orders went", a standalone schema so its type can be used on its own. */
export const campaignPerformanceSkipSchema = v.object({
  reason: v.picklist(CAMPAIGN_SKIP_REASONS),
  count: v.pipe(v.number(), v.integer()),
  campaign_id: v.nullable(v.pipe(v.number(), v.integer())),
  campaign_name: v.nullable(v.string()),
});

export type CampaignPerformanceSkip = v.InferOutput<typeof campaignPerformanceSkipSchema>;

/** `GET /campaigns/{id}/performance` response. */
export const campaignPerformanceSchema = v.object({
  campaign_id: v.pipe(v.number(), v.integer()),
  /** The campaign itself, verbatim the `GET /campaigns/{id}` shape, so the page's header needs no second request. */
  campaign: campaignSchema,
  /** Site-timezone dates, both ends inclusive. */
  window: v.object({
    from: v.string(),
    to: v.string(),
  }),
  totals: v.object({
    /** All triggers. */
    points_issued: v.pipe(v.number(), v.integer()),
    /** All triggers. The unfiltered row count behind the entries table. */
    entries_total: v.pipe(v.number(), v.integer()),
    /** Purchase only. */
    orders_affected: v.nullable(v.pipe(v.number(), v.integer())),
    /** Purchase only. The store's lifetime count of completed orders, not scoped to this campaign: the denominator for `orders_affected`. */
    orders_total: v.nullable(v.pipe(v.number(), v.integer())),
    /** Signup / review only: distinct customers who earned from this campaign. */
    customers_earned: v.nullable(v.pipe(v.number(), v.integer())),
    /** Purchase with real data only. */
    avg_points_per_order: v.nullable(v.pipe(v.number(), v.integer())),
    /** Always null until the earning engine stores the scored base amount. */
    avg_order_subtotal: v.nullable(v.number()),
  }),
  /** One entry per day in `window`, zero-filled. */
  daily: v.array(
    v.object({
      date: v.string(),
      points: v.pipe(v.number(), v.integer()),
    }),
  ),
  /** "Where the other orders went". Null, and its panel hidden, until the earning engine can replay every order. */
  skipped: v.nullable(v.array(campaignPerformanceSkipSchema)),
  /** "What it would have done last year". Null for the same reason as `skipped`. */
  estimate: v.nullable(
    v.object({
      from: v.string(),
      to: v.string(),
      orders: v.pipe(v.number(), v.integer()),
      points: v.pipe(v.number(), v.integer()),
    }),
  ),
});

export type CampaignPerformance = v.InferOutput<typeof campaignPerformanceSchema>;

/**
 * One row of `GET /campaigns/{id}/performance/entries`: an order, a signup or a review, by trigger.
 * `reference` is the order or the review, and null for a signup. `subtotal` is purchase-only.
 */
export const campaignPerformanceEntrySchema = v.object({
  id: v.pipe(v.number(), v.integer()),
  customer_id: v.pipe(v.number(), v.integer()),
  customer_name: v.string(),
  points: v.pipe(v.number(), v.integer()),
  /** UTC, `Y-m-d H:i:s`. */
  created_at: v.string(),
  reference: v.nullable(
    v.object({
      id: v.pipe(v.number(), v.integer()),
      label: v.string(),
      url: v.string(),
    }),
  ),
  subtotal: v.nullable(v.number()),
});

export type CampaignPerformanceEntry = v.InferOutput<typeof campaignPerformanceEntrySchema>;

/** App-side (camelCase) params for the entries fetch; the api module maps `perPage` to `per_page`. */
export const campaignPerformanceEntriesParamsSchema = v.object({
  page: v.pipe(v.number(), v.integer(), v.minValue(1)),
  perPage: v.optional(v.pipe(v.number(), v.integer())),
  search: v.optional(v.string()),
});

export type CampaignPerformanceEntriesParams = v.InferOutput<typeof campaignPerformanceEntriesParamsSchema>;
