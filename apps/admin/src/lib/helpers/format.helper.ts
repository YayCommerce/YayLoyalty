import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';

export function parseWPDate(date: Date | string | undefined) {
  const { formats } = getSettings();
  return dateI18n(formats.date, date);
}

/**
 * "2 days ago"-style relative time for a stored UTC datetime string
 * (`YYYY-MM-DD HH:mm:ss`, no timezone). Parsed as UTC explicitly — a bare
 * `new Date('2026-09-01 12:00:00')` is read in the browser's local time by
 * most engines, which would silently shift "2 days ago" by the site's UTC
 * offset. One date path: this is the only relative-time formatter, built on
 * the same `@wordpress/date` `parseWPDate`/`parseWPTime` already use.
 */
export function parseWPRelativeDate(date: string) {
  return humanTimeDiff(utcToDate(date));
}

/** A stored UTC MySQL datetime (`YYYY-MM-DD HH:mm:ss`) as a Date — the one parsing rule for every consumer. */
export function utcToDate(utc: string): Date {
  return new Date(utc.replace(' ', 'T') + 'Z');
}

/**
 * Format a site-calendar date (`YYYY-MM-DD`, no time — e.g. a dashboard
 * series bucket) without any timezone shift. A bare date string handed to
 * `dateI18n` is read as *browser-local* midnight and then rendered in the
 * site zone, which moves it a day whenever the two differ; pinning it to
 * noon UTC and rendering in UTC keeps the calendar day the server meant.
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
 * Splits a stored `YYYY-MM-DD HH:mm:ss` UTC datetime string (every
 * `starts_at`/`ends_at` on the wire — see campaign.schema.ts) into the
 * `{ date, time }` pair a `DatePicker` + `<Input type="time">` pair needs.
 * `null`/empty in, empty components out — campaigns' schedule bounds are
 * nullable ("always on").
 *
 * Paired with `joinStoredDateTime` below as the ONE conversion point for this
 * shape (campaign editor, phase-05's Schedule section): read the raw stored
 * string via `watch()`, split here for display, join here on change. Never a
 * bare `new Date('2026-11-27 00:00:00')` — most engines parse that as
 * browser-local, silently shifting the value by the site's UTC offset (see
 * code-standards.md §4 and `parseWPRelativeDate`'s comment above).
 */
export function splitStoredDateTime(value: string | null | undefined): { date: string; time: string } {
  if (!value) {
    return { date: '', time: '' };
  }

  const [date = '', time = ''] = value.split(' ');

  // HH:mm for the <input type="time">; the stored value's seconds are
  // rejoined (as :00) by joinStoredDateTime, never carried through the input.
  return { date, time: time.slice(0, 5) };
}

/** Reverse of `splitStoredDateTime` — a time-less date defaults to midnight; no date returns `null`. */
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
