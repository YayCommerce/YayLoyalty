/**
 * Mirrors YayLoyalty\Utils\PointsMath, the source of truth.
 * A zero denominator yields null, never 0. Rounding follows PHP round(), so a preview shows what the engine awards.
 * tests/fixtures/points-math-vectors.json holds test vectors for every export.
 */

export const ROUNDING = { DOWN: 'down', NEAREST: 'nearest', UP: 'up' } as const;
export type RoundingMode = (typeof ROUNDING)[keyof typeof ROUNDING];

/**
 * PHP round(): half away from zero, never -0. Math.round rounds half toward +∞.
 * Trimmed to 15 significant digits first, as PHP ≤ 8.3 pre-rounds, so 1.005 × 100 = 100.49999999999999 still rounds up.
 * Past 1e15 the value is returned untouched, as in every PHP release.
 */
function roundTo(value: number, decimals: number): number {
  const factor = 10 ** decimals;
  const magnitude = Math.abs(value) * factor;

  if (magnitude >= 1e15) {
    return value;
  }

  const rounded = Math.round(Number(magnitude.toPrecision(15))) / factor;

  return rounded === 0 ? 0 : Math.sign(value) * rounded;
}

/** Mirrors PointsMath::round_points; an unknown mode rounds down. `mode` is string because PHP accepts any. */
export function roundPoints(raw: number, mode: string): number {
  // `+ 0` turns the -0 Math.ceil/Math.floor produce into 0 without hiding NaN.
  switch (mode) {
    case ROUNDING.UP:
      return Math.ceil(raw) + 0;
    case ROUNDING.NEAREST:
      return Math.trunc(roundTo(raw, 0)) + 0;
    default:
      return Math.floor(raw) + 0;
  }
}

/** Mirrors PointsMath::pct_delta; null with no previous, |previous| as denominator so a negative base keeps the sign. */
export function pctDelta(current: number, previous: number, decimals = 1): number | null {
  if (previous === 0) {
    return null;
  }

  return roundTo(((current - previous) / Math.abs(previous)) * 100, decimals);
}

/** Mirrors PointsMath::pct_of; null when `whole` is 0. */
export function pctOf(part: number, whole: number): number | null {
  if (whole === 0) {
    return null;
  }

  return roundTo((part / whole) * 100, 1);
}

/** Mirrors PointsMath::points_to_currency; null when no usable rate exists. `decimals` is the store currency's. */
export function pointsToCurrency(points: number, pointsPerCurrencyUnit: number, decimals: number): number | null {
  if (pointsPerCurrencyUnit <= 0) {
    return null;
  }

  return roundTo(points / pointsPerCurrencyUnit, decimals);
}
