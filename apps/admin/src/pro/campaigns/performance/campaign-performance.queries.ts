import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';

import type { CampaignPerformanceEntriesParams } from '@/lib/schema/campaign-performance.schema';
import { getCampaignPerformance, getCampaignPerformanceEntries } from './campaign-performance.api';

/** ─── Query Options ─────────────────────────────────── */

/**
 * Keyed under `['campaign', 'performance', id, ...]`, same prefix as `CAMPAIGN_QUERIES`, so a
 * broad `['campaign']` invalidation covers this too. Neither query is invalidated on campaign
 * edit — performance figures only change when the ledger gains rows, never when a campaign's
 * own fields change, true before and after the real aggregate replaces the fixture.
 */
export const CAMPAIGN_PERFORMANCE_QUERIES = {
  summary: (id: number) =>
    queryOptions({
      queryKey: ['campaign', 'performance', id],
      queryFn: () => getCampaignPerformance(id),
      enabled: id > 0,
      retry: 1,
      staleTime: 60_000,
    }),

  entries: (id: number, params: CampaignPerformanceEntriesParams) =>
    queryOptions({
      queryKey: ['campaign', 'performance', id, 'entries', params],
      queryFn: () => getCampaignPerformanceEntries(id, params),
      enabled: id > 0,
      retry: 1,
      staleTime: 60_000,
      // Same precedent as CUSTOMER_QUERIES.ledger — keeps prior rows visible during a page/search refetch.
      placeholderData: keepPreviousData,
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useCampaignPerformanceQuery(id: number) {
  return useQuery(CAMPAIGN_PERFORMANCE_QUERIES.summary(id));
}

export function useCampaignPerformanceEntriesQuery(id: number, params: CampaignPerformanceEntriesParams) {
  return useQuery(CAMPAIGN_PERFORMANCE_QUERIES.entries(id, params));
}
