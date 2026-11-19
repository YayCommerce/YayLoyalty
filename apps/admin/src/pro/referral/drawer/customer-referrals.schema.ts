import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';
import { referralSettingsSchema } from '../referral-settings.schema';
import { referralListItemSchema } from '../referrals.schema';

/** Mirrors `CustomerReferralsRestController::get_referrals()`: a page of the advocate's referrals plus their link, totals and the program's terms. */
export const customerReferralsSchema = paginatedSchema(
  referralListItemSchema.extend({
    coupon_expires_at: z.string().nullable(),
    terms: z.record(z.string(), z.unknown()),
    first_order: referralListItemSchema.shape.first_order.unwrap().extend({ used_code: z.boolean() }).nullable(),
  }),
).extend({
  link: z.string().nullable(),
  counts: z.object({
    referred: z.number().int(),
    rewarded: z.number().int(),
    waiting: z.number().int(),
    points_earned: z.number().int(),
    revenue_referred: z.string(),
  }),
  terms: referralSettingsSchema.pick({
    enabled: true,
    discount_type: true,
    discount_amount: true,
    advocate_points: true,
    coupon_days: true,
  }),
});

export type CustomerReferrals = z.infer<typeof customerReferralsSchema>;

export type CustomerReferral = CustomerReferrals['data'][number];
