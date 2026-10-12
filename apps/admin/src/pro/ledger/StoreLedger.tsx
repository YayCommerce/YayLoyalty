import { useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Outlet } from 'react-router';

import { can, CAP } from '@/lib/access';
import { ledgerTypeLabel } from '@/lib/helpers/ledger.helper';
import { LEDGER_FILTER_TYPES, type LedgerFilterType } from '@/lib/schema/ledger.schema';
import { cn } from '@/lib/utils';
import { useCsvExport } from '@/hooks/useCsvExport';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { BackLink } from '@/components/ui/custom/back-link';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Pagination } from '@/components/ui/pagination';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { EXPORTING_LABEL } from '@/pages/customers/customers-page.export.copy';
import {
  fetchLedgerCampaignNames,
  useLedgerCampaignNames,
} from '@/pages/customers/drawer/customer-drawer.campaign-names';
import { FILTER_TYPE_ALL, FILTER_TYPE_LABEL } from '@/pages/customers/drawer/customer-drawer.copy';
import { useDrawerTabQueryState } from '@/pages/customers/drawer/customer-drawer.tab-query-state';
import { DrawerTabFilters } from '@/pages/customers/drawer/tabs/DrawerTabFilters';
import { campaignFilterOptions } from './store-ledger.campaign-options';
import {
  ACTION_EXPORT,
  BACK_LABEL,
  CAMPAIGN_FILTER_ALL,
  CAMPAIGN_FILTER_LABEL,
  EXPORT_EMPTY,
  EXPORT_ERROR_FALLBACK,
  LOAD_ERROR,
  PAGE_TITLE,
  pageDescription,
  SEARCH_PLACEHOLDER,
} from './store-ledger.copy';
import { exportStoreLedgerToCsv, fetchFilteredLedger } from './store-ledger.export';
import { useStoreLedgerQuery } from './store-ledger.queries';
import { StoreLedgerTable } from './StoreLedgerTable';

const PAGE_SIZE = 20;

/** The Select's value for "no campaign filter"; campaign ids are positive, so it cannot collide. */
const ALL_CAMPAIGNS = 'all';

const TYPE_LABELS: Record<LedgerFilterType, () => string> = {
  all: FILTER_TYPE_ALL,
  earn: () => ledgerTypeLabel('earn'),
  redeem: () => ledgerTypeLabel('redeem'),
  adjust: () => ledgerTypeLabel('adjust'),
  expire: () => ledgerTypeLabel('expire'),
};

/**
 * Every customer's ledger rows, with the drawer's filters plus campaign. The customer drawer opens
 * over it through the nested `ledger/:userId` route, so the filters survive opening and closing it.
 */
export function StoreLedger() {
  const queryClient = useQueryClient();
  const state = useDrawerTabQueryState<LedgerFilterType>('all');
  const [campaign, setCampaign] = useState(ALL_CAMPAIGNS);

  const filters = {
    search: state.pageParams.search,
    after: state.pageParams.after,
    before: state.pageParams.before,
    type: state.filter,
    campaignId: campaign === ALL_CAMPAIGNS ? undefined : Number(campaign),
  };
  const query = useStoreLedgerQuery({ ...filters, page: state.page, perPage: PAGE_SIZE });

  const campaignNames = useLedgerCampaignNames();
  const campaignOptions = useMemo(() => campaignFilterOptions(campaignNames), [campaignNames]);

  const totalPage = Math.max(1, query.data?.totalPage ?? 1);
  const { setPage } = state;

  // react-router keeps the scroll position across navigations, so the page would open wherever the Dashboard left it.
  useEffect(() => {
    window.scrollTo(0, 0);
  }, []);

  // Rows can vanish between requests; a page past the end would show an empty table with no way back.
  useEffect(() => {
    if (state.page > totalPage) setPage(totalPage);
  }, [state.page, totalPage, setPage]);

  const { isExporting, start: startExport } = useCsvExport({
    fetchRows: (signal) => fetchFilteredLedger(filters, signal),
    download: async (entries, signal) => {
      const names = await fetchLedgerCampaignNames(queryClient);
      if (signal.aborted) return;
      exportStoreLedgerToCsv(entries, names);
    },
    emptyMessage: EXPORT_EMPTY,
    errorFallback: EXPORT_ERROR_FALLBACK,
  });

  function changeCampaign(value: string) {
    setCampaign(value);
    setPage(1);
  }

  const typeCounts = query.data?.type_counts;

  return (
    <>
      <BackLink to="/dashboard" label={BACK_LABEL()} />

      <PageLayout
        className="max-w-6xl"
        title={PAGE_TITLE()}
        description={typeCounts ? pageDescription(typeCounts.all) : <Skeleton className="h-4 w-48" />}
        actions={
          <Button variant="outline" disabled={isExporting || query.isPending} onClick={startExport}>
            {isExporting ? EXPORTING_LABEL() : ACTION_EXPORT()}
          </Button>
        }
      >
        <Card>
          <CardContent className="flex flex-col gap-4">
            <DrawerTabFilters
              options={LEDGER_FILTER_TYPES.map((type) => ({
                value: type,
                label: TYPE_LABELS[type](),
                count: typeCounts?.[type],
              }))}
              value={state.filter}
              onChange={state.setFilter}
              ariaLabel={FILTER_TYPE_LABEL()}
              range={state.range}
              onRangeChange={state.setRange}
              search={state.search}
              onSearchChange={state.setSearch}
              searchPlaceholder={SEARCH_PLACEHOLDER()}
              extra={
                can(CAP.MANAGE_CAMPAIGNS) &&
                campaignOptions.length > 0 && (
                  <Select value={campaign} onValueChange={changeCampaign}>
                    <SelectTrigger className="w-auto max-w-56 shrink-0">
                      <span className="text-muted-foreground">{CAMPAIGN_FILTER_LABEL()}:</span>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={ALL_CAMPAIGNS}>{CAMPAIGN_FILTER_ALL()}</SelectItem>
                      {campaignOptions.map(({ id, label }) => (
                        <SelectItem key={id} value={String(id)}>
                          {label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )
              }
            />

            {query.isError ? (
              <ErrorState message={LOAD_ERROR()} onRetry={() => query.refetch()} />
            ) : (
              <>
                <div
                  className={cn(
                    'overflow-x-auto rounded-md border transition-opacity',
                    query.isPlaceholderData && 'opacity-60',
                  )}
                >
                  <StoreLedgerTable
                    entries={query.data?.data ?? []}
                    campaignNames={campaignNames}
                    isLoading={query.isPending}
                    isFiltered={state.isFiltered || campaign !== ALL_CAMPAIGNS}
                    skeletonRows={PAGE_SIZE / 2}
                  />
                </div>
                {totalPage > 1 && (
                  <Pagination
                    pageIndex={state.page - 1}
                    pageCount={totalPage}
                    canPreviousPage={state.page > 1}
                    canNextPage={state.page < totalPage}
                    onPreviousPage={() => setPage(Math.max(1, state.page - 1))}
                    onNextPage={() => setPage(Math.min(totalPage, state.page + 1))}
                    onPageChange={(pageIndex) => setPage(pageIndex + 1)}
                    className="ml-auto"
                  />
                )}
              </>
            )}
          </CardContent>
        </Card>
      </PageLayout>

      <Outlet />
    </>
  );
}
