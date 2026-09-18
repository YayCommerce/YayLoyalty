/** Points/balance number formatting. Ledger deltas use formatSignedPoints; money uses parseWPDecimal. */

/** Values at or above this magnitude switch to the compact "100k+" form. */
const COMPACT_THRESHOLD = 100_000;

/** Plain grouped integer — "12,400", "-1,000". */
export function formatPoints(value: number): string {
  return value.toLocaleString();
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
