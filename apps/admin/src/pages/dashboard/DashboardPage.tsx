import { useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useDashboardQuery, useRefreshDashboard } from '@/lib/queries/dashboard.queries';
import { findPointsJump } from '@/lib/schema/dashboard-notice.schema';
import type { DashboardRange, DashboardResponse, DashboardStats } from '@/lib/schema/dashboard.schema';
import { cn } from '@/lib/utils';
import { useDevScenario } from '@/hooks/useDevScenario';
import { ErrorState } from '@/components/ui/custom/error-state';
import PageLayout from '@/components/layout/PageLayout';
import { DashboardProPanels } from '@/pro';
import { DashboardAnomalyPanel } from './components/DashboardAnomalyPanel';
import { DashboardCacheStrip } from './components/DashboardCacheStrip';
import { DashboardEmptyState } from './components/DashboardEmptyState';
import { DashboardNoticeStrip } from './components/DashboardNoticeStrip';
import { DashboardPointsChart } from './components/DashboardPointsChart';
import { DashboardProTeasers } from './components/DashboardProTeasers';
import { DashboardRecentActivity } from './components/DashboardRecentActivity';
import { DashboardSkeleton } from './components/DashboardSkeleton';
import { DashboardStatsGrid } from './components/DashboardStatsGrid';
import { DashboardTopEarners } from './components/DashboardTopEarners';
import { noticeTileOverrides, rangeSubline } from './dashboard-page.copy';
import type { DashboardProSlots } from './dashboard-page.pro-slots';

/**
 * The landing page: composition only, with a single Pro/Lite branch.
 * `?scenario=` swaps in a fixture on the dev server only; there is no UI control.
 */
export default function DashboardPage() {
  const [range, setRange] = useState<DashboardRange>(30);
  const scenario = useDevScenario();

  const query = useDashboardQuery(range, scenario);
  const refresh = useRefreshDashboard(range);
  const data = query.data;

  const isEmptyStore = !!data && data.stats.members.value === 0 && data.stats.points_issued.value === 0;
  const pointsJump = data ? findPointsJump(data.notices) : null;
  const tileOverrides = data
    ? noticeTileOverrides(
        data.series,
        data.range,
        pointsJump?.data ?? null,
        data.notices.some((n) => n.key === 'default_campaign_paused'),
        data.stats.liability.points_per_currency_unit,
      )
    : {};
  const isDimmed = query.isPlaceholderData || (query.isFetching && !query.isPending);

  return (
    <PageLayout
      className="max-w-7xl"
      title={__('Dashboard', 'yayloyalty')}
      description={
        query.isPending
          ? sprintf(
              /* translators: %s: the selected range, e.g. "last 30 days". */
              __('Loading the %s…', 'yayloyalty'),
              rangeSubline(range),
            )
          : rangeSubline(range)
      }
    >
      {/* The thin bar under the header is the only page-level loading indicator. */}
      <div
        aria-hidden
        className={cn('bg-muted -mt-3 h-0.5 w-full overflow-hidden rounded', !query.isPending && 'invisible')}
      >
        <div className="bg-primary h-full w-1/3 animate-pulse" />
      </div>

      {data && <DashboardNoticeStrip notices={data.notices} />}

      {query.isError && (
        <ErrorState message={__('Could not load the dashboard.', 'yayloyalty')} onRetry={() => query.refetch()} />
      )}

      {query.isPending && !query.isError && <DashboardSkeleton range={range} onRangeChange={setRange} />}

      {data && (
        <div className={cn('flex flex-col gap-4', isDimmed && 'opacity-60 transition-opacity')}>
          {data.cached && (
            <DashboardCacheStrip
              generatedAt={data.generated_at}
              nextRefreshAt={data.next_refresh_at}
              onRefresh={() => refresh.mutate()}
              isRefreshing={refresh.isPending}
            />
          )}

          {isEmptyStore && (
            <DashboardEmptyState pointsPerCurrencyUnit={data.stats.liability.points_per_currency_unit} />
          )}

          {/* Both sides hand the same slots to the same layout; Lite never imports from @/pro. */}
          {IS_PRO_BUILD ? (
            <DashboardProPanels range={range} stats={data.stats} scenario={scenario}>
              {(slots) => (
                <DashboardBody
                  data={data}
                  range={range}
                  onRangeChange={setRange}
                  tileOverrides={tileOverrides}
                  slots={slots}
                />
              )}
            </DashboardProPanels>
          ) : (
            <DashboardProTeasers>
              {(slots) => (
                <DashboardBody
                  data={data}
                  range={range}
                  onRangeChange={setRange}
                  tileOverrides={tileOverrides}
                  slots={slots}
                />
              )}
            </DashboardProTeasers>
          )}

          {pointsJump && (
            <DashboardAnomalyPanel
              data={pointsJump.data}
              pointsPerCurrencyUnit={data.stats.liability.points_per_currency_unit}
            />
          )}

          <DashboardRecentActivity activity={data.recent_activity} />
        </div>
      )}
    </PageLayout>
  );
}

/** Tiles, chart and side column; where the Pro slots plug in. */
function DashboardBody({
  data,
  range,
  onRangeChange,
  tileOverrides,
  slots,
}: {
  data: DashboardResponse;
  range: DashboardRange;
  onRangeChange: (range: DashboardRange) => void;
  tileOverrides: Partial<Record<keyof DashboardStats, ReactNode>>;
  slots: DashboardProSlots;
}) {
  return (
    <>
      <DashboardStatsGrid stats={data.stats} range={data.range} deltaOverrides={tileOverrides}>
        {slots.referralsTile}
      </DashboardStatsGrid>

      <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <DashboardPointsChart
          series={data.series}
          range={range}
          onRangeChange={onRangeChange}
          extraSeries={slots.extraSeries}
        >
          {slots.chartControls}
        </DashboardPointsChart>
        <div className="flex flex-col gap-4">
          <DashboardTopEarners earners={data.top_earners} />
          {slots.sidePanels}
        </div>
      </div>
    </>
  );
}
