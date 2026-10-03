import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors `RedemptionsRestController::define_item_schema()`. THAT PHP METHOD
 * IS THE SOURCE OF TRUTH.
 *
 * The server always answers an empty envelope — no `yayloyalty_redemptions`
 * table exists yet (designed, not built yet).
 * When it ships, only the server handler changes — this schema is already
 * the contract.
 */

/** Matches `RedemptionsRestController`'s `status` enum — a display status,
 *  not the real table's full lifecycle vocabulary (see the PHP docblock). */
export const REDEMPTION_STATUSES = ['applied', 'returned', 'issued'] as const;
export const redemptionStatusSchema = z.enum(REDEMPTION_STATUSES);
export type RedemptionStatus = z.infer<typeof redemptionStatusSchema>;

export const redemptionEntrySchema = z.object({
  id: z.number().int(),
  reward_id: z.number().int(),
  /** Display name only — the real table stores `reward_id`; resolving it is
   *  an open question, the same one the ledger's `campaign_name` has. */
  reward_name: z.string(),
  order_id: z.number().int().nullable(),
  /** Signed for display: negative when spent, positive when a refund
   *  returned the points. */
  points: z.number().int(),
  value: z.string(),
  currency: z.string(),
  status: redemptionStatusSchema,
  created_at: z.string(),
});

export type RedemptionEntry = z.infer<typeof redemptionEntrySchema>;

export const redemptionEnvelopeSchema = paginatedSchema(redemptionEntrySchema);

export type RedemptionEnvelope = z.infer<typeof redemptionEnvelopeSchema>;
