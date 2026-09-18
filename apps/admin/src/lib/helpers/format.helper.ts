import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';

export function parseWPDate(date: Date | string | undefined) {
  const { formats } = getSettings();
  return dateI18n(formats.date, date);
}

/** "2 days ago" for a stored UTC datetime. Parsed as UTC explicitly: a bare new Date() reads it as local. */
export function parseWPRelativeDate(date: string) {
  return humanTimeDiff(utcToDate(date));
}

/** A stored UTC MySQL datetime as a Date; the one parsing rule. */
export function utcToDate(utc: string): Date {
  return new Date(utc.replace(' ', 'T') + 'Z');
}

/**
 * Formats a site-calendar date without a timezone shift: dateI18n reads a
 * bare date as browser-local midnight, which moves it a day; pinning to
 * noon UTC keeps the day the server meant.
 */
export function parseWPDay(day: string, format?: string) {
  const { formats } = getSettings();
  return dateI18n(format ?? formats.date, `${day}T12:00:00Z`, 'UTC');
}

export function parseWPTime(date: Date | string) {
  const { formats } = getSettings();
  return dateI18n(formats.time, date);
}

export function parseWPCurrency(price: string | number) {
  if (typeof price === 'string') {
    price = parseFloat(price);
  }

  const { symbol, position, thousand_sep, decimal_sep, num_decimals } = window.yayLoyaltyMeta.wcMeta.currency_data;

  const formattedPrice = price
    .toFixed(num_decimals)
    .replace(/\B(?=(\d{3})+(?!\d))/g, thousand_sep)
    .replace(/(\d+)\.(\d{2})$/, `$1${decimal_sep}$2`);

  switch (position) {
    case 'left':
      return `${symbol}${formattedPrice}`;
    case 'right':
      return `${formattedPrice}${symbol}`;
    case 'left_space':
      return `${symbol} ${formattedPrice}`;
    case 'right_space':
      return `${formattedPrice} ${symbol}`;
    default:
      return `${formattedPrice}`;
  }
}

/**
 * Splits a stored UTC datetime into the { date, time } pair a date picker
 * and time input take; null in, empty parts out. With joinStoredDateTime,
 * the one conversion point for this shape: never a bare new Date() on the
 * string, which most engines read as browser-local (code-standards.md §4).
 */
export function splitStoredDateTime(value: string | null | undefined): { date: string; time: string } {
  if (!value) {
    return { date: '', time: '' };
  }

  const [date = '', time = ''] = value.split(' ');

  // HH:mm only; joinStoredDateTime restores the :00 seconds.
  return { date, time: time.slice(0, 5) };
}

/** Reverse of splitStoredDateTime; a time-less date is midnight, no date is null. */
export function joinStoredDateTime(date: string, time: string): string | null {
  if (!date) {
    return null;
  }

  return `${date} ${time || '00:00'}:00`;
}

export function parseWPDecimal(number: number) {
  const { thousand_sep, decimal_sep, num_decimals } = window.yayLoyaltyMeta.wcMeta.currency_data;
  const formattedNumber = number
    .toFixed(num_decimals)
    .replace(/\.00$/, '')
    .replace(/\B(?=(\d{3})+(?!\d))/g, thousand_sep)
    .replace(/(\d+)\.(\d{2})$/, `$1${decimal_sep}$2`);

  return formattedNumber;
}
