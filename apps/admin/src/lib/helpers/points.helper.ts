/**
 * Shared points/balance number formatting — used anywhere a points figure is
 * displayed (Customers, Dashboard, ledger history, ...), not just one page.
 *
 * Two other helpers format numbers but don't fit here: `formatSignedPoints`
 * (ledger.helper.ts) prefixes `+` on positives, correct for a ledger delta but
 * wrong for a balance; `parseWPDecimal` (format.helper.ts) applies WooCommerce
 * currency decimals/separators, for money, not points.
 */

/** Values at or above this magnitude switch to the compact "100k+" form. */
const COMPACT_THRESHOLD = 100_000;

/** Plain grouped integer — "12,400", "-1,000". */
export function formatPoints(value: number): string {
  return value.toLocaleString();
}

/**
 * Compact form for large values: "150k+", "2M+", "1B+" — floored to the unit
 * shown, never rounded up, so the trailing "+" always reads as "at least
 * this many". Below `COMPACT_THRESHOLD` this is identical to formatPoints().
 *
 * Pair with `<PointsValue>` (components/ui/custom/points-value.tsx) rather
 * than calling this directly in a table cell — a compacted figure needs the
 * exact number available on hover, not just a shorter string.
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

/** Whether `formatCompactPoints` would shorten this value — i.e. whether a hover-for-exact affordance is worth showing. */
export function isCompactedPoints(value: number): boolean {
  return Math.abs(value) >= COMPACT_THRESHOLD;
}
