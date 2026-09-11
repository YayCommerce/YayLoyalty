import { queryOptions, useQuery } from '@tanstack/react-query';

import { getCustomersDemo } from './customers-page.api';

/** TEMPORARY DEMO DATA — see `customers-page.api.ts`. */
export const CUSTOMERS_DEMO_QUERY = queryOptions({
  queryKey: ['customers-demo', 'list'],
  queryFn: getCustomersDemo,
});

export function useCustomersDemoQuery() {
  return useQuery(CUSTOMERS_DEMO_QUERY);
}
