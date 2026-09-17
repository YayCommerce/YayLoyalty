import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';

import type { DashboardRange } from '@/lib/schema/dashboard.schema';
import { getDashboardPro } from './dashboard-pro.api';

/** Keyed under `['dashboard']` so the Free page's refresh/dismiss invalidation covers this request too. */
export const DASHBOARD_PRO_QUERIES = {
  overview: (range: DashboardRange, scenario?: string) =>
    queryOptions({
      queryKey: ['dashboard', 'pro', range, scenario ?? null],
      queryFn: () => getDashboardPro({ range, scenario }),
      placeholderData: keepPreviousData,
      retry: 1,
      staleTime: 60_000,
    }),
};

export function useDashboardProQuery(range: DashboardRange, scenario?: string) {
  return useQuery(DASHBOARD_PRO_QUERIES.overview(range, scenario));
}
