import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { can, CAP } from '@/lib/access';
import type { DesignPreviewViewer } from '@/lib/api/design.api';
import { completeSetup, dismissFirstRun, getSetup, previewSetup, saveSetupDraft } from '@/lib/api/setup.api';
import { CAMPAIGN_QUERIES } from '@/lib/queries/campaign.queries';
import { REWARD_QUERIES } from '@/lib/queries/reward.queries';
import { setupDraftSchema, type SetupDraft } from '@/lib/schema/setup.schema';

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

/**
 * Step 4's launcher, drawn by the server from the draft. Keyed by the draft, like the Design tab's preview.
 * A draft that does not parse yet (a half-typed colour) sends nothing, and the last pages stay up.
 */
export function useSetupPreviewQuery(draft: SetupDraft, viewer: DesignPreviewViewer) {
  return useQuery({
    queryKey: ['setup', 'preview', viewer, draft],
    queryFn: () => previewSetup(draft, viewer),
    enabled: setupDraftSchema.safeParse(draft).success,
    staleTime: Infinity,
    placeholderData: keepPreviousData,
  });
}

/** Enough rows for every built-in campaign, whatever a Pro store has added around them. */
const BUILTIN_LIST_PARAMS = { perPage: 100 };

/**
 * The built-in campaigns' and reward's current names. Only users who may open each list ask for it,
 * so each is undefined until loaded or for anyone without the cap; callers fall back to the seeded names.
 */
export function useSetupBuiltinNames(): { campaigns: string[] | undefined; reward: string | undefined } {
  const campaigns = useQuery({ ...CAMPAIGN_QUERIES.list(BUILTIN_LIST_PARAMS), enabled: can(CAP.MANAGE_CAMPAIGNS) });
  const rewards = useQuery({ ...REWARD_QUERIES.list(BUILTIN_LIST_PARAMS), enabled: can(CAP.MANAGE_REWARDS) });

  return {
    campaigns: campaigns.data?.data.filter((campaign) => campaign.is_builtin).map((campaign) => campaign.name),
    reward: rewards.data?.data.find((reward) => reward.is_builtin)?.name,
  };
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
