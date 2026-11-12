import { afterEach, describe, expect, test, vi } from 'vitest';

import {
  dateToStored,
  formatStoreDecimal,
  formatStoreNumber,
  formatWPCurrency,
  joinStoredDateTime,
  splitStoredDateTime,
  utcToDate,
} from '@/lib/helpers/format.helper';

const USD = { currency: 'USD', symbol: '$', position: 'left', num_decimals: 2, thousand_sep: ',', decimal_sep: '.' };
const VND = { currency: 'VND', symbol: '₫', position: 'right', num_decimals: 0, thousand_sep: '.', decimal_sep: ',' };

function givenStoreCurrency(currency_data: Record<string, unknown>) {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data } } });
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('format.helper @baseline', () => {
  test('reads and writes stored datetimes as UTC, whatever the viewer zone', () => {
    expect(utcToDate('2026-03-01 00:00:00').toISOString()).toBe('2026-03-01T00:00:00.000Z');
    expect(dateToStored(new Date('2026-03-01T00:00:00Z'))).toBe('2026-03-01 00:00:00');
  });

  test('formats money in the store currency position and separators', () => {
    givenStoreCurrency(USD);
    expect(formatWPCurrency('1234.5')).toBe('$1,234.50');

    givenStoreCurrency({ ...USD, position: 'right_space' });
    expect(formatWPCurrency(1234.5)).toBe('1,234.50 $');

    givenStoreCurrency(VND);
    expect(formatWPCurrency(12400)).toBe('12.400₫');
  });

  test('never shows a minus on an amount that rounds to zero', () => {
    givenStoreCurrency(USD);
    expect(formatStoreNumber(-0.001, 2)).toBe('0.00');
    expect(formatStoreNumber(-1234.5, 2)).toBe('-1,234.50');
    expect(formatStoreNumber(1234567, 0)).toBe('1,234,567');
  });

  test('drops trailing zeros from a rate', () => {
    givenStoreCurrency(VND);
    expect(formatStoreDecimal(1.5, 4)).toBe('1,5');
    expect(formatStoreDecimal(1, 3)).toBe('1');
    expect(formatStoreDecimal(0.004, 3)).toBe('0,004');
  });

  test('splits and joins a stored datetime for the date and time inputs', () => {
    expect(splitStoredDateTime('2026-03-01 09:30:00')).toEqual({ date: '2026-03-01', time: '09:30' });
    expect(splitStoredDateTime(null)).toEqual({ date: '', time: '' });
    expect(joinStoredDateTime('2026-03-01', '')).toBe('2026-03-01 00:00:00');
    expect(joinStoredDateTime('', '10:00')).toBeNull();
  });
});
