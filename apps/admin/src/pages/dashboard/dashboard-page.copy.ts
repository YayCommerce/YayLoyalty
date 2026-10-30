import { __, _n, sprintf } from '@wordpress/i18n';

import { formatPercent, formatStoreDecimal, formatWPTime, utcToDate } from '@/lib/helpers/format.helper';
import { formatCount, formatPoints } from '@/lib/helpers/points.helper';
import { pctDelta } from '@/lib/points-math/points-math';
import type { DashboardRange, DashboardSeriesPoint } from '@/lib/schema/dashboard.schema';

/**
 * The dashboard's sentence builders, pure. A count of 0 renders "0"; a
 * ratio with no denominator "—"; no previous period reads as such, never 0%.
 */

export const RANGE_LABEL = (range: DashboardRange) =>
  sprintf(
    /* translators: %d: number of days in the range. */
    _n('%d day', '%d days', range, 'yayloyalty'),
    range,
  );

/** "last 30 days" */
export function rangeSubline(range: DashboardRange): string {
  return sprintf(
    /* translators: %s: the range's length, e.g. "30 days". */
    __('last %s', 'yayloyalty'),
    RANGE_LABEL(range),
  );
}

/** "▲ 3.2% vs prev 30 days" | "▼ 0.9% vs prev 30 days" | "no previous period" */
export function deltaLine(
  delta: number | null,
  range: DashboardRange,
): { text: string; direction: 'up' | 'down' | 'flat' | 'none' } {
  if (delta === null) {
    return { text: __('no previous period', 'yayloyalty'), direction: 'none' };
  }

  const vs = sprintf(
    /* translators: %s: the range's length, e.g. "30 days". */
    __('vs prev %s', 'yayloyalty'),
    RANGE_LABEL(range),
  );
  const pct = formatPercent(Math.abs(delta), 1);

  if (delta > 0) return { text: `▲ ${pct} ${vs}`, direction: 'up' };
  if (delta < 0) return { text: `▼ ${pct} ${vs}`, direction: 'down' };

  return {
    text: sprintf(
      /* translators: %s: the period compared against, e.g. "vs prev 30 days". */
      __('unchanged %s', 'yayloyalty'),
      vs,
    ),
    direction: 'flat',
  };
}

/** "212 of 1,190 orders" */
export function ordersSubline(withRedemption: number, total: number): string {
  return sprintf(
    /* translators: 1: orders on which points were redeemed, formatted; 2: all orders in the range, formatted. */
    __('%1$s of %2$s orders', 'yayloyalty'),
    formatCount(withRedemption),
    formatCount(total),
  );
}

/** "6 most recent of 41,200 events today", only when the panel is not the whole story. */
export function activityDenominator(shown: number, totalToday: number): string | null {
  if (totalToday <= shown) return null;

  return sprintf(
    /* translators: 1: number of events shown; 2: all events today, formatted. */
    __('%1$d most recent of %2$s events today', 'yayloyalty'),
    shown,
    formatCount(totalToday),
  );
}

/** "Last updated 14:05 · next 15:05" for the cache strip. Inputs are UTC MySQL strings. */
export function cacheStripTimes(generatedAt: string, nextRefreshAt: string | null): string {
  const at = (utc: string) => formatWPTime(utcToDate(utc));
  const updated = sprintf(
    /* translators: %s: the time the figures were computed. */
    __('Last updated %s', 'yayloyalty'),
    at(generatedAt),
  );

  return nextRefreshAt
    ? `${updated} · ${sprintf(
        /* translators: %s: the time of the next refresh. */
        __('next %s', 'yayloyalty'),
        at(nextRefreshAt),
      )}`
    : updated;
}

/** Whether every point is zero; the chart then shows its empty overlay but keeps its axes. */
export function isSeriesEmpty(series: DashboardSeriesPoint[]): boolean {
  return series.every((p) => p.issued === 0 && p.redeemed === 0);
}

/** Tile lines that replace the 30-day delta when a notice makes it misleading. */
export function noticeTileOverrides(
  series: DashboardSeriesPoint[],
  range: DashboardRange,
  pointsJump: { yesterday_points: number } | null,
  defaultCampaignPaused: boolean,
): Partial<Record<'points_issued' | 'liability' | 'redemption_rate', string>> {
  if (pointsJump) {
    return {
      points_issued: sprintf(
        /* translators: %s: points issued yesterday, formatted. */
        __('▲ %s of it yesterday', 'yayloyalty'),
        formatPoints(pointsJump.yesterday_points),
      ),
      liability: sprintf(
        /* translators: %s: points issued yesterday, formatted. */
        __('▲ %s pts overnight', 'yayloyalty'),
        formatPoints(pointsJump.yesterday_points),
      ),
      // Not "unchanged": the delta may be non-zero; the jump just cannot have moved it.
      redemption_rate: __('not moved by the jump — this rate counts orders, not points', 'yayloyalty'),
    };
  }

  if (defaultCampaignPaused && range === 30) {
    const collapse = sevenDayCollapse(series);

    if (collapse !== null) {
      return {
        points_issued: sprintf(
          /* translators: %s: the percentage fall, a whole number. */
          __('▼ %s%% in the last 7 days', 'yayloyalty'),
          formatStoreDecimal(collapse, 0),
        ),
      };
    }
  }

  return {};
}

/**
 * Percentage fall of the last 7 complete days against the 7 before; null
 * unless there was a fall. Today is excluded: a partial day would overstate it.
 */
export function sevenDayCollapse(series: DashboardSeriesPoint[]): number | null {
  if (series.length < 15) return null;

  const sum = (points: DashboardSeriesPoint[]) => points.reduce((acc, p) => acc + p.issued, 0);
  const delta = pctDelta(sum(series.slice(-8, -1)), sum(series.slice(-15, -8)), 0);

  return delta === null || delta >= 0 ? null : -delta;
}
