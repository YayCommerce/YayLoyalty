import { z } from 'zod';

/**
 * Mirrors CustomerRestController::get_item_schema().
 *
 * THAT PHP METHOD IS THE SOURCE OF TRUTH. When a column is added there, add it
 * here; nothing generates one from the other.
 *
 * Field names are snake_case to match the PHP payload exactly. The pagination
 * envelope around collections is camelCase — see PaginatedResponse<T>.
 *
 * Every aggregate is server-owned and derived from the ledger; none of it is
 * writable, which is why there is no separate "create" or "update" shape.
 */
export const customerSchema = z.object({
  user_id: z.number().int(),
  /** Signed: a clawback after a refund can legitimately push this below zero. */
  balance: z.number().int(),
  pending_points: z.number().int(),
  /** Signed. Unaffected by redemptions, so spending never demotes a tier. */
  lifetime_earned: z.number().int(),
  lifetime_redeemed: z.number().int(),
  last_activity_at: z.string().nullable(),
  expires_at: z.string().nullable(),
  joined_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

export type Customer = z.infer<typeof customerSchema>;

/** Request body for POST /customers/{id}/adjust. */
export const adjustPointsSchema = z.object({
  points: z.number().int(),
  note: z.string().min(1),
});

export type AdjustPointsInput = z.infer<typeof adjustPointsSchema>;
