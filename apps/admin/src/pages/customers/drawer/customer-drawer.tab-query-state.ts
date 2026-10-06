import { useState } from 'react';
import { format } from 'date-fns';
import type { DateRange } from 'react-day-picker';

import { useDebouncedSearchCommit } from '@/hooks/useDebouncedSearchCommit';
import { DRAWER_PAGE_SIZE } from './tabs/DrawerTabPager';

/**
 * Filter, search, date range and page state for a paged drawer tab.
 * Any filter change resets to page 1 and cancels a pending search commit, which would otherwise overwrite it.
 * `pageParams` is the request-ready slice; the caller adds its own filter key.
 */
export function useDrawerTabQueryState<F extends string>(initialFilter: F) {
  const [filter, setFilterState] = useState<F>(initialFilter);
  const [range, setRangeState] = useState<DateRange | undefined>(undefined);
  const [search, setSearchState] = useState('');
  const [querySearch, setQuerySearch] = useState('');
  const [page, setPage] = useState(1);

  const { commit: commitSearch, cancelPending: cancelPendingSearch } = useDebouncedSearchCommit(setQuerySearch);

  function setSearch(value: string) {
    setSearchState(value);
    commitSearch(value);
    setPage(1);
  }

  function setFilter(value: F) {
    cancelPendingSearch();
    setFilterState(value);
    setPage(1);
  }

  function setRange(value: DateRange | undefined) {
    cancelPendingSearch();
    setRangeState(value);
    setPage(1);
  }

  return {
    filter,
    setFilter,
    range,
    setRange,
    search,
    setSearch,
    page,
    setPage,
    /** Reads the committed search, not the box: the rows only change once a search commits. */
    isFiltered: filter !== initialFilter || querySearch !== '' || range?.from !== undefined,
    pageParams: {
      page,
      perPage: DRAWER_PAGE_SIZE,
      search: querySearch,
      after: range?.from ? format(range.from, 'yyyy-MM-dd') : undefined,
      before: range?.to ? format(range.to, 'yyyy-MM-dd') : undefined,
    },
  };
}
