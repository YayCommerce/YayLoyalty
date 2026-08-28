import { dateI18n, getSettings } from '@wordpress/date';

export function parseWPDate(date: Date | string | undefined) {
  const { formats } = getSettings();
  return dateI18n(formats.date, date);
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

export function parseWPDecimal(number: number) {
  const { thousand_sep, decimal_sep, num_decimals } = window.yayLoyaltyMeta.wcMeta.currency_data;
  const formattedNumber = number
    .toFixed(num_decimals)
    .replace(/\.00$/, '')
    .replace(/\B(?=(\d{3})+(?!\d))/g, thousand_sep)
    .replace(/(\d+)\.(\d{2})$/, `$1${decimal_sep}$2`);

  return formattedNumber;
}
