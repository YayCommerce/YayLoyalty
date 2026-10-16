import { Link } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { parseWPDay, parseWPTime, utcToDate } from '@/lib/helpers/format.helper';
import { DAILY_POINTS_CHART_FORMAT, formatPoints } from '@/lib/helpers/points.helper';
import type { PointsJumpData } from '@/lib/schema/dashboard-notice.schema';
import { BarChart } from '@/components/ui/bar-chart';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { type ChartConfig } from '@/components/ui/chart';
import { ANOMALY_PANEL_ID } from '../dashboard-page.notice-actions';
import { DashboardAnomalySettlements } from './DashboardAnomalySettlements';

const CONFIG: ChartConfig = { issued: { label: __('Issued', 'yayloyalty'), color: 'var(--chart-1)' } };

/**
 * Daily totals with yesterday highlighted, beside who edited a campaign
 * that day. Never says what changed: no diff exists.
 */
export function DashboardAnomalyPanel({ data }: { data: PointsJumpData }) {
  const first = data.daily[0]?.date;
  const edits = data.what_changed;

  return (
    <Card id={ANOMALY_PANEL_ID} className="scroll-mt-24">
      <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
        <div className="flex flex-col gap-3">
          <CardHeader className="p-0">
            <CardTitle>{__('Points issued per day', 'yayloyalty')}</CardTitle>
            <CardDescription>
              {first
                ? sprintf(
                    /* translators: 1: the first day shown; 2: yesterday; 3: number of days shown. */
                    __('%1$s – %2$s · %3$d days', 'yayloyalty'),
                    parseWPDay(first),
                    parseWPDay(data.yesterday),
                    data.daily.length,
                  )
                : parseWPDay(data.yesterday)}
            </CardDescription>
          </CardHeader>
          <BarChart
            config={CONFIG}
            data={data.daily}
            xKey="date"
            format={DAILY_POINTS_CHART_FORMAT}
            className="h-55"
            referenceY={data.daily_average}
            radius={3}
            opacity={(d) => (d.date === data.yesterday ? 1 : 0.35)}
          />
          <div className="text-muted-foreground flex flex-wrap gap-x-4 text-xs">
            <span>
              {sprintf(
                /* translators: %s: average points issued per day, formatted. */
                __('Typical day — about %s', 'yayloyalty'),
                formatPoints(data.daily_average),
              )}
            </span>
            <span className="text-foreground font-medium">
              {sprintf(
                /* translators: 1: yesterday's date; 2: points issued yesterday, formatted. */
                __('%1$s — %2$s', 'yayloyalty'),
                parseWPDay(data.yesterday),
                formatPoints(data.yesterday_points),
              )}
            </span>
          </div>
        </div>

        <div className="flex flex-col gap-3">
          <CardHeader className="p-0">
            <CardTitle>
              {edits.length === 1
                ? sprintf(
                    /* translators: %s: the time the campaign was edited. */
                    __('What changed at %s', 'yayloyalty'),
                    parseWPTime(utcToDate(edits[0].updated_at)),
                  )
                : __('What changed', 'yayloyalty')}
            </CardTitle>
          </CardHeader>
          {edits.length === 0 ? (
            <p className="text-muted-foreground text-sm">{__('No campaign was edited on this day.', 'yayloyalty')}</p>
          ) : (
            edits.map((edit) => (
              <dl key={edit.campaign_id} className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                <dt className="text-muted-foreground">{__('Campaign', 'yayloyalty')}</dt>
                <dd className="font-medium">{edit.name}</dd>
                <dt className="text-muted-foreground">{__('Edited by', 'yayloyalty')}</dt>
                <dd>
                  {edits.length > 1
                    ? sprintf(
                        /* translators: 1: person, 2: time. */
                        __('%1$s at %2$s', 'yayloyalty'),
                        edit.updated_by_name || __('Unknown', 'yayloyalty'),
                        parseWPTime(utcToDate(edit.updated_at)),
                      )
                    : edit.updated_by_name || __('Unknown', 'yayloyalty')}
                </dd>
                <dt className="text-muted-foreground">{__('Issued that day', 'yayloyalty')}</dt>
                <dd className="tabular-nums">
                  {sprintf(
                    /* translators: 1: order count, 2: points. */
                    __('%1$s · +%2$s pts', 'yayloyalty'),
                    sprintf(
                      /* translators: %s: number of orders, formatted. */
                      _n('%s order', '%s orders', edit.orders_affected, 'yayloyalty'),
                      formatPoints(edit.orders_affected),
                    ),
                    formatPoints(edit.points_issued),
                  )}
                </dd>
              </dl>
            ))
          )}
          <div className="flex flex-wrap gap-4 text-sm">
            {edits.length > 0 && (
              <Can cap={CAP.MANAGE_CAMPAIGNS}>
                <Link to="/campaigns" className="text-primary hover:underline">
                  {_n('Open the campaign', 'Open the campaigns', edits.length, 'yayloyalty')}
                </Link>
              </Can>
            )}
            <a href={data.orders_admin_url} className="text-primary hover:underline">
              {sprintf(
                /* translators: %s: yesterday's date. */
                __('See the orders from %s', 'yayloyalty'),
                parseWPDay(data.yesterday),
              )}
            </a>
          </div>
        </div>
      </div>
      {edits.length > 0 && (
        <DashboardAnomalySettlements edits={edits} day={parseWPDay(data.yesterday)} threshold={data.threshold} />
      )}
    </Card>
  );
}
