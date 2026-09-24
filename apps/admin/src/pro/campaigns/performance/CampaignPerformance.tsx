import { useEffect } from 'react';
import { HTTPError } from 'ky';
import { ChevronLeft, InfoIcon } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { useCampaignQuery } from '@/lib/queries/campaign.queries';
import {
  CampaignPerformance as CampaignPerformanceData,
  CampaignPerformanceTrigger,
  hasPerformancePage,
} from '@/lib/schema/campaign-performance.schema';
import { Campaign, CampaignDisplayStatus, resolveCampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import NotFoundPage from '@/pages/404';
import { conditionsLabel, givesLabel, scheduleLabel, statusLabel } from '../campaign-labels';
import { emptyReason } from './campaign-performance.copy';
import { useCampaignPerformanceQuery } from './campaign-performance.queries';
import { CampaignPerformanceChart, CampaignPerformanceChartSkeleton } from './CampaignPerformanceChart';
import { CampaignPerformanceEntries } from './CampaignPerformanceEntries';
import { CampaignPerformanceSidePanel } from './CampaignPerformanceSidePanel';
import { CampaignPerformanceTiles, CampaignPerformanceTilesSkeleton } from './CampaignPerformanceTiles';

function isNotFound(error: unknown): boolean {
  return error instanceof HTTPError && error.response.status === 404;
}

/**
 * Composes the campaign performance page — mirrors `CampaignEditor.tsx`'s
 * state-handling shape (invalid id / pending / error / 404, all before the
 * "real" render). Two independent queries: `useCampaignQuery` (header) and
 * `useCampaignPerformanceQuery` (everything below it) — a failure in the
 * summary never hides the header, since the header only depends on the
 * campaign query.
 *
 * A trigger outside `CAMPAIGN_PERFORMANCE_TRIGGERS` (e.g. `birthday`) 404s
 * from the loaded campaign alone — the SPA never even requests the summary.
 * The summary query's OWN 404 (an edge case: the campaign row disappearing
 * server-side between the two fetches) is treated the same way, since the
 * server enforces the identical trigger allowlist on that route.
 */
export function CampaignPerformance({ id }: { id: number }) {
  const navigate = useNavigate();
  const campaignQuery = useCampaignQuery(id);
  const summaryQuery = useCampaignPerformanceQuery(id);

  // react-router keeps the current scroll position on navigation (no full page load) — this
  // page opens scrolled to whatever the previous screen left, so reset it explicitly.
  useEffect(() => {
    window.scrollTo(0, 0);
  }, [id]);

  if (!(id > 0)) {
    return <NotFoundPage />;
  }

  if (campaignQuery.isPending) {
    return (
      <PageLayout className="max-w-6xl" title={<Skeleton className="h-8 w-64" />}>
        <Skeleton className="h-96 w-full" />
      </PageLayout>
    );
  }

  if (campaignQuery.isError) {
    if (isNotFound(campaignQuery.error)) {
      return <NotFoundPage />;
    }

    return (
      <PageLayout className="max-w-6xl" title={__('Campaign', 'yayloyalty')}>
        <ErrorState
          message={__('Could not load this campaign.', 'yayloyalty')}
          onRetry={() => campaignQuery.refetch()}
        />
      </PageLayout>
    );
  }

  const campaign = campaignQuery.data;

  if (!hasPerformancePage(campaign.trigger)) {
    return <NotFoundPage />;
  }

  const trigger = campaign.trigger;
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);

  const metaLine = [
    givesLabel(campaign.trigger, campaign.config),
    sprintf(/* translators: %d: the campaign's priority number. */ __('priority %d', 'yayloyalty'), campaign.priority),
    scheduleLabel(campaign.starts_at, campaign.ends_at),
    conditionsLabel(campaign.conditions),
  ].join(' · ');

  return (
    <>
      {/* Same wrapper CampaignEditor.tsx uses for its own back link, so the two screens line up. */}
      <div className="mx-auto flex max-w-6xl items-center gap-2 px-6 pt-6 text-sm">
        <Button type="button" variant="link" className="h-auto gap-1 p-0!" onClick={() => navigate('/campaigns')}>
          <ChevronLeft className="size-3.5" />
          {__('Campaigns', 'yayloyalty')}
        </Button>
      </div>

      <PageLayout
        className="max-w-6xl"
        title={
          <span className="flex flex-wrap items-center gap-2">
            {campaign.name}
            <Badge variant="outline" className="uppercase">
              {statusLabel(displayStatus)}
            </Badge>
          </span>
        }
        description={metaLine}
        actions={
          <Button variant="outline" onClick={() => navigate(`/campaigns/${id}/edit`)}>
            {__('Edit campaign', 'yayloyalty')}
          </Button>
        }
      >
        {summaryQuery.isPending ? (
          <CampaignPerformanceBodySkeleton trigger={trigger} />
        ) : summaryQuery.isError ? (
          isNotFound(summaryQuery.error) ? (
            <NotFoundPage />
          ) : (
            <ErrorState
              message={__("Could not load this campaign's performance.", 'yayloyalty')}
              onRetry={() => summaryQuery.refetch()}
            />
          )
        ) : (
          <CampaignPerformanceBody
            campaign={campaign}
            trigger={trigger}
            displayStatus={displayStatus}
            data={summaryQuery.data}
          />
        )}
      </PageLayout>
    </>
  );
}

function CampaignPerformanceBody({
  campaign,
  trigger,
  displayStatus,
  data,
}: {
  campaign: Campaign;
  trigger: CampaignPerformanceTrigger;
  displayStatus: CampaignDisplayStatus;
  data: CampaignPerformanceData;
}) {
  const navigate = useNavigate();
  const hasData = data.totals.entries_total > 0;

  return (
    <div className="flex flex-col gap-4">
      {!hasData && (
        <Alert variant="outline">
          <InfoIcon />
          <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
            <span>{emptyReason(trigger, displayStatus, campaign.starts_at)}</span>
            {displayStatus === 'scheduled' && (
              <Button
                variant="link"
                size="sm"
                className="h-auto p-0"
                onClick={() => navigate(`/campaigns/${campaign.id}/edit`)}
              >
                {__('Check its schedule', 'yayloyalty')}
              </Button>
            )}
          </AlertDescription>
        </Alert>
      )}

      <CampaignPerformanceTiles trigger={trigger} totals={data.totals} hasData={hasData} />

      <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
        <div className="min-w-0 flex-1">
          <CampaignPerformanceChart window={data.window} daily={data.daily} />
        </div>
        {trigger === 'purchase' && <CampaignPerformanceSidePanel skipped={data.skipped} estimate={data.estimate} />}
      </div>

      {/* `key` forces a remount on an in-place campaign-id change — react-router doesn't remount a
          matched element on a param-only change, so without this the table's own page/search
          state would leak into the new campaign's query. */}
      <CampaignPerformanceEntries
        key={campaign.id}
        campaignId={campaign.id}
        trigger={trigger}
        entriesTotal={data.totals.entries_total}
      />
    </div>
  );
}

function CampaignPerformanceBodySkeleton({ trigger }: { trigger: CampaignPerformanceTrigger }) {
  return (
    <div className="flex flex-col gap-4">
      <CampaignPerformanceTilesSkeleton trigger={trigger} />
      <CampaignPerformanceChartSkeleton />
      <Skeleton className="h-64 w-full" />
    </div>
  );
}
