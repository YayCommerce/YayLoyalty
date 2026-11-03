import { _n, sprintf } from '@wordpress/i18n';

import { currencyDecimals, formatStoreDecimal, formatStoreNumber, formatWPCurrency } from './format.helper';

/** Points/balance number formatting. */

/** Values at or above this magnitude switch to the compact "100k+" form. */
const COMPACT_THRESHOLD = 100_000;

/** Plain grouped integer with the store's separators — "12,400", "-1,000". For counts, days and other non-points numbers. */
export function formatCount(value: number): string {
  return formatStoreNumber(value, 0);
}

/** A points figure as a grouped integer, the same as formatCount(). */
export function formatPoints(value: number): string {
  return formatCount(value);
}

/** "1 point", "12,400 points": the count with its plural-aware noun. */
export function pointsPhrase(count: number): string {
  return sprintf(
    /* translators: %s: number of points, formatted. */
    _n('%s point', '%s points', count, 'yayloyalty'),
    formatPoints(count),
  );
}

/** "1 pt", "500 pts": the abbreviated form, for tight UI. */
export function shortPoints(points: number): string {
  return sprintf(
    /* translators: %s: a points amount, formatted. Abbreviated: this reads in tight UI. */
    _n('%s pt', '%s pts', points, 'yayloyalty'),
    formatPoints(points),
  );
}

/** "2 pts per $1.00", "1 pt per $2.00": a purchase rate as the spend pair an admin would enter. */
export function pointsPerCurrencyLabel(rate: number): string {
  const { amount, points } = ratePair(rate);

  return sprintf(
    /* translators: 1: points earned; 2: the amount spent to earn them, formatted in the store currency. */
    _n('%1$s pt per %2$s', '%1$s pts per %2$s', points, 'yayloyalty'),
    formatStoreDecimal(points, 6),
    formatWPCurrency(amount),
  );
}

/** The stored purchase rate (points per 1 currency unit) for "spend X to receive Y"; null when X is not above 0. */
export function rateFromSpend(spend: number, points: number): number | null {
  return spend > 0 ? points / spend : null;
}

/** Tolerance for "rate × spend is a whole number", as tight as PointsMath's 9-decimal snap: a pair is shown only when the engine pays it exactly. */
const WHOLE_EPSILON = 1e-9;

/**
 * Mirrors PointsMath::whole_pair(), the source of truth, plus the exact rate when no pair is whole: the admin
 * edits the rate, so it never shows the storefront's rounded fallback (PointsMath::rate_pair()).
 * The "spend X to receive Y" pair a stored rate reads as: 0.5 → 2 → 1, 0.75 → 4 → 3, 3 → 1 → 3.
 * Only the rate is stored, so 6 → 3 comes back as 2 → 1, the same rate.
 */
export function ratePair(rate: number): { amount: number; points: number } {
  if (!(rate > 0)) {
    return { amount: 1, points: 0 };
  }

  for (let amount = 1; amount <= 100; amount++) {
    const points = rate * amount;

    if (Math.abs(points - Math.round(points)) < WHOLE_EPSILON) {
      return { amount, points: Math.round(points) };
    }
  }

  // A larger spend that earns exactly 1 point (1 per 10,000 ₫); otherwise the exact rate per 1 unit.
  const factor = 10 ** currencyDecimals();
  const amount = Math.round(factor / rate) / factor;

  return amount > 0 && Math.abs(amount * rate - 1) < WHOLE_EPSILON
    ? { amount, points: 1 }
    : { amount: 1, points: rate };
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

/** Whether formatCompactPoints would shorten this value. */
export function isCompactedPoints(value: number): boolean {
  return Math.abs(value) >= COMPACT_THRESHOLD;
}
