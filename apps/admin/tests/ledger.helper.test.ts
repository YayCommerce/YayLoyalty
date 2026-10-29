import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { formatSignedPoints, signedPointsCsv } from '@/lib/helpers/ledger.helper';

const USD = { symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 };

beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: USD } } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

// Ledger rows sit beside a grouped balance, so the signed figure groups too; the CSV stays raw.
describe('signed points', () => {
  it.each([
    [12400, '+12,400'],
    [-1000, '-1,000'],
    [0, '0'],
  ])('formats %s as %s', (points, text) => {
    expect(formatSignedPoints(points)).toBe(text);
  });

  it('keeps the CSV cell ungrouped', () => {
    expect(signedPointsCsv(12400)).toBe('+12400');
    expect(signedPointsCsv(-1000)).toBe('-1000');
  });
});
