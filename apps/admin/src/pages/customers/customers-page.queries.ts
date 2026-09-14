import { queryOptions, useQuery } from '@tanstack/react-query';

import { getCustomersDemo, type CustomersDemoListParams } from './customers-page.api';

/**
 * TEMPORARY DEMO DATA — see `customers-page.api.ts`.
 *
 * One list query per page/filter/sort combination — the "which filter to drop"
 * suggestions ride along inside this same response once it's empty (see
 * `getCustomersDemo`'s envelope schema), so there's no second query for them.
 */
export const CUSTOMERS_DEMO_QUERIES = {
  list: (params: CustomersDemoListParams) =>
    queryOptions({
      queryKey: ['customers-demo', 'list', params],
      queryFn: () => getCustomersDemo(params),
    }),
};

export function useCustomersDemoQuery(params: CustomersDemoListParams = {}) {
  return useQuery(CUSTOMERS_DEMO_QUERIES.list(params));
}
