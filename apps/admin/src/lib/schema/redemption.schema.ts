import * as v from 'valibot';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors RedemptionsRestController::define_item_schema(), the source of truth.
 * The server answers an empty envelope until the redemptions table exists.
 */

/** Mirrors `RedemptionsRestController`'s `status` enum, the source of truth. A display status, not the table's full lifecycle. */
export const REDEMPTION_STATUSES = ['applied', 'returned', 'issued'] as const;
export const redemptionStatusSchema = v.picklist(REDEMPTION_STATUSES);
export type RedemptionStatus = v.InferOutput<typeof redemptionStatusSchema>;

export const redemptionEntrySchema = v.object({
  id: v.pipe(v.number(), v.integer()),
  reward_id: v.pipe(v.number(), v.integer()),
  /** Display name only. How to resolve it from `reward_id` is still open. */
  reward_name: v.string(),
  order_id: v.nullable(v.pipe(v.number(), v.integer())),
  /** Signed for display: negative when spent, positive when a refund returned the points. */
  points: v.pipe(v.number(), v.integer()),
  value: v.string(),
  currency: v.string(),
  status: redemptionStatusSchema,
  created_at: v.string(),
});

export type RedemptionEntry = v.InferOutput<typeof redemptionEntrySchema>;

export const redemptionEnvelopeSchema = paginatedSchema(redemptionEntrySchema);

export type RedemptionEnvelope = v.InferOutput<typeof redemptionEnvelopeSchema>;
