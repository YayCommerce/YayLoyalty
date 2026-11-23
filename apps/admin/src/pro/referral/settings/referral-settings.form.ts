import { z } from 'zod';
import { __ } from '@wordpress/i18n';

import {
  REFERRAL_DISCOUNT_TYPES,
  REFERRAL_LIMITS,
  ReferralSettings,
  ReferralSettingsUpdate,
} from '../referral-settings.schema';

const days = (min: number, max: number, message: string) => z.number().int().min(min, message).max(max, message);

/** The Settings tab's form: the stored settings, with money as numbers for the inputs. */
export const referralSettingsFormSchema = z
  .object({
    enabled: z.boolean(),
    discount_type: z.enum(REFERRAL_DISCOUNT_TYPES),
    discount_amount: z.number().gt(0, __('Enter an amount above 0.', 'yayloyalty')),
    min_order: z.number().min(0),
    coupon_days: days(1, REFERRAL_LIMITS.COUPON_DAYS_MAX, __('Enter 1 to 365 days.', 'yayloyalty')),
    advocate_points: z.number().int().min(1, __('Enter at least 1 point.', 'yayloyalty')),
    hold_days: days(0, REFERRAL_LIMITS.HOLD_DAYS_MAX, __('Enter 0 to 90 days.', 'yayloyalty')),
    exclude_other_coupons: z.boolean(),
    cookie_days: days(1, REFERRAL_LIMITS.COOKIE_DAYS_MAX, __('Enter 1 to 365 days.', 'yayloyalty')),
  })
  .refine(
    (values) =>
      values.discount_type !== 'percent' ||
      (values.discount_amount >= 1 && values.discount_amount <= REFERRAL_LIMITS.PERCENT_MAX),
    { path: ['discount_amount'], message: __('Percentage must be 1 to 100.', 'yayloyalty') },
  );

export type ReferralSettingsFormValues = z.infer<typeof referralSettingsFormSchema>;

export type ReferralSettingsDirtyFields = Partial<Record<keyof ReferralSettingsFormValues, boolean>>;

export function referralSettingsDefaults(settings: ReferralSettings): ReferralSettingsFormValues {
  return {
    ...settings,
    discount_amount: Number(settings.discount_amount),
    min_order: Number(settings.min_order),
  };
}

/** The save payload: only the edited fields, money back as strings. */
export function buildReferralSettingsUpdate(
  values: ReferralSettingsFormValues,
  dirty: ReferralSettingsDirtyFields,
): ReferralSettingsUpdate {
  const update: Record<string, unknown> = {};

  for (const [field, value] of Object.entries(values)) {
    if (dirty[field as keyof ReferralSettingsFormValues]) {
      update[field] =
        typeof value === 'number' && (field === 'discount_amount' || field === 'min_order') ? String(value) : value;
    }
  }

  return update as ReferralSettingsUpdate;
}
