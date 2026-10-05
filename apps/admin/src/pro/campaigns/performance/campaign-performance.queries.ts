import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';

import type { CampaignPerformanceEntriesParams } from '@/lib/schema/campaign-performance.schema';
import { getCampaignPerformance, getCampaignPerformanceEntries } from './campaign-performance.api';

/**
 * Keyed under `['campaign', 'performance', id, ...]`, same prefix as `CAMPAIGN_QUERIES`, so a
 * broad `['campaign']` invalidation covers this too. The summary carries the campaign itself
 * (the page's header), so the campaign write mutations invalidate it along with detail.
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
      // Keeps prior rows visible during a page or search refetch.
      placeholderData: keepPreviousData,
    }),
};

export function useCampaignPerformanceQuery(id: number) {
  return useQuery(CAMPAIGN_PERFORMANCE_QUERIES.summary(id));
}

export function useCampaignPerformanceEntriesQuery(id: number, params: CampaignPerformanceEntriesParams) {
  return useQuery(CAMPAIGN_PERFORMANCE_QUERIES.entries(id, params));
}
