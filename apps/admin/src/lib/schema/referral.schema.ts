import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';

/**
 * Mirrors ReferralsRestController::define_item_schema(), the source of truth.
 * The server answers an empty envelope until the referrals table exists.
 * `referred_name` and `referred_email` are a third party's personal data: the referred customer's, not the drawer's.
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

/** The campaign facts every row shares, composed into a sentence client-side. */
export const referralCampaignSchema = z.object({
  referrer_points: z.number().int(),
  friend_coupon_label: z.string(),
  window_days: z.number().int(),
});

export type ReferralCampaign = z.infer<typeof referralCampaignSchema>;

/** The envelope carries the referral link and campaign alongside the rows. */
export const referralEnvelopeSchema = paginatedSchema(referralEntrySchema).extend({
  referral_link: z.string(),
  campaign: referralCampaignSchema.nullable(),
});

export type ReferralEnvelope = z.infer<typeof referralEnvelopeSchema>;
