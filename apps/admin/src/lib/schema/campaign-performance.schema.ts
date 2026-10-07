import { z } from 'zod';

import { campaignSchema, CampaignTrigger } from './campaign.schema';

/**
 * Mirrors YayLoyalty\Pro\Controllers\CampaignPerformanceRestController, the source of truth.
 * A null field means "not applicable to this trigger" or "not computable yet", never 0. Render it as "—".
 * `totals` is lifetime. `window` and `daily` cover the last 30 days, clamped to the campaign's run.
 */

/**
 * Triggers the performance page supports. Mirrors `CampaignPerformanceRestController::TRIGGERS`.
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
export const campaignPerformanceSkipSchema = z.object({
  reason: z.enum(CAMPAIGN_SKIP_REASONS),
  count: z.number().int(),
  campaign_id: z.number().int().nullable(),
  campaign_name: z.string().nullable(),
});

export type CampaignPerformanceSkip = z.infer<typeof campaignPerformanceSkipSchema>;

/** `GET /campaigns/{id}/performance` response. */
export const campaignPerformanceSchema = z.object({
  campaign_id: z.number().int(),
  /** The campaign itself, verbatim the `GET /campaigns/{id}` shape, so the page's header needs no second request. */
  campaign: campaignSchema,
  /** Site-timezone dates, both ends inclusive. */
  window: z.object({
    from: z.string(),
    to: z.string(),
  }),
  totals: z.object({
    /** All triggers. */
    points_issued: z.number().int(),
    /** All triggers. The unfiltered row count behind the entries table. */
    entries_total: z.number().int(),
    /** Purchase only. */
    orders_affected: z.number().int().nullable(),
    /** Purchase only. The store's lifetime count of completed orders, not scoped to this campaign: the denominator for `orders_affected`. */
    orders_total: z.number().int().nullable(),
    /** Signup / review only: distinct customers who earned from this campaign. */
    customers_earned: z.number().int().nullable(),
    /** Purchase with real data only. */
    avg_points_per_order: z.number().int().nullable(),
    /** Always null until the earning engine stores the scored base amount. */
    avg_order_subtotal: z.number().nullable(),
    /** Purchase only; null when the store has no configured points-to-currency rate. */
    liability_amount: z.number().nullable(),
  }),
  /** One entry per day in `window`, zero-filled. */
  daily: z.array(
    z.object({
      date: z.string(),
      points: z.number().int(),
    }),
  ),
  /** "Where the other orders went". Null, and its panel hidden, until the earning engine can replay every order. */
  skipped: z.array(campaignPerformanceSkipSchema).nullable(),
  /** "What it would have done last year". Null for the same reason as `skipped`. */
  estimate: z
    .object({
      from: z.string(),
      to: z.string(),
      orders: z.number().int(),
      points: z.number().int(),
      liability_amount: z.number().nullable(),
    })
    .nullable(),
});

export type CampaignPerformance = z.infer<typeof campaignPerformanceSchema>;

/**
 * One row of `GET /campaigns/{id}/performance/entries`: an order, a signup or a review, by trigger.
 * `reference` is the order or the review, and null for a signup. `subtotal` is purchase-only.
 */
export const campaignPerformanceEntrySchema = z.object({
  id: z.number().int(),
  customer_id: z.number().int(),
  customer_name: z.string(),
  points: z.number().int(),
  /** UTC, `Y-m-d H:i:s`. */
  created_at: z.string(),
  reference: z
    .object({
      id: z.number().int(),
      label: z.string(),
      url: z.string(),
    })
    .nullable(),
  subtotal: z.number().nullable(),
});

export type CampaignPerformanceEntry = z.infer<typeof campaignPerformanceEntrySchema>;

/** App-side (camelCase) params for the entries fetch; the api module maps `perPage` to `per_page`. */
export const campaignPerformanceEntriesParamsSchema = z.object({
  page: z.number().int().min(1),
  perPage: z.number().int().optional(),
  search: z.string().optional(),
});

export type CampaignPerformanceEntriesParams = z.infer<typeof campaignPerformanceEntriesParamsSchema>;
