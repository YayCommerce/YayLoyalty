import { useEffect } from 'react';
import { Search } from 'lucide-react';
import { Outlet } from 'react-router';

import { formatCount } from '@/lib/helpers/points.helper';
import { cn } from '@/lib/utils';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Pagination } from '@/components/ui/pagination';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { useDrawerTabQueryState } from '@/pages/customers/drawer/customer-drawer.tab-query-state';
import { useReferralListQuery } from '../referral.queries';
import { REFERRAL_STATUS_LABEL, REFERRALS_COPY } from '../referrals.copy';
import { REFERRAL_STATUSES, type ReferralStatus } from '../referrals.schema';
import { ReferralsTable } from './ReferralsTable';

const PAGE_SIZE = 20;

type StatusFilter = 'all' | ReferralStatus;

/** Referral → Referrals: every referral with search and status pills. */
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
  const statusOptions: { value: StatusFilter; label: string; count?: number }[] = [
    { value: 'all', label: REFERRALS_COPY.all(), count: total },
    ...REFERRAL_STATUSES.map((status) => ({
      value: status,
      label: REFERRAL_STATUS_LABEL[status](),
      count: counts ? (counts[status] ?? 0) : undefined,
    })),
  ];

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
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="max-w-full overflow-x-auto">
              <Segmented
                size="sm"
                aria-label={REFERRALS_COPY.filterLabel()}
                value={state.filter}
                // Radix reports '' when the active segment is clicked again; there is no "no filter" state.
                onValueChange={(next) => next && state.setFilter(next as StatusFilter)}
                className="w-max"
              >
                {statusOptions.map((option) => (
                  <SegmentedItem key={option.value} value={option.value} className="px-2.5">
                    {option.label}
                    {option.count !== undefined && (
                      <span className="text-muted-foreground text-xs tabular-nums">{formatCount(option.count)}</span>
                    )}
                  </SegmentedItem>
                ))}
              </Segmented>
            </div>
            <InputGroup className="w-full sm:w-72">
              <InputGroupInput
                placeholder={REFERRALS_COPY.search()}
                aria-label={REFERRALS_COPY.search()}
                value={state.search}
                onChange={(event) => state.setSearch(event.target.value)}
              />
              <InputGroupAddon align="inline-end">
                <Search className="size-4" />
              </InputGroupAddon>
            </InputGroup>
          </div>

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
