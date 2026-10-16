import * as v from 'valibot';

import { paginatedSchema } from '@/lib/api/paginated';

/** Mirrors LedgerRestController::define_item_schema(), the source of truth. */

/** Mirrors Enums\LedgerType, the source of truth. */
export const LEDGER_TYPES = [
  'earn',
  'redeem',
  'refund_earn',
  'refund_redeem',
  'expire',
  'adjust',
  'correction',
] as const;

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
] as const;

export const ledgerTypeSchema = v.picklist(LEDGER_TYPES);
export const ledgerSourceSchema = v.picklist(LEDGER_SOURCES);

export type LedgerType = v.InferOutput<typeof ledgerTypeSchema>;
export type LedgerSource = v.InferOutput<typeof ledgerSourceSchema>;

export const ledgerEntrySchema = v.object({
  id: v.pipe(v.number(), v.integer()),
  user_id: v.pipe(v.number(), v.integer()),
  /** Signed delta: negative for redemptions, clawbacks and expiry. */
  points: v.pipe(v.number(), v.integer()),
  /** Balance immediately after this row. Forms an unbroken chain per user. */
  balance_after: v.pipe(v.number(), v.integer()),
  type: ledgerTypeSchema,
  source: ledgerSourceSchema,
  reference_type: v.nullable(v.string()),
  reference_id: v.nullable(v.pipe(v.number(), v.integer())),
  campaign_id: v.nullable(v.pipe(v.number(), v.integer())),
  redemption_id: v.nullable(v.pipe(v.number(), v.integer())),
  order_id: v.nullable(v.pipe(v.number(), v.integer())),
  /** Manual adjustments only; system rows compose their text client-side so it stays translatable. */
  note: v.nullable(v.string()),
  /** Shown to the customer in place of "Adjustment by {store}"; manual adjustments only. */
  customer_note: v.nullable(v.string()),
  actor_id: v.nullable(v.pipe(v.number(), v.integer())),
  created_at: v.string(),
});

export type LedgerEntry = v.InferOutput<typeof ledgerEntrySchema>;

/**
 * Mirrors `LedgerQueryArgs::filter_types()`, the source of truth. The ledger tab's filter groups over `LEDGER_TYPES`.
 * `earn` adds `refund_earn`, `redeem` adds `refund_redeem` and `adjust` adds `correction`.
 */
export const LEDGER_FILTER_TYPES = ['all', 'earn', 'redeem', 'adjust', 'expire'] as const;
export const ledgerFilterTypeSchema = v.picklist(LEDGER_FILTER_TYPES);
export type LedgerFilterType = v.InferOutput<typeof ledgerFilterTypeSchema>;

// `satisfies`, not a record schema: this way a key added to or dropped from
// LEDGER_FILTER_TYPES fails tsc here.
const ledgerTypeCountsShape = {
  all: v.pipe(v.number(), v.integer()),
  earn: v.pipe(v.number(), v.integer()),
  redeem: v.pipe(v.number(), v.integer()),
  adjust: v.pipe(v.number(), v.integer()),
  expire: v.pipe(v.number(), v.integer()),
} satisfies Record<LedgerFilterType, v.GenericSchema>;

/** Per-group counts over the unfiltered set, for the filter labels. Built by `LedgerQueryArgs::group_counts()`. */
export const ledgerTypeCountsSchema = v.object(ledgerTypeCountsShape);

export type LedgerTypeCounts = v.InferOutput<typeof ledgerTypeCountsSchema>;

/** `GET /customers/{id}/ledger`'s envelope: the paginated envelope plus `type_counts`. */
export const ledgerEnvelopeSchema = v.object({
  ...paginatedSchema(ledgerEntrySchema).entries,
  type_counts: ledgerTypeCountsSchema,
});

export type LedgerEnvelope = v.InferOutput<typeof ledgerEnvelopeSchema>;
