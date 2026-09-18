import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getCampaign, getCampaigns, updateCampaign } from '@/lib/api/campaign.api';
import type { CampaignListParams, CampaignUpdateInput } from '@/lib/schema/campaign.schema';

/** ─── Query Options ─────────────────────────────────── */

export const CAMPAIGN_QUERIES = {
  list: (params: CampaignListParams) =>
    queryOptions({
      queryKey: ['campaign', 'list', params],
      queryFn: () => getCampaigns(params),
    }),

  detail: (id: number) =>
    queryOptions({
      queryKey: ['campaign', 'detail', id],
      queryFn: () => getCampaign(id),
      enabled: id > 0,
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useCampaignsQuery(params: CampaignListParams = {}) {
  return useQuery(CAMPAIGN_QUERIES.list(params));
}

export function useCampaignQuery(id: number) {
  return useQuery(CAMPAIGN_QUERIES.detail(id));
}

export function useUpdateCampaignMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'update', id],
    mutationFn: (input: CampaignUpdateInput) => updateCampaign(id, input),
    onSuccess: () => {
      // The list shows the same fields; invalidating only detail leaves its row stale.
      queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}
