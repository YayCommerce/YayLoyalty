import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';

import { getStoreLedger, type StoreLedgerParams } from './store-ledger.api';

/** Under `['customer', 'store-ledger']`, so the bulk adjust's `['customer']` invalidation covers it too. */
export const STORE_LEDGER_QUERIES = {
  list: (params: StoreLedgerParams) =>
    queryOptions({
      queryKey: ['customer', 'store-ledger', params],
      queryFn: () => getStoreLedger(params),
      // Paging/filtering must not flash a skeleton over rows already on screen.
      placeholderData: keepPreviousData,
      retry: 1,
    }),
};

export function useStoreLedgerQuery(params: StoreLedgerParams) {
  return useQuery(STORE_LEDGER_QUERIES.list(params));
}
