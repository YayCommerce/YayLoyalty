import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';

import { getLookup } from '@/lib/api/lookup.api';
import type { LookupEntity } from '@/lib/schema/lookup.schema';

/** ─── Query Options ─────────────────────────────────── */

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
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useLookupSearchQuery(entity: LookupEntity, term: string) {
  return useQuery(LOOKUP_QUERIES.search(entity, term));
}

/** Labels for ids the picker already holds but has not seen in a search result. */
export function useLookupLabelsQuery(entity: LookupEntity, ids: number[]) {
  return useQuery(LOOKUP_QUERIES.labels(entity, ids));
}
