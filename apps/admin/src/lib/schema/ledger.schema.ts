import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/** Mirrors LedgerRestController::define_item_schema(), the source of truth. */

/** Mirrors Enums\LedgerType, the source of truth. */
export const LEDGER_TYPES = ['earn', 'redeem', 'refund_earn', 'refund_redeem', 'expire', 'adjust', 'correction'] as const;

/** Mirrors Enums\LedgerSource, the source of truth. */
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
  'tier_bonus',
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
  /** Shown to the customer in place of "Adjustment by {store}"; manual adjustments only. */
  customer_note: z.string().nullable(),
  /**
   * The tier an entry bonus was paid for, deleted tiers included; null on every other row. Mirrors LedgerItemTierNames.
   * Only the ledger list routes send it; the adjust route and the dashboard don't, so it defaults to null.
   */
  tier_name: z.string().nullable().default(null),
  actor_id: z.number().int().nullable(),
  created_at: z.string(),
});

export type LedgerEntry = z.infer<typeof ledgerEntrySchema>;

/**
 * Mirrors `LedgerQueryArgs::filter_types()`, the source of truth. The ledger tab's filter groups over `LEDGER_TYPES`.
 * `earn` adds `refund_earn`, `redeem` adds `refund_redeem` and `adjust` adds `correction`.
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

/** Per-group counts over the unfiltered set, for the filter labels. Built by `LedgerQueryArgs::group_counts()`. */
export const ledgerTypeCountsSchema = z.object(ledgerTypeCountsShape);

export type LedgerTypeCounts = z.infer<typeof ledgerTypeCountsSchema>;

/** `GET /customers/{id}/ledger`'s envelope: the paginated envelope plus `type_counts`. */
export const ledgerEnvelopeSchema = paginatedSchema(ledgerEntrySchema).extend({
  type_counts: ledgerTypeCountsSchema,
});

export type LedgerEnvelope = z.infer<typeof ledgerEnvelopeSchema>;
