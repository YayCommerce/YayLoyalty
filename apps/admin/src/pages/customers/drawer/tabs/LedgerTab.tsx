import { useState } from 'react';
import { format } from 'date-fns';
import type { DateRange } from 'react-day-picker';

import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { useCustomerLedgerQuery } from '@/lib/queries/customer.queries';
import type { LedgerFilterType } from '@/lib/schema/ledger.schema';
import { useLedgerCampaignNames } from '../customer-drawer.campaign-names';
import { toLedgerRow } from '../customer-drawer.ledger-rows';
import { DrawerTabPager } from './DrawerTabPager';
import { LedgerTabFilters } from './LedgerTabFilters';
import { LedgerTabRows } from './LedgerTabRows';

/** Matches `CustomersPage.tsx`'s own `PER_PAGE` — the two screens page alike. */
const PER_PAGE = 10;

/**
 * Owns filter state (`type`, `search`, `range`, `page`) and the ledger
 * query; composes filters → rows → pager. Any filter change resets to page
 * 1 — the matching set changed, so a stale page number could fetch past the
 * new last page.
 */
export function LedgerTab({
  userId,
  customerFirstName,
  scenario,
}: {
  userId: number;
  customerFirstName: string;
  scenario?: string;
}) {
  const [type, setType] = useState<LedgerFilterType>('all');
  const [range, setRange] = useState<DateRange | undefined>(undefined);
  const [search, setSearch] = useState('');
  const [querySearch, setQuerySearch] = useState('');
  const [page, setPage] = useState(1);

  // See `useDebouncedSearchCommit`'s docblock: `cancelPending()` is called
  // from every OTHER filter change below so a search commit still in flight
  // can't fire after and silently overwrite a type/range change.
  const { commit: commitSearch, cancelPending: cancelPendingSearch } = useDebouncedSearchCommit(setQuerySearch);

  const query = useCustomerLedgerQuery(userId, {
    page,
    perPage: PER_PAGE,
    type,
    search: querySearch,
    after: range?.from ? format(range.from, 'yyyy-MM-dd') : undefined,
    before: range?.to ? format(range.to, 'yyyy-MM-dd') : undefined,
    scenario,
  });

  const campaignNames = useLedgerCampaignNames();
  const rows = query.data?.data.map((entry) => toLedgerRow(entry, campaignNames)) ?? [];
  const totalItems = query.data?.totalItems ?? 0;
  // Per-type counts describe the UNFILTERED set (see the schema's own
  // docblock) — `null` on the real path means the server can't filter yet.
  const typeCounts = query.data?.type_counts ?? null;
  const canFilter = typeCounts != null;

  function handleSearchChange(value: string) {
    setSearch(value);
    commitSearch(value);
    setPage(1);
  }

  function handleTypeChange(value: LedgerFilterType) {
    cancelPendingSearch();
    setType(value);
    setPage(1);
  }

  function handleRangeChange(value: DateRange | undefined) {
    cancelPendingSearch();
    setRange(value);
    setPage(1);
  }

  return (
    <div className="flex flex-col gap-3">
      <LedgerTabFilters
        type={type}
        onTypeChange={handleTypeChange}
        typeCounts={typeCounts}
        range={range}
        onRangeChange={handleRangeChange}
        search={search}
        onSearchChange={handleSearchChange}
        disabled={!canFilter}
      />
      <div className="overflow-x-auto rounded-md border">
        <LedgerTabRows rows={rows} isLoading={query.isPending} customerFirstName={customerFirstName} />
      </div>
      <DrawerTabPager
        page={page}
        perPage={PER_PAGE}
        totalItems={totalItems}
        onPageChange={setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
    </div>
  );
}
