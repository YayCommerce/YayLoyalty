import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors RedemptionsRestController::define_item_schema(), the source of truth.
 * The server reads the redemptions table, newest first.
 */

/** Matches `RedemptionsRestController`'s `status` enum: the five stored statuses, plus `expired`, which the server derives. */
export const REDEMPTION_STATUSES = ['applied', 'issued', 'used', 'refunded', 'cancelled', 'expired'] as const;
export const redemptionStatusSchema = z.enum(REDEMPTION_STATUSES);
export type RedemptionStatus = z.infer<typeof redemptionStatusSchema>;

export const redemptionEntrySchema = z.object({
  id: z.number().int(),
  reward_id: z.number().int(),
  /** Resolved from `reward_id` by the server. Empty when the reward was deleted. */
  reward_name: z.string(),
  order_id: z.number().int().nullable(),
  /** Negative: the points spent. */
  points: z.number().int(),
  value: z.string(),
  currency: z.string(),
  status: redemptionStatusSchema,
  created_at: z.string(),
});

export type RedemptionEntry = z.infer<typeof redemptionEntrySchema>;

export const redemptionEnvelopeSchema = paginatedSchema(redemptionEntrySchema);

export type RedemptionEnvelope = z.infer<typeof redemptionEnvelopeSchema>;
