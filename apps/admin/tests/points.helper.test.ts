import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { spendPairFromRate } from '@/lib/helpers/points.helper';

const VND = { symbol: '₫', position: 'right_space', thousand_sep: '.', decimal_sep: ',', num_decimals: 0 };

beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: VND } } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

// The pair is shown only when the engine pays it exactly; otherwise the rate per 1 unit.
describe('spendPairFromRate', () => {
  it.each([
    [2.5, { spend: 2, points: 5 }],
    [1 / 3, { spend: 3, points: 1 }],
    [0.05, { spend: 20, points: 1 }],
    [0.0001, { spend: 10000, points: 1 }],
    [2.000001, { spend: 1, points: 2.000001 }],
    [0.333333, { spend: 1, points: 0.333333 }],
  ])('reads %s as its pair', (rate, pair) => {
    expect(spendPairFromRate(rate)).toEqual(pair);
  });
});
