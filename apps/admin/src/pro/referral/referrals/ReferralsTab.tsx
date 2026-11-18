import { useEffect } from 'react';
import { Outlet } from 'react-router';

import { cn } from '@/lib/utils';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Pagination } from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';
import { useDrawerTabQueryState } from '@/pages/customers/drawer/customer-drawer.tab-query-state';
import { DrawerTabFilters } from '@/pages/customers/drawer/tabs/DrawerTabFilters';
import { useReferralListQuery } from '../referral.queries';
import { REFERRAL_STATUS_LABEL, REFERRALS_COPY } from '../referrals.copy';
import { REFERRAL_STATUSES, type ReferralStatus } from '../referrals.schema';
import { ReferralsTable } from './ReferralsTable';

const PAGE_SIZE = 20;

type StatusFilter = 'all' | ReferralStatus;

/**
 * Referral → Referrals: every referral with search and status pills. The customer drawer opens over it
 * through the nested `referral/referrals/:userId` route, so the filters survive opening and closing it.
 */
export function ReferralsTab() {
  const state = useDrawerTabQueryState<StatusFilter>('all');
  const query = useReferralListQuery({
    page: state.page,
    perPage: PAGE_SIZE,
    search: state.pageParams.search,
    status: state.filter === 'all' ? undefined : state.filter,
  });
  const counts = query.data?.counts;
  const total = counts ? Object.values(counts).reduce((sum, count) => sum + count, 0) : undefined;
  const totalPages = Math.max(1, query.data?.totalPages ?? 1);
  const { setPage } = state;

  // Rows can vanish between requests; a page past the end would show an empty table with no way back.
  useEffect(() => {
    if (state.page > totalPages) setPage(totalPages);
  }, [state.page, totalPages, setPage]);

  return (
    <>
      <Card>
        <CardContent className="flex flex-col gap-4">
          <div className="text-muted-foreground text-sm">
            {counts && total !== undefined ? (
              REFERRALS_COPY.description(total, counts.rewarded ?? 0)
            ) : (
              <Skeleton className="h-4 w-48" />
            )}
          </div>
          <DrawerTabFilters<StatusFilter>
            options={[
              { value: 'all', label: REFERRALS_COPY.all(), count: total },
              ...REFERRAL_STATUSES.map((status) => ({
                value: status,
                label: REFERRAL_STATUS_LABEL[status](),
                count: counts ? (counts[status] ?? 0) : undefined,
              })),
            ]}
            value={state.filter}
            onChange={state.setFilter}
            ariaLabel={REFERRALS_COPY.filterLabel()}
            search={state.search}
            onSearchChange={state.setSearch}
            searchPlaceholder={REFERRALS_COPY.search()}
          />

          {query.isError ? (
            <ErrorState message={REFERRALS_COPY.loadError()} onRetry={() => query.refetch()} />
          ) : (
            <>
              <div
                className={cn('overflow-x-auto rounded-md border transition-opacity', query.isPlaceholderData && 'opacity-60')}
              >
                <ReferralsTable
                  items={query.data?.data ?? []}
                  isLoading={query.isPending}
                  isFiltered={state.isFiltered}
                  skeletonRows={PAGE_SIZE / 2}
                />
              </div>
              {totalPages > 1 && (
                <Pagination
                  pageIndex={state.page - 1}
                  pageCount={totalPages}
                  canPreviousPage={state.page > 1}
                  canNextPage={state.page < totalPages}
                  onPreviousPage={() => setPage(Math.max(1, state.page - 1))}
                  onNextPage={() => setPage(Math.min(totalPages, state.page + 1))}
                  onPageChange={(pageIndex) => setPage(pageIndex + 1)}
                  className="ml-auto"
                />
              )}
            </>
          )}
        </CardContent>
      </Card>

      <Outlet />
    </>
  );
}
