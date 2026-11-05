import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { customerKeyPrefix } from '@/lib/queries/customer.queries';
import { SETTINGS_QUERIES, useSettingsQuery } from '@/lib/queries/settings.queries';
import type { AssignCustomerTierInput, TierInput, TierSettingsInput } from '@/lib/schema/tier.schema';
import {
  assignCustomerTier,
  createTier,
  deleteTier,
  getCustomerTier,
  getTiers,
  getTierSettings,
  removeCustomerTier,
  updateTier,
  updateTierSettings,
} from './tiers.api';

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
  customer: (userId: number) =>
    queryOptions({
      queryKey: [...TIER_KEY, 'customer', userId],
      queryFn: ({ signal }) => getCustomerTier(userId, signal),
      enabled: userId > 0,
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
    mutationFn: (input: TierSettingsInput) => updateTierSettings(input),
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

export function useCustomerTierQuery(userId: number, enabled = true) {
  return useQuery({ ...TIER_QUERIES.customer(userId), enabled: enabled && userId > 0 });
}

/** A manual tier can move the customer, write activity and pay a bonus, so every view of them refreshes. */
function useInvalidateCustomerTier(userId: number) {
  const queryClient = useQueryClient();

  return () => {
    void queryClient.invalidateQueries({ queryKey: TIER_KEY });
    void queryClient.invalidateQueries({ queryKey: customerKeyPrefix('detail', userId) });
    void queryClient.invalidateQueries({ queryKey: customerKeyPrefix('ledger', userId) });
    void queryClient.invalidateQueries({ queryKey: customerKeyPrefix('activity', userId) });
    void queryClient.invalidateQueries({ queryKey: ['customer', 'list'] });
    void queryClient.invalidateQueries({ queryKey: ['customer', 'store-ledger'] });
  };
}

export function useAssignCustomerTierMutation(userId: number) {
  const invalidate = useInvalidateCustomerTier(userId);

  return useMutation({
    mutationFn: (input: AssignCustomerTierInput) => assignCustomerTier(userId, input),
    onSuccess: invalidate,
  });
}

export function useRemoveCustomerTierMutation(userId: number) {
  const invalidate = useInvalidateCustomerTier(userId);

  return useMutation({
    mutationFn: () => removeCustomerTier(userId),
    onSuccess: invalidate,
  });
}
