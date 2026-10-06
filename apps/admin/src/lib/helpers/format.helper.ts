import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';

/**
 * A stored UTC datetime, or a Date, in the site's date format. A string is
 * parsed as UTC here rather than handed to dateI18n, which reads a bare
 * string as browser-local and so shifts a midnight instant to the previous
 * day for any viewer east of the site zone.
 */
export function parseWPDate(date: Date | string | undefined) {
  const { formats } = getSettings();
  return dateI18n(formats.date, typeof date === 'string' ? utcToDate(date) : date);
}

/** "2 days ago" for a stored UTC datetime. Parsed as UTC explicitly: a bare new Date() reads it as local. */
export function parseWPRelativeDate(date: string) {
  return humanTimeDiff(utcToDate(date));
}

/** A stored UTC datetime in the site's date format; the same as parseWPDate(), typed for strings only. */
export function formatStoredDate(utc: string) {
  return parseWPDate(utc);
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

/** A stored UTC datetime, or a Date, in the site's time format; strings are parsed as UTC, as in parseWPDate(). */
export function parseWPTime(date: Date | string) {
  const { formats } = getSettings();
  return dateI18n(formats.time, typeof date === 'string' ? utcToDate(date) : date);
}

/** The store currency code (`get_woocommerce_currency()`), e.g. USD. */
export function storeCurrency(): string {
  return window.yayLoyaltyMeta.wcMeta.currency_data.currency;
}

/** The store currency's decimals (`wc_get_price_decimals()`), the precision every money figure rounds to. */
export function currencyDecimals(): number {
  return window.yayLoyaltyMeta.wcMeta.currency_data.num_decimals;
}

/**
 * A money amount in the store's currency format. Formats only: pass a value
 * already rounded by its owner (e.g. `pointsToCurrency`), since `toFixed`
 * rounds the binary float and can land a half-cent the wrong way.
 */
export function parseWPCurrency(price: string | number) {
  if (typeof price === 'string') {
    price = parseFloat(price);
  }

  const { symbol, position, thousand_sep, decimal_sep, num_decimals } = window.yayLoyaltyMeta.wcMeta.currency_data;

  // Split before grouping so the separator applies at any number of decimals
  // and grouping never reaches the fraction.
  const [whole, fraction] = Math.abs(price).toFixed(num_decimals).split('.');
  const sign = price < 0 && Number(`${whole}.${fraction ?? 0}`) !== 0 ? '-' : '';
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousand_sep);
  const formattedPrice = `${sign}${grouped}${fraction === undefined ? '' : `${decimal_sep}${fraction}`}`;

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
 * string, which most engines read as browser-local.
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
