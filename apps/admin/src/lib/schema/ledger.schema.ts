import { z } from 'zod';

/**
 * Mirrors LedgerRestController::define_item_schema().
 *
 * THAT PHP METHOD IS THE SOURCE OF TRUTH. The enums below must stay in step
 * with Enums\LedgerType and Enums\LedgerSource.
 */

/** Matches Enums\LedgerType. */
export const LEDGER_TYPES = ['earn', 'redeem', 'refund_earn', 'refund_redeem', 'expire', 'adjust'] as const;

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
  /**
   * Present on manual adjustments only. System rows deliberately store no text
   * so their description stays translatable — compose those client-side from
   * type/source rather than expecting a string here.
   */
  note: z.string().nullable(),
  actor_id: z.number().int().nullable(),
  created_at: z.string(),
});

export type LedgerEntry = z.infer<typeof ledgerEntrySchema>;
