import { describe, expect, test } from 'vitest';

import {
  buildReferralSettingsUpdate,
  referralSettingsDefaults,
  referralSettingsFormSchema,
} from '@/pro/referral/settings/referral-settings.form';

const stored = {
  enabled: true,
  discount_type: 'fixed' as const,
  discount_amount: '10',
  min_order: '50',
  coupon_days: 30,
  advocate_points: 1000,
  hold_days: 14,
  exclude_other_coupons: true,
  cookie_days: 30,
};

const valid = referralSettingsDefaults(stored);

function firstError(values: object): string | undefined {
  const result = referralSettingsFormSchema.safeParse({ ...valid, ...values });

  return result.success ? undefined : result.error.issues[0]?.message;
}

describe('referral-settings.form @P-3', () => {
  test('accepts the stored settings', () => {
    expect(firstError({})).toBeUndefined();
  });

  test('blocks out-of-range values with the design messages', () => {
    expect(firstError({ discount_type: 'percent', discount_amount: 150 })).toBe('Percentage must be 1 to 100.');
    expect(firstError({ discount_amount: 0 })).toBe('Enter an amount above 0.');
    expect(firstError({ hold_days: 91 })).toBe('Enter 0 to 90 days.');
    expect(firstError({ cookie_days: 0 })).toBe('Enter 1 to 365 days.');
    expect(firstError({ advocate_points: 0 })).toBe('Enter at least 1 point.');
  });

  test('allows a fixed amount over 100', () => {
    expect(firstError({ discount_amount: 150 })).toBeUndefined();
  });

  test('sends only the edited fields, money as strings', () => {
    const update = buildReferralSettingsUpdate(
      { ...valid, min_order: 60, hold_days: 7 },
      { min_order: true, hold_days: true },
    );

    expect(update).toEqual({ min_order: '60', hold_days: 7 });
  });
});
