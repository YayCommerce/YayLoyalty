import { queryOptions, useQuery } from '@tanstack/react-query';

import { getCustomersDemo, type CustomersDemoListParams } from './customers-page.api';
import { getFilterDropSuggestions } from './customers-page.filter-suggestions';
import type { RowFilters } from './customers-page.filter-rows';

/** TEMPORARY DEMO DATA — see `customers-page.api.ts`. */
export const CUSTOMERS_DEMO_QUERIES = {
  list: (params: CustomersDemoListParams) =>
    queryOptions({
      queryKey: ['customers-demo', 'list', params],
      queryFn: () => getCustomersDemo(params),
    }),

  /** Only meaningful once the active filters match zero rows — gate with `enabled`. */
  suggestions: (filters: RowFilters, includeTier: boolean) =>
    queryOptions({
      queryKey: ['customers-demo', 'suggestions', filters, includeTier],
      queryFn: () => getFilterDropSuggestions(filters, includeTier),
    }),
};

export function useCustomersDemoQuery(params: CustomersDemoListParams = {}) {
  return useQuery(CUSTOMERS_DEMO_QUERIES.list(params));
}

export function useCustomersDemoSuggestionsQuery(filters: RowFilters, includeTier: boolean, enabled: boolean) {
  return useQuery({ ...CUSTOMERS_DEMO_QUERIES.suggestions(filters, includeTier), enabled });
}
