import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { ReferralSettingsUpdate } from './referral-settings.schema';
import type { AdminRejectReason } from './approvals/approvals.copy';
import {
  approveReferral,
  getReferralSettings,
  getReferrals,
  postReferralSettings,
  rejectReferral,
  type ReferralListParams,
} from './referral.api';

export const REFERRAL_QUERIES = {
  settings: queryOptions({
    queryKey: ['referral', 'settings'],
    queryFn: getReferralSettings,
  }),
  list: (params: ReferralListParams) =>
    queryOptions({
      queryKey: ['referral', 'list', params],
      queryFn: ({ signal }) => getReferrals(params, signal),
      // Paging and filtering must not flash a skeleton over rows already on screen.
      placeholderData: keepPreviousData,
      retry: 1,
    }),
};

export function useReferralListQuery(params: ReferralListParams) {
  return useQuery(REFERRAL_QUERIES.list(params));
}

/** The held count for the Approvals badges: one row is enough, the store-wide `counts` come with it. */
export function useHeldReferralCount(): number {
  const query = useReferralListQuery({ perPage: 1, status: 'on_hold' });

  return query.data?.counts.on_hold ?? 0;
}

/** Approve or reject one held referral; every referral list refetches once it settles. */
export function useReferralDecisionMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['referral', 'decide'],
    mutationFn: ({ id, reason }: { id: number; reason?: AdminRejectReason }) =>
      reason ? rejectReferral(id, reason) : approveReferral(id),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['referral', 'list'] }),
  });
}

export function useReferralSettingsQuery() {
  return useQuery(REFERRAL_QUERIES.settings);
}

export function useSaveReferralSettingsMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['referral', 'settings', 'save'],
    mutationFn: (data: ReferralSettingsUpdate) => postReferralSettings(data),
    onSuccess: (settings) => {
      queryClient.setQueryData(REFERRAL_QUERIES.settings.queryKey, settings);
    },
  });
}
