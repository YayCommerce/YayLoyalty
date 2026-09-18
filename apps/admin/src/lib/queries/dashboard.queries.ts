import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { dismissDashboardNotice, getDashboard } from '@/lib/api/dashboard.api';
import type { DashboardRange, DashboardResponse, DismissDashboardNoticeParams } from '@/lib/schema/dashboard.schema';

export const DASHBOARD_QUERIES = {
  overview: (range: DashboardRange, scenario?: string) =>
    queryOptions({
      queryKey: ['dashboard', 'overview', range, scenario ?? null],
      queryFn: () => getDashboard({ range, scenario }),
      // A range change is a new key; keep the old series on screen, dimmed.
      placeholderData: keepPreviousData,
      retry: 1,
      staleTime: 60_000,
    }),
};

export function useDashboardQuery(range: DashboardRange, scenario?: string) {
  return useQuery(DASHBOARD_QUERIES.overview(range, scenario));
}

/** Optimistic dismiss; a server refusal rolls back so the notice reappears. */
export function useDismissNoticeMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['dashboard', 'dismiss'],
    mutationFn: (params: DismissDashboardNoticeParams) => dismissDashboardNotice(params),
    onMutate: async (params) => {
      await queryClient.cancelQueries({ queryKey: ['dashboard', 'overview'] });

      const previous = queryClient.getQueriesData<DashboardResponse>({ queryKey: ['dashboard', 'overview'] });

      queryClient.setQueriesData<DashboardResponse>({ queryKey: ['dashboard', 'overview'] }, (data) =>
        data
          ? {
              ...data,
              notices: data.notices.filter((n) => !(n.key === params.key && n.fingerprint === params.fingerprint)),
            }
          : data,
      );

      return { previous };
    },
    onError: (_error, _params, context) => {
      context?.previous.forEach(([key, data]) => queryClient.setQueryData(key, data));
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

/** "Refresh now": one request with refresh=1, then the normal query refetches. */
export function useRefreshDashboard(range: DashboardRange) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['dashboard', 'refresh', range],
    mutationFn: () => getDashboard({ range, refresh: true }),
    onSuccess: (data) => {
      queryClient.setQueryData(DASHBOARD_QUERIES.overview(range).queryKey, data);
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}
