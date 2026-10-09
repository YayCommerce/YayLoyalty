import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { completeSetup, dismissSetup, getSetup } from '@/lib/api/setup.api';
import { DESIGN_QUERIES } from '@/lib/queries/design.queries';
import { SETTINGS_QUERIES } from '@/lib/queries/settings.queries';
import type { Setup } from '@/lib/schema/setup.schema';

export const SETUP_QUERIES = {
  main: queryOptions({
    queryKey: ['setup', 'main'],
    queryFn: getSetup,
  }),
};

export function useSetupQuery() {
  return useQuery(SETUP_QUERIES.main);
}

/** "Turn on my program": every screen the wizard wrote into reads fresh data afterwards. */
export function useCompleteSetupMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationKey: ['setup', 'complete'],
    mutationFn: (data: Setup) => completeSetup(data),
    onSuccess: async (setup) => {
      window.yayLoyaltyMeta.loyaltyMeta.setupCompleted = true;
      queryClient.setQueryData(SETUP_QUERIES.main.queryKey, setup);
      queryClient.invalidateQueries({ queryKey: ['campaign'] });
      queryClient.invalidateQueries({ queryKey: ['reward'] });
      // Settings and design seed from window.yayLoyaltyAdmin and never go stale, so an invalidate is a
      // no-op before their tab has mounted. Fetch them now: the queryFns refresh the window copy too.
      await Promise.all([
        queryClient.fetchQuery({ ...SETTINGS_QUERIES.main, staleTime: 0 }),
        queryClient.fetchQuery({ ...DESIGN_QUERIES.main, staleTime: 0 }),
      ]);
    },
  });
}

/** "Exit setup": nothing is saved, the wizard just never opens again. */
export function useDismissSetupMutation() {
  return useMutation({
    mutationKey: ['setup', 'dismiss'],
    mutationFn: dismissSetup,
    onSuccess: () => {
      window.yayLoyaltyMeta.loyaltyMeta.setupCompleted = true;
    },
  });
}
