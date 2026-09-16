import { queryOptions, useQuery } from '@tanstack/react-query';

import { getBuiltInCampaigns } from './built-in-campaign-list.api';

/**
 * Same shape as CAMPAIGN_QUERIES in @/lib/queries/campaign.queries — a
 * create/update mutation here would follow useSaveSettingsMutation's pattern
 * in settings.queries.ts (mutationFn + onSuccess cache update).
 */
export const BUILT_IN_CAMPAIGN_LIST_QUERIES = {
  list: queryOptions({
    queryKey: ['campaign', 'built-in-list'],
    queryFn: getBuiltInCampaigns,
  }),
};

export function useBuiltInCampaignsQuery() {
  return useQuery(BUILT_IN_CAMPAIGN_LIST_QUERIES.list);
}
