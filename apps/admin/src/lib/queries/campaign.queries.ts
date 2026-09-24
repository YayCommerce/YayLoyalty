import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  createCampaign,
  deleteCampaign,
  getCampaign,
  getCampaigns,
  updateCampaign,
  updateCampaignConfig,
  updateCampaignStatus,
} from '@/lib/api/campaign.api';
import { settleInChunks } from '@/lib/helpers/settle-in-chunks.helper';
import type {
  Campaign,
  CampaignCreateInput,
  CampaignListParams,
  CampaignStatus,
  CampaignUpdateInput,
} from '@/lib/schema/campaign.schema';

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
      statuses.forEach(({ id }) => queryClient.invalidateQueries({ queryKey: ['campaign', 'detail', id] }));
      deletes.forEach((id) => queryClient.removeQueries({ queryKey: ['campaign', 'detail', id] }));
      queryClient.invalidateQueries({ queryKey: ['campaign', 'list'] });
    },
  });
}
