import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/** Mirrors YayLoyalty\Pro\Enums\ReferralStatus::all(), in the order the status pills list them. */
export const REFERRAL_STATUSES = ['rewarded', 'on_hold', 'pending', 'rejected', 'expired', 'reversed'] as const;

export type ReferralStatus = (typeof REFERRAL_STATUSES)[number];

const personSchema = z.object({ id: z.number().int(), name: z.string(), email: z.string() });

/** Mirrors `ReferralsRestController::get_referrals()` items: `ReferralListRepository::shape_row()` plus `with_order()`. */
export const referralListItemSchema = z.object({
  id: z.number().int(),
  status: z.enum(REFERRAL_STATUSES),
  reject_reason: z.string().nullable(),
  hold_until: z.string().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  advocate: personSchema,
  friend: personSchema,
  advocate_points: z.number().int(),
  first_order: z
    .object({
      id: z.number().int(),
      number: z.string(),
      total: z.string(),
      currency: z.string(),
      edit_url: z.string(),
    })
    .nullable(),
});

export type ReferralListItem = z.infer<typeof referralListItemSchema>;

/** `GET /referrals`: the paginated envelope plus store-wide `counts` per status (a status with none is absent). */
export const referralListEnvelopeSchema = paginatedSchema(referralListItemSchema).extend({
  counts: z.record(z.string(), z.number().int()),
});
