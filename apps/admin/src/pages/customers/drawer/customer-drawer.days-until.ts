import { utcToDate } from '@/lib/helpers/format.helper';

/**
 * Whole days from now until a stored UTC datetime, floored at 0. A plain
 * function, not inlined in a component: `Date.now()` is an impure call, and
 * ESLint's `react-hooks/purity` rule flags it inside a component/hook body.
 */
export function daysUntil(utcDatetime: string): number {
  return Math.max(0, Math.round((utcToDate(utcDatetime).getTime() - Date.now()) / (24 * 60 * 60 * 1000)));
}
