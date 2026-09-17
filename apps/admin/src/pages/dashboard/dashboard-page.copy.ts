import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, parseWPTime, utcToDate } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { pctDelta, pointsToCurrency } from '@/lib/points-math/points-math';
import type { DashboardRange, DashboardSeriesPoint } from '@/lib/schema/dashboard.schema';

/**
 * The dashboard's sentence builders — every tile sub-line, delta line and
 * denominator in one reviewable place. Pure: no React, no fetch.
 *
 * Null rules (artboard 2a): a count of 0 is a real measurement and renders
 * as "0"; a ratio with no denominator renders as "—"; a change with no
 * previous period reads "no previous period", never 0%.
 */

export const RANGE_LABEL = (range: DashboardRange) => sprintf(_n('%d day', '%d days', range, 'yayloyalty'), range);

/** "last 30 days" */
export function rangeSubline(range: DashboardRange): string {
  return sprintf(__('last %s', 'yayloyalty'), RANGE_LABEL(range));
}

/** "▲ 3.2% vs prev 30 days" | "▼ 0.9% vs prev 30 days" | "no previous period" */
export function deltaLine(
  delta: number | null,
  range: DashboardRange,
): { text: string; direction: 'up' | 'down' | 'flat' | 'none' } {
  if (delta === null) {
    return { text: __('no previous period', 'yayloyalty'), direction: 'none' };
  }

  const vs = sprintf(__('vs prev %s', 'yayloyalty'), RANGE_LABEL(range));
  const pct = `${Math.abs(delta).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;

  if (delta > 0) return { text: `▲ ${pct} ${vs}`, direction: 'up' };
  if (delta < 0) return { text: `▼ ${pct} ${vs}`, direction: 'down' };

  return { text: sprintf(__('unchanged %s', 'yayloyalty'), vs), direction: 'flat' };
}

/** "212 of 1,190 orders" */
export function ordersSubline(withPoints: number, total: number): string {
  return sprintf(__('%1$s of %2$s orders', 'yayloyalty'), formatPoints(withPoints), formatPoints(total));
}

/** "871,500 pts at 100 pts = $1" — names the valuation rate so the approximation is visible; "871,500 pts outstanding" without one. */
export function liabilitySubline(points: number, pointsPerCurrencyUnit: number): string {
  if (pointsPerCurrencyUnit <= 0) {
    return sprintf(__('%s pts outstanding', 'yayloyalty'), formatPoints(points));
  }

  return sprintf(
    __('%1$s pts at %2$s pts = %3$s', 'yayloyalty'),
    formatPoints(points),
    formatPoints(pointsPerCurrencyUnit),
    parseWPCurrency(1),
  );
}

/** "6 most recent of 41,200 events today" — only when the panel is not the whole story. */
export function activityDenominator(shown: number, totalToday: number): string | null {
  if (totalToday <= shown) return null;

  return sprintf(__('%1$d most recent of %2$s events today', 'yayloyalty'), shown, formatPoints(totalToday));
}

/** "Last updated 14:05 · next 15:05" for the cache strip. Inputs are UTC MySQL strings. */
export function cacheStripTimes(generatedAt: string, nextRefreshAt: string | null): string {
  const at = (utc: string) => parseWPTime(utcToDate(utc));
  const updated = sprintf(__('Last updated %s', 'yayloyalty'), at(generatedAt));

  return nextRefreshAt ? `${updated} · ${sprintf(__('next %s', 'yayloyalty'), at(nextRefreshAt))}` : updated;
}

/** True when every point in the series is zero — the chart shows its empty overlay but keeps its axes. */
export function isSeriesEmpty(series: DashboardSeriesPoint[]): boolean {
  return series.every((p) => p.issued === 0 && p.redeemed === 0);
}

/**
 * Tile lines that replace the tidy 30-day delta when a notice makes it
 * misleading (artboards 6a #3, 6b #2). Pure: returns strings or nothing.
 */
export function noticeTileOverrides(
  series: DashboardSeriesPoint[],
  range: DashboardRange,
  pointsJump: { yesterday_points: number } | null,
  defaultCampaignPaused: boolean,
  pointsPerCurrencyUnit: number,
): Partial<Record<'points_issued' | 'liability' | 'redemption_rate', string>> {
  if (pointsJump) {
    const overnightAmount = pointsToCurrency(pointsJump.yesterday_points, pointsPerCurrencyUnit);
    const overnight = overnightAmount === null ? null : parseWPCurrency(overnightAmount);

    return {
      points_issued: sprintf(__('▲ %s of it yesterday', 'yayloyalty'), formatPoints(pointsJump.yesterday_points)),
      ...(overnight ? { liability: sprintf(__('▲ %s overnight', 'yayloyalty'), overnight) } : {}),
      // Not "unchanged": the real delta may be non-zero. The point is that the jump cannot have moved it.
      redemption_rate: __('not moved by the jump — this rate counts orders, not points', 'yayloyalty'),
    };
  }

  if (defaultCampaignPaused && range === 30) {
    const collapse = sevenDayCollapse(series);

    if (collapse !== null) {
      return {
        points_issued: sprintf(
          __('▼ %s%% in the last 7 days', 'yayloyalty'),
          collapse.toLocaleString(undefined, { maximumFractionDigits: 0 }),
        ),
      };
    }
  }

  return {};
}

/**
 * Percentage fall of the last 7 complete days' issuance against the 7
 * before, whole percent; null unless there was a fall to show — a fall that
 * rounds to 0% is not a fall. Today is excluded — it is a partial day and
 * would overstate the collapse every morning.
 */
export function sevenDayCollapse(series: DashboardSeriesPoint[]): number | null {
  if (series.length < 15) return null;

  const sum = (points: DashboardSeriesPoint[]) => points.reduce((acc, p) => acc + p.issued, 0);
  const delta = pctDelta(sum(series.slice(-8, -1)), sum(series.slice(-15, -8)), 0);

  return delta === null || delta >= 0 ? null : -delta;
}
