import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

import { pointsShare, toAdvocateCell, toFriendCell } from '@/pro/referral/drawer/customer-referrals.rows';
import type { CustomerReferral } from '@/pro/referral/drawer/customer-referrals.schema';

const USD = { currency: 'USD', symbol: '$', position: 'left', num_decimals: 2, thousand_sep: ',', decimal_sep: '.' };

function referral(overrides: Partial<CustomerReferral>): CustomerReferral {
  return {
    id: 1,
    status: 'pending',
    reject_reason: null,
    hold_until: null,
    created_at: '2026-11-01 10:00:00',
    updated_at: '2026-11-01 10:00:00',
    coupon_expires_at: null,
    advocate: { id: 2, name: 'Daniel', email: 'daniel@example.test' },
    friend: { id: 3, name: 'Priya', email: 'priya@example.test' },
    advocate_points: 1000,
    terms: { discount_type: 'fixed', discount_amount: '10' },
    first_order: null,
    ...overrides,
  };
}

const order = (used_code: boolean) => ({ id: 9, number: '4166', total: '148.00', currency: 'USD', edit_url: '', used_code });

beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: USD } } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('customer drawer referral rows @P-5', () => {
  test('the advocate cell shows paid points, points on hold, or a dash', () => {
    expect(toAdvocateCell(referral({ status: 'rewarded' }))).toBe('+1,000');
    expect(toAdvocateCell(referral({ status: 'on_hold' }))).toBe('+1,000 on hold');
    expect(toAdvocateCell(referral({ status: 'pending' }))).toBe('—');
    expect(toAdvocateCell(referral({ status: 'rejected' }))).toBe('—');
  });

  test('the friend cell says whether the first order used the discount, or that it lapsed', () => {
    expect(toFriendCell(referral({ status: 'rewarded', first_order: order(true) }))).toBe('$10.00 · used');
    expect(toFriendCell(referral({ status: 'rejected', first_order: order(false) }))).toBe('$10.00 · not used');
    expect(toFriendCell(referral({ status: 'expired' }))).toBe('$10.00 · expired');
    expect(toFriendCell(referral({ status: 'rejected' }))).toBe('—');
    expect(toFriendCell(referral({ status: 'expired', terms: { discount_type: 'percent', discount_amount: '15' } }))).toBe(
      '15% · expired',
    );
  });

  test('the points share is of the lifetime total, and 0 with none', () => {
    const counts = { referred: 3, rewarded: 2, waiting: 1, points_earned: 1000, revenue_referred: '210.00' };

    expect(pointsShare(counts, 14300)).toBe(7);
    expect(pointsShare(counts, 0)).toBe(0);
  });
});
