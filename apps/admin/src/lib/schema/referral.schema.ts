import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors `ReferralsRestController::define_item_schema()`. THAT PHP METHOD
 * IS THE SOURCE OF TRUTH.
 *
 * The server always answers an empty envelope — no `yayloyalty_referrals`
 * table exists yet (docs/data-model/future-tables.md, 1.1, designed but not implemented).
 * When it ships, only the server handler changes — this schema is already
 * the contract.
 *
 * `referred_name`/`referred_email` are personal data of a THIRD PARTY (the
 * referred customer, not the one the drawer belongs to).
 */

/** Matches `ReferralsRestController`'s `status` enum. */
export const REFERRAL_STATUSES = ['completed', 'pending', 'lapsed'] as const;
export const referralStatusSchema = z.enum(REFERRAL_STATUSES);
export type ReferralStatus = z.infer<typeof referralStatusSchema>;

export const referralEntrySchema = z.object({
  id: z.number().int(),
  referred_name: z.string(),
  referred_email: z.string(),
  signed_up_at: z.string(),
  first_order_id: z.number().int().nullable(),
  first_order_total: z.string().nullable(),
  referrer_points: z.number().int(),
  friend_coupon: z.object({
    label: z.string(),
    used: z.boolean(),
  }),
  status: referralStatusSchema,
  /** Only meaningful when `status === 'pending'`. */
  window_closes_at: z.string().nullable(),
});

export type ReferralEntry = z.infer<typeof referralEntrySchema>;

/** The campaign facts every row shares — structured, composed into a sentence client-side. */
export const referralCampaignSchema = z.object({
  referrer_points: z.number().int(),
  friend_coupon_label: z.string(),
  window_days: z.number().int(),
});

export type ReferralCampaign = z.infer<typeof referralCampaignSchema>;

/**
 * Envelope carries the referral link + campaign alongside the rows. Parse
 * with THIS schema, not the generic `parsePaginated()` helper, which would
 * silently drop both fields.
 */
export const referralEnvelopeSchema = paginatedSchema(referralEntrySchema).extend({
  referral_link: z.string(),
  campaign: referralCampaignSchema.nullable(),
});

export type ReferralEnvelope = z.infer<typeof referralEnvelopeSchema>;
