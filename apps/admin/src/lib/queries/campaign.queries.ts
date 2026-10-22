import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  createCampaign,
  deleteCampaign,
  getCampaign,
  getCampaigns,
  reorderCampaigns,
  updateCampaign,
  updateCampaignConfig,
  updateCampaignStatus,
} from '@/lib/api/campaign.api';
import { setPaginatedRow } from '@/lib/helpers/paginated-row.helper';
import { settleInChunks } from '@/lib/helpers/settle-in-chunks.helper';
import type {
  Campaign,
  CampaignCreateInput,
  CampaignListParams,
  CampaignStatus,
  CampaignUpdateInput,
} from '@/lib/schema/campaign.schema';

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

export function useCampaignsQuery(params: CampaignListParams = {}) {
  return useQuery(CAMPAIGN_QUERIES.list(params));
}

export function useCampaignQuery(id: number) {
  return useQuery(CAMPAIGN_QUERIES.detail(id));
}

/** No detail query key: nothing exists to invalidate until the row is created. */
export function useCreateCampaignMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'create'],
    mutationFn: (input: CampaignCreateInput) => createCampaign(input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}

export function useUpdateCampaignMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'update', id],
    mutationFn: (input: Partial<CampaignUpdateInput>) => updateCampaign(id, input),
    onSuccess: () => {
      // The list and the performance header show the same fields; invalidating only detail leaves them stale.
      queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'performance', id] });
    },
  });
}

/** Archive, pause and enable; see updateCampaignStatus(). */
export function useUpdateCampaignStatusMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'status', id],
    mutationFn: (status: CampaignStatus) => updateCampaignStatus(id, status),
    // The response is the decorated row, the list's own shape, and a restore's new top priority moves no other row.
    onSuccess: (saved) => {
      queryClient.setQueryData(CAMPAIGN_QUERIES.detail(id).queryKey, saved);
      setPaginatedRow(queryClient, ['campaign', 'list'], saved);
      queryClient.invalidateQueries({ queryKey: ['campaign', 'performance', id] });
    },
  });
}

/** Writes only `config`; see updateCampaignConfig(). */
export function useUpdateCampaignConfigMutation(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'config', id],
    mutationFn: (config: Campaign['config']) => updateCampaignConfig(id, config),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'performance', id] });
    },
  });
}

/** Saves a dragged order as priority; see reorderCampaigns(). */
export function useReorderCampaignsMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['campaign', 'reorder'],
    // One scope runs drags in order, so a slow earlier request can never land after a later one.
    scope: { id: 'campaign-reorder' },
    mutationFn: (ids: number[]) => reorderCampaigns(ids),
    // onSettled: a failed save must still bring the table back to the stored order.
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['campaign'] });
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
      queryClient.removeQueries({ queryKey: ['campaign', 'performance', id] });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}

/** What one bulk action writes: a status PUT per entry in `statuses`, a DELETE per id in `deletes`. */
export interface CampaignBulkWrites {
  statuses: { id: number; status: CampaignStatus }[];
  deletes: number[];
}

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
      const writes: ({ id: number; status: CampaignStatus } | { id: number; status?: never })[] = [
        ...statuses,
        ...deletes.map((id) => ({ id })),
      ];
      const { succeeded, failedItems } = await settleInChunks(writes, ({ id, status }) =>
        status ? updateCampaignStatus(id, status) : deleteCampaign(id),
      );

      return { succeeded, failed: failedItems.length };
    },
    // onSettled: a partly failed batch still changed real campaigns.
    onSettled: (_data, _error, { statuses, deletes }) => {
      statuses.forEach(({ id }) => {
        queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] });
        queryClient.invalidateQueries({ queryKey: ['campaign', 'performance', id] });
      });
      deletes.forEach((id) => {
        queryClient.removeQueries({ queryKey: ['campaign', 'detail', id] });
        queryClient.removeQueries({ queryKey: ['campaign', 'performance', id] });
      });
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}
