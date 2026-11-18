import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { ReferralSettingsUpdate } from './referral-settings.schema';
import { getReferralSettings, getReferrals, postReferralSettings, type ReferralListParams } from './referral.api';

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
