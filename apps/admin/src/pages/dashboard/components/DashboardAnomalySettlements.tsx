import { __, _n, sprintf } from '@wordpress/i18n';

import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import type { PointsJumpData, PointsJumpWhatChanged } from '@/lib/schema/dashboard-notice.schema';

/**
 * Three ways to settle a jump, doing nothing included; the dashboard does
 * not choose. Figures are named as the day's issuance, never the excess:
 * no per-campaign baseline exists.
 */
export function DashboardAnomalySettlements({
  edits,
  day,
  threshold,
  pointsPerCurrencyUnit,
}: {
  edits: PointsJumpWhatChanged[];
  /** Formatted site-calendar day (`parseWPDay`). */
  day: string;
  threshold: PointsJumpData['threshold'];
  pointsPerCurrencyUnit: number;
}) {
  const points = edits.reduce((sum, e) => sum + e.points_issued, 0);
  const orderCount = edits.reduce((sum, e) => sum + e.orders_affected, 0);
  const worth = formatPointsAsMoney(points, pointsPerCurrencyUnit) ?? '';
  const orders = sprintf(
    /* translators: %s: number of orders, formatted. */
    _n('%s order', '%s orders', orderCount, 'yayloyalty'),
    formatPoints(orderCount),
  );
  const issued = sprintf(
    /* translators: 1: points, 2: order count phrase, 3: day. */
    __('the %1$s points issued across %2$s on %3$s', 'yayloyalty'),
    formatPoints(points),
    orders,
    day,
  );

  const settlements = [
    {
      title: _n('Put the campaign back as it was', 'Put the campaigns back as they were', edits.length, 'yayloyalty'),
      body: worth
        ? sprintf(
            /* translators: 1: issuance phrase, 2: currency amount. */
            __('and leave %1$s — worth %2$s to those customers.', 'yayloyalty'),
            issued,
            worth,
          )
        : sprintf(
            /* translators: %s: issuance phrase. */
            __('and leave %s.', 'yayloyalty'),
            issued,
          ),
    },
    {
      title: __('Put it back and reverse the points', 'yayloyalty'),
      body: sprintf(
        /* translators: %s: issuance phrase. */
        __(
          '— a bulk adjustment of −%s, which those customers will notice. Reverse only the share you did not intend.',
          'yayloyalty',
        ),
        issued,
      ),
    },
    {
      title: __('Keep it as it is', 'yayloyalty'),
      body: __('— if this was the intention, the campaign is working.', 'yayloyalty'),
    },
  ];

  return (
    <div className="flex flex-col gap-3 border-t pt-4">
      <div className="text-sm font-semibold">{__('Three ways to settle it', 'yayloyalty')}</div>
      <ol className="grid gap-3 text-sm md:grid-cols-3">
        {settlements.map((s) => (
          <li key={s.title} className="bg-muted/40 rounded-md p-3">
            <span className="font-medium">{s.title}</span> <span className="text-muted-foreground">{s.body}</span>
          </li>
        ))}
      </ol>
      <p className="text-muted-foreground text-xs">
        <span className="font-medium">{__('What counts as a jump:', 'yayloyalty')}</span>{' '}
        {sprintf(
          /* translators: 1: the multiple of the daily average; 2: the minimum number of orders that day. */
          __(
            '%1$s× the 30-day daily average, on a day with at least %2$d orders. A sale day will trip it and that is fine — the notice states the cause, and dismissing it takes one click.',
            'yayloyalty',
          ),
          threshold.multiple.toLocaleString(),
          threshold.min_orders,
        )}
      </p>
    </div>
  );
}
