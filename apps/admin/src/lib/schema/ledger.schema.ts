import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors LedgerRestController::get_item_schema().
 *
 * THAT PHP METHOD IS THE SOURCE OF TRUTH. The enums below must stay in step
 * with Enums\LedgerType and Enums\LedgerSource.
 */

/** Matches Enums\LedgerType. */
export const LEDGER_TYPES = ['earn', 'redeem', 'refund_earn', 'refund_redeem', 'expire', 'adjust', 'correction'] as const;

/** Matches Enums\LedgerSource. */
export const LEDGER_SOURCES = [
  'order',
  'signup',
  'review',
  'referral',
  'birthday',
  'milestone',
  'social',
  'renewal',
  'manual',
  'import',
  'expiry',
  'system',
] as const;

export const ledgerTypeSchema = z.enum(LEDGER_TYPES);
export const ledgerSourceSchema = z.enum(LEDGER_SOURCES);

export type LedgerType = z.infer<typeof ledgerTypeSchema>;
export type LedgerSource = z.infer<typeof ledgerSourceSchema>;

export const ledgerEntrySchema = z.object({
  id: z.number().int(),
  user_id: z.number().int(),
  /** Signed delta: negative for redemptions, clawbacks and expiry. */
  points: z.number().int(),
  /** Balance immediately after this row. Forms an unbroken chain per user. */
  balance_after: z.number().int(),
  type: ledgerTypeSchema,
  source: ledgerSourceSchema,
  reference_type: z.string().nullable(),
  reference_id: z.number().int().nullable(),
  campaign_id: z.number().int().nullable(),
  redemption_id: z.number().int().nullable(),
  order_id: z.number().int().nullable(),
  /** Manual adjustments only; system rows compose their text client-side so it stays translatable. */
  note: z.string().nullable(),
  actor_id: z.number().int().nullable(),
  created_at: z.string(),
});

export type LedgerEntry = z.infer<typeof ledgerEntrySchema>;

/**
 * The drawer ledger tab's filter vocabulary — a GROUPING over `LEDGER_TYPES`,
 * not the same list: `earn` covers `earn` + `refund_earn`, `redeem` covers
 * `redeem` + `refund_redeem`, `adjust` covers `adjust` + `correction`. Mirrors `LedgerQueryArgs::filter_types()`.
 */
export const LEDGER_FILTER_TYPES = ['all', 'earn', 'redeem', 'adjust', 'expire'] as const;
export const ledgerFilterTypeSchema = z.enum(LEDGER_FILTER_TYPES);
export type LedgerFilterType = z.infer<typeof ledgerFilterTypeSchema>;

// `satisfies`, not `z.record()`: zod 3 types record keys as optional, and
// this way a key added to or dropped from LEDGER_FILTER_TYPES fails tsc here.
const ledgerTypeCountsShape = {
  all: z.number().int(),
  earn: z.number().int(),
  redeem: z.number().int(),
  adjust: z.number().int(),
  expire: z.number().int(),
} satisfies Record<LedgerFilterType, z.ZodTypeAny>;

/**
 * Per-type counts over the UNFILTERED set — what the ledger tab's filter
 * labels ("All 4,812", "Earned 4,190") read from. Built by
 * `LedgerQueryArgs::group_counts()`.
 */
export const ledgerTypeCountsSchema = z.object(ledgerTypeCountsShape);

export type LedgerTypeCounts = z.infer<typeof ledgerTypeCountsSchema>;

/**
 * `GET /customers/{id}/ledger`'s full envelope — the paginated envelope plus
 * `type_counts`. Parse with THIS schema, not the generic `parsePaginated()`
 * helper, which would silently drop `type_counts`.
 */
export const ledgerEnvelopeSchema = paginatedSchema(ledgerEntrySchema).extend({
  type_counts: ledgerTypeCountsSchema,
});

export type LedgerEnvelope = z.infer<typeof ledgerEnvelopeSchema>;
