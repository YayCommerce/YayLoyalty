import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { completeSetup, dismissFirstRun, getSetup, saveSetupDraft } from '@/lib/api/setup.api';
import type { SetupDraft } from '@/lib/schema/setup.schema';

/** ─── Query Options ─────────────────────────────────── */

export const SETUP_QUERIES = {
  state: queryOptions({
    queryKey: ['setup', 'state'],
    queryFn: getSetup,
    // The wizard owns the draft once loaded; a background refetch would reset the form under the merchant.
    staleTime: Infinity,
  }),
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useSetupQuery() {
  return useQuery(SETUP_QUERIES.state);
}

export function useSaveSetupDraftMutation() {
  return useMutation({
    mutationKey: ['setup', 'draft'],
    mutationFn: ({ step, draft }: { step: number; draft: SetupDraft }) => saveSetupDraft(step, draft),
    onSuccess: (_data, { step }) => {
      window.yayLoyaltyAdmin.setup.step = step;
    },
  });
}

/** Every screen the apply touched reads stale data afterwards, so each one's prefix is invalidated. */
export function useCompleteSetupMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['setup', 'complete'],
    mutationFn: (draft: SetupDraft) => completeSetup(draft),
    onSuccess: () => {
      window.yayLoyaltyAdmin.setup.status = 'completed';
      for (const prefix of ['setup', 'settings', 'campaign', 'reward', 'design', 'dashboard']) {
        queryClient.invalidateQueries({ queryKey: [prefix] });
      }
    },
  });
}

export function useDismissFirstRunMutation() {
  return useMutation({
    mutationKey: ['setup', 'dismiss-first-run'],
    mutationFn: dismissFirstRun,
    onSuccess: () => {
      window.yayLoyaltyAdmin.setup.first_run_dismissed = true;
    },
  });
}
