import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { ReferralSettingsUpdate } from './referral-settings.schema';
import { getReferralSettings, postReferralSettings } from './referral.api';

export const REFERRAL_QUERIES = {
  settings: queryOptions({
    queryKey: ['referral', 'settings'],
    queryFn: getReferralSettings,
  }),
};

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
