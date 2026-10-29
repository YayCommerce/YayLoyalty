import { useMemo, useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import type { DashboardRange, DashboardStats } from '@/lib/schema/dashboard.schema';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import { ProGate } from '@/components/pro/ProGate';
import type { DashboardProSlots } from '@/pages/dashboard/dashboard-page.pro-slots';
import { useDashboardProQuery } from './dashboard-pro.queries';
import { DashboardCampaignBreakdownToggle } from './DashboardCampaignBreakdownToggle';
import { DashboardTopCampaigns } from './DashboardTopCampaigns';

/** Above this many members the breakdown starts off: five series over big values is unreadable at 280px. */
const BREAKDOWN_DEFAULT_OFF_ABOVE = 10_000;

/**
 * The single Pro export for the dashboard. Owns the second request and the
 * breakdown toggle, and hands the page its slots through a render prop so
 * the Lite layout stays the one tree in both tiers.
 */
export function DashboardProPanels({
  range,
  stats,
  children,
}: {
  range: DashboardRange;
  stats: DashboardStats;
  children: (slots: DashboardProSlots) => ReactNode;
}) {
  const [breakdown, setBreakdown] = useState(stats.members.value <= BREAKDOWN_DEFAULT_OFF_ABOVE);
  const query = useDashboardProQuery(range);
  const data = query.data;

  const extraSeries = useMemo(() => {
    if (!breakdown || !data) return [];

    // Keys are ids, never names: they become CSS variable names in ChartStyle.
    return data.top_campaigns.map((c) => ({
      key: `campaign-${c.campaign_id}`,
      label: c.name,
      data: data.campaign_series
        .filter((p) => p.campaign_id === c.campaign_id)
        .map((p) => ({ date: p.date, issued: p.issued })),
    }));
  }, [breakdown, data]);

  return (
    <>
      {children({
        chartControls: <DashboardCampaignBreakdownToggle on={breakdown} onChange={setBreakdown} />,
        extraSeries,
        // A failed Pro request must not masquerade as "no campaigns yet".
        sidePanels: query.isError ? (
          <ErrorState message={__('Could not load the Pro panels.', 'yayloyalty')} onRetry={() => query.refetch()} />
        ) : query.isPending ? (
          <ProSidePanelsSkeleton />
        ) : (
          <DashboardTopCampaigns campaigns={data?.top_campaigns ?? []} range={range} />
        ),
      })}
    </>
  );
}

/** One card, three rows — the shape of Top campaigns, so first paint does not reflow. */
function ProSidePanelsSkeleton() {
  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          {__('Top campaigns', 'yayloyalty')}
          <ProGate inline />
        </CardTitle>
      </CardHeader>
      <div className="flex flex-col gap-2.5">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-3 w-full" />
        ))}
      </div>
    </Card>
  );
}
