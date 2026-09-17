/**
 * The points arithmetic the admin shares with PHP — a same-named mirror of
 * `YayLoyalty\Utils\PointsMath` (includes/Utils/PointsMath.php). Framework-free
 * on purpose so a storefront bundle can import it too.
 *
 * Rule shared by every ratio here: a zero denominator yields null, never 0 —
 * callers render "—" / "no previous period" for null and would otherwise show
 * a misleading 0%.
 *
 * Every export has a snake_case key in `tests/fixtures/points-math-vectors.json`
 * (repo root); both test suites iterate that file, so adding a rule on one side
 * without the other fails that side's tests. PHP is the source of truth.
 *
 * Because these follow PHP's `round()` rather than JS `Math.round`/`toFixed`,
 * a preview that lands on a binary half-boundary (0.29 pts/unit × 50) can
 * show a different whole number than the raw JS expression would — that is
 * the point: it shows what the engine awards. PHP 8.4 changed `round()` for
 * such inputs, so on those hosts the engine and this mirror can differ by one
 * point at exactly those boundaries; the shared fixture excludes them.
 *
 * Formatting is not here: `lib/helpers/points.helper.ts` turns these numbers
 * into strings.
 */

export const ROUNDING = { DOWN: 'down', NEAREST: 'nearest', UP: 'up' } as const;
export type RoundingMode = (typeof ROUNDING)[keyof typeof ROUNDING];

/**
 * PHP `round()`: half away from zero, with a `-0` never returned. JS
 * `Math.round` is half toward +∞ (`Math.round(-0.5)` is `-0`, PHP gives -1),
 * so round the magnitude and restore the sign.
 *
 * The scaled value is first trimmed to 15 significant digits so a decimal
 * that sits a hair under a half in binary (1.005 × 100 = 100.49999999999999)
 * still rounds up to 1.01. That is PHP ≤ 8.3's pre-rounding; PHP 8.4 reaches
 * the same answer for every input in the shared fixture by a different route,
 * and the fixture deliberately excludes the inputs where the two PHP
 * algorithms part ways. Once the scaled magnitude reaches 1e15 every PHP
 * release returns the value untouched, so we do too.
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

/**
 * Raw points to a whole number, ONCE, at order level. Half away from zero.
 * An unknown mode rounds down — the conservative direction for a liability.
 * Mirrors `PointsMath::round_points`; `mode` is `string` because the PHP side
 * accepts any string.
 */
export function roundPoints(raw: number, mode: string): number {
  // `+ 0` turns the `-0` Math.ceil/Math.floor produce for (-1, 0) into 0 without hiding NaN.
  switch (mode) {
    case ROUNDING.UP:
      return Math.ceil(raw) + 0;
    case ROUNDING.NEAREST:
      // PHP casts the rounded float to int; only matters past 1e15, where round() is a no-op.
      return Math.trunc(roundTo(raw, 0)) + 0;
    default:
      return Math.floor(raw) + 0;
  }
}

/**
 * Percentage change from `previous` to `current`; null when there is no
 * previous figure. The denominator is |previous| so the sign follows the
 * direction of travel even on a negative base. Mirrors `PointsMath::pct_delta`.
 */
export function pctDelta(current: number, previous: number, decimals = 1): number | null {
  if (previous === 0) {
    return null;
  }

  return roundTo(((current - previous) / Math.abs(previous)) * 100, decimals);
}

/** `part` as a percentage of `whole`, 1 dp; null when `whole` is 0. Mirrors `PointsMath::pct_of`. */
export function pctOf(part: number, whole: number): number | null {
  if (whole === 0) {
    return null;
  }

  return roundTo((part / whole) * 100, 1);
}

/**
 * Points in store currency at `pointsPerCurrencyUnit`, 2 dp; null when no
 * usable rate exists. Mirrors `PointsMath::points_to_currency`.
 */
export function pointsToCurrency(points: number, pointsPerCurrencyUnit: number): number | null {
  if (pointsPerCurrencyUnit <= 0) {
    return null;
  }

  return roundTo(points / pointsPerCurrencyUnit, 2);
}
