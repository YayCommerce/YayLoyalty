import { _n, sprintf } from '@wordpress/i18n';

import { pointsToCurrency } from '@/lib/points-math/points-math';
import { currencyDecimals, parseWPCurrency } from './format.helper';

/** Points/balance number formatting, and the one points → money label. */

/** Values at or above this magnitude switch to the compact "100k+" form. */
const COMPACT_THRESHOLD = 100_000;

/** Plain grouped integer — "12,400", "-1,000". */
export function formatPoints(value: number): string {
  return value.toLocaleString();
}

/** "1 point", "12,400 points": the count with its plural-aware noun. */
export function pointsPhrase(count: number): string {
  /* translators: %s: number of points, formatted. */
  return sprintf(_n('%s point', '%s points', count, 'yayloyalty'), formatPoints(count));
}

/**
 * "150k+", "2M+": floored, never rounded up, so "+" reads as "at least".
 * Pair with <PointsValue> in a cell so the exact number is available on hover.
 */
export function formatCompactPoints(value: number): string {
  const abs = Math.abs(value);

  if (abs < COMPACT_THRESHOLD) {
    return formatPoints(value);
  }

  const sign = value < 0 ? '-' : '';

  if (abs < 1_000_000) {
    return `${sign}${Math.floor(abs / 1_000)}k+`;
  }

  if (abs < 1_000_000_000) {
    return `${sign}${Math.floor(abs / 1_000_000)}M+`;
  }

  return `${sign}${Math.floor(abs / 1_000_000_000)}B+`;
}

/**
 * Points as store money, e.g. "$98.50" — rounded by the same rule as the
 * server (`PointsMath::points_to_currency`) at the store's decimals, then
 * formatted. `null` when the rate is unconfigured (0).
 */
export function formatPointsAsMoney(points: number, pointsPerCurrencyUnit: number): string | null {
  const amount = pointsToCurrency(points, pointsPerCurrencyUnit, currencyDecimals());

  return amount === null ? null : parseWPCurrency(amount);
}

/** Whether formatCompactPoints would shorten this value. */
export function isCompactedPoints(value: number): boolean {
  return Math.abs(value) >= COMPACT_THRESHOLD;
}
