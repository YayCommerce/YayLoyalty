/**
 * Mirrors YayLoyalty\Utils\PointsMath, the source of truth.
 * A zero denominator yields null, never 0. Rounding follows PHP round(), so a preview shows what the engine awards.
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

/** Mirrors PointsMath::SNAP_DECIMALS, the source of truth. */
const SNAP_DECIMALS = 9;

/**
 * Mirrors PointsMath::round_points, the source of truth. An unknown mode rounds down. `mode` is string because PHP accepts any.
 * Snaps first, so float error (0.57 × 100 = 56.999…) never crosses a whole number.
 */
export function roundPoints(raw: number, mode: string): number {
  const snapped = roundTo(raw, SNAP_DECIMALS);

  // `+ 0` turns the -0 Math.ceil/Math.floor produce into 0 without hiding NaN.
  switch (mode) {
    case ROUNDING.UP:
      return Math.ceil(snapped) + 0;
    case ROUNDING.NEAREST:
      return Math.trunc(roundTo(snapped, 0)) + 0;
    default:
      return Math.floor(snapped) + 0;
  }
}

/** Mirrors PointsMath::pct_delta, the source of truth. Null with no previous, |previous| as denominator so a negative base keeps the sign. */
export function pctDelta(current: number, previous: number, decimals = 1): number | null {
  if (previous === 0) {
    return null;
  }

  return roundTo(((current - previous) / Math.abs(previous)) * 100, decimals);
}

/** Mirrors PointsMath::pct_of, the source of truth. Null when `whole` is 0. */
export function pctOf(part: number, whole: number): number | null {
  if (whole === 0) {
    return null;
  }

  return roundTo((part / whole) * 100, 1);
}
