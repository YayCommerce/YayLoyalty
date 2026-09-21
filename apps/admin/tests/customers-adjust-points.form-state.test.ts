import { describe, expect, it } from 'vitest';

import {
  bulkEffectiveCount,
  bulkLiabilityProjection,
  bulkTotalPoints,
  canSubmit,
  isConfirmSatisfied,
  isStaleSnapshot,
  isTypoGuardActive,
  liabilityContribution,
  liabilityDelta,
  newBalance,
  pointsToClearDeficit,
  signedPoints,
  typoGuardFloor,
  TYPO_GUARD_FLOOR_CURRENCY_UNITS,
  TYPO_GUARD_FLOOR_POINTS_FALLBACK,
  type AdjustFormState,
} from '@/pages/customers/components/customers-adjust-points.form-state';

function state(overrides: Partial<AdjustFormState> = {}): AdjustFormState {
  return {
    direction: 'add',
    amount: 0,
    confirmAmount: 0,
    reason: '',
    adjustLifetime: true,
    showCustomerNote: false,
    customerNote: '',
    notifyByEmail: false,
    ...overrides,
  };
}

describe('signedPoints / newBalance', () => {
  it('adds for direction "add"', () => {
    expect(signedPoints(state({ direction: 'add', amount: 500 }))).toBe(500);
    expect(newBalance(state({ direction: 'add', amount: 500 }), 9850)).toBe(10350);
  });

  it('subtracts for direction "remove"', () => {
    expect(signedPoints(state({ direction: 'remove', amount: 500 }))).toBe(-500);
    expect(newBalance(state({ direction: 'remove', amount: 500 }), 300)).toBe(-200);
  });
});

describe('typoGuardFloor', () => {
  it('is money-denominated when a conversion rate is configured', () => {
    expect(typoGuardFloor(100)).toBe(TYPO_GUARD_FLOOR_CURRENCY_UNITS * 100);
  });

  it('falls back to a flat points floor when the rate is unconfigured', () => {
    expect(typoGuardFloor(0)).toBe(TYPO_GUARD_FLOOR_POINTS_FALLBACK);
  });
});

describe('isTypoGuardActive', () => {
  it('is inactive below the floor even with zero lifetime earned', () => {
    // A brand-new customer: lifetime_earned 0 must not make every grant trip the guard.
    expect(isTypoGuardActive(state({ amount: 500 }), 0, 100)).toBe(false);
  });

  it('is active above both the floor and 3x lifetime earned', () => {
    expect(isTypoGuardActive(state({ amount: 50000 }), 14300, 100)).toBe(true);
  });

  it('is inactive when above the floor but not above 3x lifetime earned', () => {
    expect(isTypoGuardActive(state({ amount: 2000 }), 10000, 100)).toBe(false);
  });

  it('uses the flat fallback floor when the rate is unconfigured', () => {
    expect(isTypoGuardActive(state({ amount: 500 }), 0, 0)).toBe(false);
    expect(isTypoGuardActive(state({ amount: 1500 }), 0, 0)).toBe(true);
  });
});

describe('isConfirmSatisfied', () => {
  it('requires an exact match', () => {
    expect(isConfirmSatisfied(state({ amount: 50000, confirmAmount: 50000 }))).toBe(true);
    expect(isConfirmSatisfied(state({ amount: 50000, confirmAmount: 5000 }))).toBe(false);
  });
});

describe('pointsToClearDeficit', () => {
  it('is the magnitude of a negative balance', () => {
    expect(pointsToClearDeficit(-400)).toBe(400);
  });

  it('floors at 0 for a non-negative balance', () => {
    expect(pointsToClearDeficit(0)).toBe(0);
    expect(pointsToClearDeficit(120)).toBe(0);
  });
});

describe('liabilityContribution / liabilityDelta', () => {
  it('a negative balance contributes nothing', () => {
    expect(liabilityContribution(-50)).toBe(0);
  });

  it('is never negative even when the starting balance is already below zero (regression: previously produced "$-0.50")', () => {
    // balance -50, remove 100 more -> balanceAfter -150; nothing was left to reduce.
    expect(liabilityDelta(-50, -150)).toBe(0);
  });

  it('reduces by only the pre-adjustment balance when crossing from positive to negative', () => {
    // balance 50, remove 100 -> balanceAfter -50; only the 50 that was positive stops counting.
    expect(liabilityDelta(50, -50)).toBe(-50);
  });

  it('adds only the portion above zero when crossing from negative back to positive', () => {
    // balance -50, add 80 -> balanceAfter 30; only 30 newly counts (regression: previously reported the full 80).
    expect(liabilityDelta(-50, 30)).toBe(30);
  });

  it('is the plain signed delta when both sides stay non-negative', () => {
    expect(liabilityDelta(9850, 10350)).toBe(500);
    expect(liabilityDelta(340, 40)).toBe(-300);
  });
});

describe('isStaleSnapshot', () => {
  const record = { user_id: 1, name: 'Daniel', email: 'd@example.com', balance: 9850, lifetime_earned: 14300 };

  it('is false when neither balance nor lifetime_earned changed', () => {
    expect(isStaleSnapshot(record, { ...record })).toBe(false);
  });

  it('is true when only balance changed', () => {
    expect(isStaleSnapshot(record, { ...record, balance: 10120 })).toBe(true);
  });

  it('is true when only lifetime_earned changed', () => {
    expect(isStaleSnapshot(record, { ...record, lifetime_earned: 14570 })).toBe(true);
  });

  it('is true when both changed', () => {
    expect(isStaleSnapshot(record, { ...record, balance: 10120, lifetime_earned: 14570 })).toBe(true);
  });

  it('is false when either snapshot is missing', () => {
    expect(isStaleSnapshot(null, record)).toBe(false);
    expect(isStaleSnapshot(record, null)).toBe(false);
  });
});

describe('canSubmit', () => {
  const baseBlockers = { lifetimeEarned: 14300, pointsPerCurrencyUnit: 100 };

  it('blocks a zero amount', () => {
    expect(canSubmit(state({ amount: 0, reason: 'ok' }), baseBlockers)).toBe(false);
  });

  it('blocks an empty reason', () => {
    expect(canSubmit(state({ amount: 500, reason: '   ' }), baseBlockers)).toBe(false);
  });

  it('blocks while stale', () => {
    expect(canSubmit(state({ amount: 500, reason: 'ok' }), { ...baseBlockers, isStale: true })).toBe(false);
  });

  it('blocks while unverified', () => {
    expect(canSubmit(state({ amount: 500, reason: 'ok' }), { ...baseBlockers, isUnverified: true })).toBe(false);
  });

  it('blocks bulk without acknowledgement', () => {
    expect(
      canSubmit(state({ amount: 500, reason: 'ok' }), { ...baseBlockers, needsAcknowledgement: true }),
    ).toBe(false);
    expect(
      canSubmit(state({ amount: 500, reason: 'ok' }), {
        ...baseBlockers,
        needsAcknowledgement: true,
        acknowledged: true,
      }),
    ).toBe(true);
  });

  it('blocks an active typo guard until the confirm field matches', () => {
    const guardState = state({ amount: 50000, reason: 'ok', confirmAmount: 0 });
    expect(canSubmit(guardState, baseBlockers)).toBe(false);
    expect(canSubmit({ ...guardState, confirmAmount: 50000 }, baseBlockers)).toBe(true);
  });

  it('allows a plain valid adjustment with no guard active', () => {
    expect(canSubmit(state({ amount: 500, reason: 'ok' }), baseBlockers)).toBe(true);
  });
});

describe('bulkTotalPoints', () => {
  it('multiplies the per-customer amount by the effective count', () => {
    expect(bulkTotalPoints(state({ amount: 500 }), 340)).toBe(170000);
  });
});

describe('bulkEffectiveCount', () => {
  it('is the raw client count when the selection is not a filter ("all") selection', () => {
    expect(bulkEffectiveCount(false, 340, undefined, 5)).toBe(340);
    expect(bulkEffectiveCount(false, 340, 500, 5)).toBe(340);
  });

  it('subtracts excludedCount from the server COUNT once it has resolved', () => {
    expect(bulkEffectiveCount(true, 335, 340, 5)).toBe(335);
  });

  it(
    'falls back to the already-net client count while the server COUNT is unresolved, ' +
      'without subtracting excludedCount a second time (regression: previously under-counted by excludedCount)',
    () => {
      // count is selectionCount()'s own output: totalItems (340) - excludedIds.size (5) = 335, already net.
      expect(bulkEffectiveCount(true, 335, undefined, 5)).toBe(335);
    },
  );

  it('floors at 0', () => {
    expect(bulkEffectiveCount(true, 0, 3, 5)).toBe(0);
  });
});

describe('bulkLiabilityProjection', () => {
  it('projects a from/to range for Add with a known liability', () => {
    expect(bulkLiabilityProjection('add', 170000, 8715_00)).toEqual({ kind: 'range', from: 871500, to: 1041500 });
  });

  it('is delta-only for Remove even with a known liability (balances can cross zero)', () => {
    expect(bulkLiabilityProjection('remove', 170000, 871500)).toEqual({ kind: 'delta', delta: 170000 });
  });

  it('is delta-only for Add when liability is unknown (explicit-id branch)', () => {
    expect(bulkLiabilityProjection('add', 170000, null)).toEqual({ kind: 'delta', delta: 170000 });
  });

  it('is none when the total is zero', () => {
    expect(bulkLiabilityProjection('add', 0, 871500)).toEqual({ kind: 'none' });
  });
});
