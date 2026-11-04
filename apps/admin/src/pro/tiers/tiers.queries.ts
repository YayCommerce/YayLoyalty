import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { SETTINGS_QUERIES, useSettingsQuery } from '@/lib/queries/settings.queries';
import type { TierInput } from '@/lib/schema/tier.schema';
import { createTier, deleteTier, getTiers, getTierSettings, updateTier, updateTierSettings } from './tiers.api';

/** Every tier read sits under this prefix, so each write invalidates the lot. */
const TIER_KEY = ['tier'] as const;

export const TIER_QUERIES = {
  list: () =>
    queryOptions({
      queryKey: [...TIER_KEY, 'list'],
      queryFn: ({ signal }) => getTiers(signal),
    }),
  /** Polls while a sweep runs, so the progress banner moves and clears on its own. */
  settings: () =>
    queryOptions({
      queryKey: [...TIER_KEY, 'settings'],
      queryFn: ({ signal }) => getTierSettings(signal),
      refetchInterval: (query) => (query.state.data?.sweep ? 5000 : false),
    }),
};

export function useTierSettingsQuery() {
  return useQuery(TIER_QUERIES.settings());
}

/** Whether tiers are on, from the main settings the whole admin already holds. */
export function useTiersEnabled(): boolean {
  return useSettingsQuery().data?.tiers_enabled === true;
}

export function useUpdateTierSettingsMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: { tiers_enabled: boolean }) => updateTierSettings(input),
    onSuccess: (settings) => {
      queryClient.setQueryData(TIER_QUERIES.settings().queryKey, settings);
      void queryClient.invalidateQueries({ queryKey: TIER_KEY });
      // Every tier surface reads tiers_enabled from the main settings.
      void queryClient.invalidateQueries({ queryKey: SETTINGS_QUERIES.main.queryKey });
    },
  });
}

export function useTiersQuery() {
  return useQuery(TIER_QUERIES.list());
}

export function useCreateTierMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: TierInput) => createTier(input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TIER_KEY }),
  });
}

export function useUpdateTierMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: Partial<TierInput> }) => updateTier(id, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TIER_KEY }),
  });
}

export function useDeleteTierMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => deleteTier(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: TIER_KEY }),
  });
}
