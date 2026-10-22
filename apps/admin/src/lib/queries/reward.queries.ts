import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  createReward,
  deleteReward,
  getReward,
  getRewards,
  reorderRewards,
  updateReward,
  updateRewardStatus,
} from '@/lib/api/reward.api';
import { setPaginatedRow } from '@/lib/helpers/paginated-row.helper';
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

/** Pause, resume, archive and restore; see updateRewardStatus(). */
export function useUpdateRewardStatusMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['reward', 'status', id],
    mutationFn: (status: RewardStatus) => updateRewardStatus(id, status),
    // The response is the decorated row, the list's own shape, so both are written, not refetched.
    onSuccess: (saved) => {
      queryClient.setQueryData(REWARD_QUERIES.detail(id).queryKey, saved);
      setPaginatedRow(queryClient, ['reward', 'list'], saved);
    },
  });
}

export function useDeleteRewardMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['reward', 'delete', id],
    mutationFn: () => deleteReward(id),
    onSuccess: () => {
      // Remove, not invalidate: a refetch of a deleted reward would only 404.
      queryClient.removeQueries({ queryKey: REWARD_QUERIES.detail(id).queryKey });
    },
    // A 409 means a claim landed since is_deletable was read; refetch so the next dialog opens as archive.
    onError: () => {
      queryClient.invalidateQueries({ queryKey: REWARD_QUERIES.detail(id).queryKey });
    },
    onSettled: () => {
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
