import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';

import { getLookup, getProductSummary } from '@/lib/api/lookup.api';
import type { LookupEntity } from '@/lib/schema/lookup.schema';

export const LOOKUP_QUERIES = {
  search: (entity: LookupEntity, term: string) =>
    queryOptions({
      queryKey: ['lookup', entity, 'search', term],
      queryFn: ({ signal }) => getLookup(entity, { search: term }, signal),
      staleTime: 60_000,
      // Keeps the last list on screen while the next term loads, so the popover never blanks mid-typing.
      placeholderData: keepPreviousData,
    }),

  labels: (entity: LookupEntity, ids: number[]) =>
    queryOptions({
      queryKey: ['lookup', entity, 'labels', ids],
      queryFn: () => getLookup(entity, { ids }),
      enabled: ids.length > 0,
      staleTime: 5 * 60_000,
    }),

  productSummary: (id: number) =>
    queryOptions({
      queryKey: ['lookup', 'products', 'summary', id],
      queryFn: () => getProductSummary(id),
      enabled: id > 0,
      // Stock is live; a short stale time keeps the card honest without refetching on every render.
      staleTime: 30_000,
      // A deleted product answers 404 and stays deleted; retrying only delays the message.
      retry: false,
    }),
};

export function useLookupSearchQuery(entity: LookupEntity, term: string) {
  return useQuery(LOOKUP_QUERIES.search(entity, term));
}

/** Labels for ids the picker already holds but has not seen in a search result. */
export function useLookupLabelsQuery(entity: LookupEntity, ids: number[]) {
  return useQuery(LOOKUP_QUERIES.labels(entity, ids));
}

/** One product's card data; off while no product is chosen. */
export function useProductSummaryQuery(id: number | null) {
  return useQuery(LOOKUP_QUERIES.productSummary(id ?? 0));
}
