import { queryOptions, useQuery } from '@tanstack/react-query';

import { getCampaignTable } from './campaign-table.api';

/** Same shape as CAMPAIGN_QUERIES in @/lib/queries/campaign.queries. */
export const CAMPAIGN_TABLE_QUERIES = {
  list: queryOptions({
    queryKey: ['campaign', 'table'],
    queryFn: getCampaignTable,
  }),
};

export function useCampaignTableQuery(enabled: boolean) {
  return useQuery({ ...CAMPAIGN_TABLE_QUERIES.list, enabled });
}
