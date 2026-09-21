import { useState } from 'react';
import { format } from 'date-fns';
import type { DateRange } from 'react-day-picker';

import { useCustomerActivityQuery } from '@/lib/queries/customer.queries';
import type { ActivityGroup } from '@/lib/schema/activity.schema';
import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { toActivityRow } from '../customer-drawer.activity-rows';
import { ACTIVITY_FOOTER_NOTE } from '../customer-drawer.copy';
import { ActivityLogTabFilters } from './ActivityLogTabFilters';
import { ActivityLogTabRows } from './ActivityLogTabRows';
import { DRAWER_PAGE_SIZE, DrawerTabPager } from './DrawerTabPager';

/**
 * Owns filter state (`group`, `search`, `range`, `page`) and the activity
 * query; composes filters → rows → pager — the exact same shape
 * `LedgerTab.tsx` uses.
 *
 * **Started as "Load more" (3d), now paged.** The artboard draws Load more
 * because the tab has no running-balance column forcing contiguous pages
 * the way the ledger's does (3c note 3) — that reasoning was, and still is,
 * correct. It just didn't anticipate what the `large` scenario's 1,940 rows
 * feel like to browse with only a group filter and no search: reviewing the
 * shipped tab surfaced that gap, so this switched to the ledger's full
 * filter set (group + search + date range) and its pager, at the cost of
 * diverging from 3d's literal control. Any filter change resets to page 1,
 * same as the ledger.
 */
export function ActivityLogTab({ userId, scenario }: { userId: number; scenario?: string }) {
  const [group, setGroup] = useState<ActivityGroup>('all');
  const [range, setRange] = useState<DateRange | undefined>(undefined);
  const [search, setSearch] = useState('');
  const [querySearch, setQuerySearch] = useState('');
  const [page, setPage] = useState(1);

  // See `useDebouncedSearchCommit`'s docblock: `cancelPending()` is called
  // from every OTHER filter change below so a search commit still in flight
  // can't fire after and silently overwrite a group/range change.
  const { commit: commitSearch, cancelPending: cancelPendingSearch } = useDebouncedSearchCommit(setQuerySearch);

  const query = useCustomerActivityQuery(userId, {
    page,
    perPage: DRAWER_PAGE_SIZE,
    group,
    search: querySearch,
    after: range?.from ? format(range.from, 'yyyy-MM-dd') : undefined,
    before: range?.to ? format(range.to, 'yyyy-MM-dd') : undefined,
    scenario,
  });

  const rows = query.data?.data.map(toActivityRow) ?? [];
  const totalItems = query.data?.totalItems ?? 0;

  function handleSearchChange(value: string) {
    setSearch(value);
    commitSearch(value);
    setPage(1);
  }

  function handleGroupChange(value: ActivityGroup) {
    cancelPendingSearch();
    setGroup(value);
    setPage(1);
  }

  function handleRangeChange(value: DateRange | undefined) {
    cancelPendingSearch();
    setRange(value);
    setPage(1);
  }

  return (
    <div className="flex flex-col gap-3">
      <ActivityLogTabFilters
        group={group}
        onGroupChange={handleGroupChange}
        range={range}
        onRangeChange={handleRangeChange}
        search={search}
        onSearchChange={handleSearchChange}
      />
      <div className="overflow-x-auto rounded-md border">
        <ActivityLogTabRows rows={rows} isLoading={query.isPending} />
      </div>
      <DrawerTabPager
        page={page}
        perPage={DRAWER_PAGE_SIZE}
        totalItems={totalItems}
        onPageChange={setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
      <p className="text-muted-foreground border-t pt-3 text-xs">{ACTIVITY_FOOTER_NOTE()}</p>
    </div>
  );
}
