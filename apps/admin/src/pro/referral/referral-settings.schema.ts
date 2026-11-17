import { z } from 'zod';

/** Mirrors `ReferralSettings::DISCOUNT_FIXED` / `DISCOUNT_PERCENT` (includes/Pro/Referrals/ReferralSettings.php). */
export const REFERRAL_DISCOUNT_TYPES = ['fixed', 'percent'] as const;

/** Mirrors the bounds in `ReferralSettings::SPEC` and `PERCENT_MAX`. */
export const REFERRAL_LIMITS = {
  PERCENT_MAX: 100,
  COUPON_DAYS_MAX: 365,
  HOLD_DAYS_MAX: 90,
  COOKIE_DAYS_MAX: 365,
} as const;

/** Mirrors `ReferralSettings::get()`. Money is a string in the store's base currency. */
export const referralSettingsSchema = z.object({
  enabled: z.boolean(),
  discount_type: z.enum(REFERRAL_DISCOUNT_TYPES),
  discount_amount: z.string(),
  min_order: z.string(),
  coupon_days: z.number().int(),
  advocate_points: z.number().int(),
  hold_days: z.number().int(),
  exclude_other_coupons: z.boolean(),
  cookie_days: z.number().int(),
});

export type ReferralSettings = z.infer<typeof referralSettingsSchema>;

export type ReferralDiscountType = ReferralSettings['discount_type'];

/** POST body: only the fields that changed. */
export type ReferralSettingsUpdate = Partial<ReferralSettings>;
