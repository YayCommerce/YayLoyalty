import { afterEach, describe, expect, it, vi } from 'vitest';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPointsAsMoney, pointsPerCurrencyLabel } from '@/lib/helpers/points.helper';

/**
 * Money labels against three store currencies. The decimal separator must
 * apply at any number of decimals, and a points amount must round by the
 * server's rule (half-up) before `toFixed` formats it.
 */
function useCurrency(currency_data: Record<string, unknown>) {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data } } });
}

const USD = { symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 };
const VND = { symbol: '₫', position: 'right_space', thousand_sep: '.', decimal_sep: ',', num_decimals: 0 };
const KWD = { symbol: 'KD', position: 'left_space', thousand_sep: '.', decimal_sep: ',', num_decimals: 3 };

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('parseWPCurrency', () => {
  it('formats a 2-decimal store', () => {
    useCurrency(USD);
    expect(parseWPCurrency(1234567.5)).toBe('$1,234,567.50');
    expect(parseWPCurrency(-1234.5)).toBe('$-1,234.50');
  });

  it('formats a 0-decimal store without a separator', () => {
    useCurrency(VND);
    expect(parseWPCurrency(1234567)).toBe('1.234.567 ₫');
  });

  it('applies the decimal separator to a 3-decimal store and never groups the fraction', () => {
    useCurrency(KWD);
    expect(parseWPCurrency(1234.567)).toBe('KD 1.234,567');
  });

  it('drops the sign when the amount rounds to zero', () => {
    useCurrency(USD);
    expect(parseWPCurrency(-0.001)).toBe('$0.00');
  });
});

describe('formatPointsAsMoney', () => {
  it('rounds half-up like the server, not like toFixed on a binary float', () => {
    useCurrency(USD);
    // 1.005 is 1.00499… in binary: toFixed(2) alone says 1.00, the server says 1.01.
    expect(formatPointsAsMoney(1005, 1000)).toBe('$1.01');
  });

  it('rounds to the store currency, not a fixed 2 decimals', () => {
    useCurrency(KWD);
    expect(formatPointsAsMoney(12346, 10000)).toBe('KD 1,235');
    useCurrency(VND);
    expect(formatPointsAsMoney(9361, 100)).toBe('94 ₫');
  });

  it('is null without a conversion rate', () => {
    useCurrency(USD);
    expect(formatPointsAsMoney(500, 0)).toBeNull();
  });
});

describe('pointsPerCurrencyLabel', () => {
  it('reads a purchase rate against one unit of the store currency, singular and plural', () => {
    useCurrency(USD);
    expect(pointsPerCurrencyLabel(1)).toBe('1 pt per $1.00');
    expect(pointsPerCurrencyLabel(2)).toBe('2 pts per $1.00');
    expect(pointsPerCurrencyLabel(1.5)).toBe('1.5 pts per $1.00');
  });
});
