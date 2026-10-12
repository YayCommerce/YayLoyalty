import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors RedemptionsRestController::define_item_schema(), the source of truth.
 * The server answers an empty envelope until the redemptions table exists.
 */

/** Mirrors `RedemptionsRestController`'s `status` enum, the source of truth. A display status, not the table's full lifecycle. */
export const REDEMPTION_STATUSES = ['applied', 'returned', 'issued'] as const;
export const redemptionStatusSchema = z.enum(REDEMPTION_STATUSES);
export type RedemptionStatus = z.infer<typeof redemptionStatusSchema>;

export const redemptionEntrySchema = z.object({
  id: z.number().int(),
  reward_id: z.number().int(),
  /** Display name only. How to resolve it from `reward_id` is still open. */
  reward_name: z.string(),
  order_id: z.number().int().nullable(),
  /** Signed for display: negative when spent, positive when a refund returned the points. */
  points: z.number().int(),
  value: z.string(),
  currency: z.string(),
  status: redemptionStatusSchema,
  created_at: z.string(),
});

export type RedemptionEntry = z.infer<typeof redemptionEntrySchema>;

export const redemptionEnvelopeSchema = paginatedSchema(redemptionEntrySchema);

export type RedemptionEnvelope = z.infer<typeof redemptionEnvelopeSchema>;
