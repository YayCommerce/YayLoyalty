import { z } from 'zod';

import { CampaignTrigger } from './campaign.schema';

/**
 * Mirrors Pro\Controllers\CampaignPerformanceRestController and
 * Pro\Controllers\CampaignPerformanceFixture (`GET /campaigns/{id}/performance`,
 * `GET /campaigns/{id}/performance/entries`). THOSE PHP CLASSES ARE THE
 * SOURCE OF TRUTH.
 *
 * Every `.nullable()` field means "not applicable to this trigger" or "not
 * computable yet" — NEVER 0. A purchase-only total is null on a signup/review
 * campaign; an average is null when there are zero entries to average
 * (division by zero), not 0. Render null as "—", the same rule the dashboard
 * uses for its redemption rate and delta lines.
 */

/**
 * Triggers this page understands — mirrors `CampaignPerformanceRestController::TRIGGERS`.
 * Same three values as `LITE_CAMPAIGN_TRIGGERS` above by coincidence, not identity: this is
 * what the performance page supports, not what Lite ships, so it stays its own constant.
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

/** Mirrors `CampaignPerformanceFixture::skipped_breakdown()`'s `reason` values. */
export const CAMPAIGN_SKIP_REASONS = ['higher_priority', 'guest', 'excluded'] as const;

export type CampaignSkipReason = (typeof CAMPAIGN_SKIP_REASONS)[number];

/** One line of "where the other orders went" — a standalone schema, like `campaignConditionSchema`, so its type is usable on its own (e.g. by `skipReasonLabel()`). */
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
  /** Site-timezone dates, both ends inclusive. */
  window: z.object({
    from: z.string(),
    to: z.string(),
  }),
  totals: z.object({
    /** All triggers. */
    points_issued: z.number().int(),
    /** All triggers — unfiltered row count behind the entries table; drives the search-bar and "Export all N" visibility. */
    entries_total: z.number().int(),
    /** Purchase only. */
    orders_affected: z.number().int().nullable(),
    /** Purchase with real data only. */
    orders_in_window: z.number().int().nullable(),
    /** Signup / review only: distinct customers who earned from this campaign. */
    customers_earned: z.number().int().nullable(),
    /** Purchase with real data only. */
    avg_points_per_order: z.number().int().nullable(),
    /** Purchase with real data only. */
    avg_order_subtotal: z.number().nullable(),
    /** Purchase only; null when the store has no configured points-to-currency rate. */
    liability_amount: z.number().nullable(),
    /** Purchase, non-built-in multiplier campaigns only. */
    liability_above_default: z.number().nullable(),
  }),
  /** One entry per day in `window`, zero-filled. */
  daily: z.array(
    z.object({
      date: z.string(),
      points: z.number().int(),
    }),
  ),
  /** "Where the other orders went" — purchase campaigns with real data only; null otherwise (panel hidden). */
  skipped: z.array(campaignPerformanceSkipSchema).nullable(),
  /** "What it would have done last year" — purchase campaigns with NO real data yet only; null otherwise (panel hidden). */
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
 * One row of `GET /campaigns/{id}/performance/entries`'s `data` — an order,
 * a signup, or a review depending on the campaign's trigger.
 *
 * `reference` is the order (purchase) or the review (review); always null
 * for signup, which has no secondary record to link to. `subtotal` is
 * purchase-only.
 */
export const campaignPerformanceEntrySchema = z.object({
  id: z.number().int(),
  customer_id: z.number().int(),
  customer_name: z.string(),
  points: z.number().int(),
  /** UTC, `Y-m-d H:i:s` — see code-standards.md §4. */
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

/** App-side (camelCase) params for the entries fetch — `campaign-performance.api.ts` maps them to the wire's `per_page`. */
export const campaignPerformanceEntriesParamsSchema = z.object({
  page: z.number().int().min(1),
  perPage: z.number().int().optional(),
  search: z.string().optional(),
});

export type CampaignPerformanceEntriesParams = z.infer<typeof campaignPerformanceEntriesParamsSchema>;
