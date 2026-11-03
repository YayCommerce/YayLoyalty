import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { TierInput } from '@/lib/schema/tier.schema';
import { createTier, deleteTier, getTiers, updateTier } from './tiers.api';

/** Every tier read sits under `['tier']`, so each write invalidates the lot. */
export const TIER_QUERIES = {
  list: () =>
    queryOptions({
      queryKey: ['tier', 'list'],
      queryFn: ({ signal }) => getTiers(signal),
    }),
};

export function useTiersQuery() {
  return useQuery(TIER_QUERIES.list());
}

export function useCreateTierMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: TierInput) => createTier(input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tier'] }),
  });
}

export function useUpdateTierMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: Partial<TierInput> }) => updateTier(id, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tier'] }),
  });
}

export function useDeleteTierMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => deleteTier(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tier'] }),
  });
}
