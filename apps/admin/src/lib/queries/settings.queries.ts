import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getSettings, postSettings } from '@/lib/api/settings.api';
import type { SettingsUpdate } from '@/lib/schema/settings.schema';

export const SETTINGS_QUERIES = {
  main: queryOptions({
    queryKey: ['settings', 'main'],
    queryFn: async () => {
      const settings = await getSettings();
      window.yayLoyaltyAdmin.settings = settings;
      return settings;
    },
    initialData: () => window.yayLoyaltyAdmin.settings,
    staleTime: Infinity,
  }),
};

export function useSettingsQuery() {
  return useQuery(SETTINGS_QUERIES.main);
}

export function useSaveSettingsMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationKey: ['settings', 'save'],
    mutationFn: (data: SettingsUpdate) => postSettings(data),
    onSuccess: (settings) => {
      queryClient.setQueryData(SETTINGS_QUERIES.main.queryKey, settings);
    },
  });
}
