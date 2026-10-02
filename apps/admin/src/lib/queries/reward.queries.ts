import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  createReward,
  getReward,
  getRewards,
  reorderRewards,
  updateReward,
  updateRewardStatus,
} from '@/lib/api/reward.api';
import type { RewardCreateInput, RewardListParams, RewardStatus, RewardUpdateInput } from '@/lib/schema/reward.schema';

/** ─── Query Options ─────────────────────────────────── */

export const REWARD_QUERIES = {
  list: (params: RewardListParams) =>
    queryOptions({
      queryKey: ['reward', 'list', params],
      queryFn: () => getRewards(params),
    }),

  detail: (id: number) =>
    queryOptions({
      queryKey: ['reward', 'detail', id],
      queryFn: () => getReward(id),
      enabled: id > 0,
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useRewardsQuery(params: RewardListParams = {}) {
  return useQuery(REWARD_QUERIES.list(params));
}

export function useRewardQuery(id: number) {
  return useQuery(REWARD_QUERIES.detail(id));
}

/** No detail query key: nothing exists to invalidate until the row is created. */
export function useCreateRewardMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['reward', 'create'],
    mutationFn: (input: RewardCreateInput) => createReward(input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reward', 'list'] });
    },
  });
}

export function useUpdateRewardMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['reward', 'update', id],
    mutationFn: (input: Partial<RewardUpdateInput>) => updateReward(id, input),
    // The response is the stored row, so the detail is written, not refetched.
    onSuccess: (saved) => {
      queryClient.setQueryData(REWARD_QUERIES.detail(id).queryKey, saved);
      queryClient.invalidateQueries({ queryKey: ['reward', 'list'] });
    },
  });
}

/** Pause, resume and archive; see updateRewardStatus(). */
export function useUpdateRewardStatusMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['reward', 'status', id],
    mutationFn: (status: RewardStatus) => updateRewardStatus(id, status),
    // The response is the stored row, so the detail is written, not refetched.
    onSuccess: (saved) => {
      queryClient.setQueryData(REWARD_QUERIES.detail(id).queryKey, saved);
      queryClient.invalidateQueries({ queryKey: ['reward', 'list'] });
    },
  });
}

/** Saves a dragged display order; see reorderRewards(). */
export function useReorderRewardsMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['reward', 'reorder'],
    // One scope runs drags in order, so a slow earlier request can never land after a later one.
    scope: { id: 'reward-reorder' },
    mutationFn: (ids: number[]) => reorderRewards(ids),
    // onSettled: a failed save must still bring the table back to the stored order.
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['reward', 'list'] });
    },
  });
}
