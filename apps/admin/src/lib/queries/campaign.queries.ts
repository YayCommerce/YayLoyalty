import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  deleteCampaign,
  getCampaign,
  getCampaigns,
  updateCampaign,
  updateCampaignConfig,
  updateCampaignStatus,
} from '@/lib/api/campaign.api';
import type { Campaign, CampaignListParams, CampaignStatus, CampaignUpdateInput } from '@/lib/schema/campaign.schema';

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

/** Archive and pause; see updateCampaignStatus(). */
export function useUpdateCampaignStatusMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'status', id],
    mutationFn: (status: CampaignStatus) => updateCampaignStatus(id, status),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}

/** BuiltInCampaignList's "Save changes"; see updateCampaignConfig(). */
export function useUpdateCampaignConfigMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'config', id],
    mutationFn: (config: Campaign['config']) => updateCampaignConfig(id, config),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}

export function useDeleteCampaignMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'delete', id],
    mutationFn: () => deleteCampaign(id),
    onSuccess: () => {
      // Remove, not invalidate: a refetch of a deleted campaign would only 404.
      queryClient.removeQueries({ queryKey: ['campaign', 'detail', id] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}

/** What one bulk action writes: a status PUT per entry in `statuses`, a DELETE per id in `deletes`. */
export interface CampaignBulkWrites {
  statuses: { id: number; status: CampaignStatus }[];
  deletes: number[];
}

/** Concurrent requests for a bulk write; same ceiling as the customers bulk adjust. */
const BULK_WRITE_CONCURRENCY = 10;

/**
 * Archive, pause and delete for several campaigns as one mutation, through
 * the same per-id calls as the single-campaign hooks; there is no bulk
 * endpoint. A failed campaign does not abort the rest.
 */
export function useBulkCampaignWritesMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'bulk'],
    mutationFn: async ({ statuses, deletes }: CampaignBulkWrites) => {
      const writes: (() => Promise<unknown>)[] = [];
      statuses.forEach(({ id, status }) => writes.push(() => updateCampaignStatus(id, status)));
      deletes.forEach((id) => writes.push(() => deleteCampaign(id)));

      let succeeded = 0;
      let failed = 0;

      for (let i = 0; i < writes.length; i += BULK_WRITE_CONCURRENCY) {
        const results = await Promise.allSettled(writes.slice(i, i + BULK_WRITE_CONCURRENCY).map((write) => write()));

        succeeded += results.filter((result) => result.status === 'fulfilled').length;
        failed += results.filter((result) => result.status === 'rejected').length;
      }

      return { succeeded, failed };
    },
    // onSettled: a partly failed batch still changed real campaigns.
    onSettled: (_data, _error, { statuses, deletes }) => {
      statuses.forEach(({ id }) => queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] }));
      deletes.forEach((id) => queryClient.removeQueries({ queryKey: ['campaign', 'detail', id] }));
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}
