import { parseWPCurrency, utcToDate } from '@/lib/helpers/format.helper';

/**
 * Points → money, using `customer.points_per_currency_unit`. `null` when the
 * rate is unconfigured (0) — only the raw rate is on the wire, so the guard
 * lives here.
 *
 * Shared by the expiry panel/stats (4b) — money is derived client-side
 * everywhere in this drawer, never pre-computed and stored.
 */
export function pointsToMoney(points: number, ratePerCurrencyUnit: number): string | null {
  return ratePerCurrencyUnit > 0 ? parseWPCurrency(points / ratePerCurrencyUnit) : null;
}

/**
 * Whole days from now until a stored UTC datetime, floored at 0. A plain
 * function, not inlined in a component: `Date.now()` is an impure call, and
 * ESLint's `react-hooks/purity` rule flags it inside a component/hook body.
 */
export function daysUntil(utcDatetime: string): number {
  return Math.max(0, Math.round((utcToDate(utcDatetime).getTime() - Date.now()) / (24 * 60 * 60 * 1000)));
}
