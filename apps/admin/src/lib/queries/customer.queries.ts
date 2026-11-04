import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  getCustomerActivity,
  getCustomerRedemptions,
  type CustomerActivityParams,
  type DrawerPageOnlyParams,
} from '@/lib/api/customer-drawer.api';
import {
  adjustCustomerPoints,
  getCustomer,
  getCustomerLedger,
  getCustomers,
  previewAdjustPoints,
  type CustomerLedgerParams,
  type CustomerListParams,
} from '@/lib/api/customer.api';
import { settleInChunks } from '@/lib/helpers/settle-in-chunks.helper';
import type { AdjustPointsInput, AdjustPointsPreviewInput } from '@/lib/schema/customer.schema';

/** `['customer', kind, userId]`, built in one place so the query keys and the adjust invalidations cannot drift apart. */
export function customerKeyPrefix(kind: 'detail' | 'ledger' | 'activity' | 'redemptions', userId: number) {
  return ['customer', kind, userId] as const;
}

export const CUSTOMER_QUERIES = {
  detail: (userId: number) =>
    queryOptions({
      queryKey: customerKeyPrefix('detail', userId),
      queryFn: () => getCustomer(userId),
      enabled: userId > 0,
    }),

  ledger: (userId: number, params: CustomerLedgerParams) =>
    queryOptions({
      queryKey: [...customerKeyPrefix('ledger', userId), params],
      queryFn: () => getCustomerLedger(userId, params),
      enabled: userId > 0,
      // Paging/filtering must not flash a skeleton over rows already on screen.
      placeholderData: keepPreviousData,
      retry: 1,
    }),

  list: (params: CustomerListParams) =>
    queryOptions({
      queryKey: ['customer', 'list', params],
      queryFn: () => getCustomers(params),
      // Every page/sort/filter change is a new key; without this the skeleton flashes on each.
      placeholderData: keepPreviousData,
      // Safe for an idempotent GET; the adjust mutation must never auto-retry.
      retry: 1,
    }),

  activity: (userId: number, params: CustomerActivityParams) =>
    queryOptions({
      queryKey: [...customerKeyPrefix('activity', userId), params],
      queryFn: () => getCustomerActivity(userId, params),
      enabled: userId > 0,
      placeholderData: keepPreviousData,
      retry: 1,
    }),

  redemptions: (userId: number, params: DrawerPageOnlyParams) =>
    queryOptions({
      queryKey: [...customerKeyPrefix('redemptions', userId), params],
      queryFn: () => getCustomerRedemptions(userId, params),
      enabled: userId > 0,
      placeholderData: keepPreviousData,
      retry: 1,
    }),

  /** The bulk adjust dialog's count/liability. staleTime 0: each open reads the store as it is now. */
  adjustPreview: (input: AdjustPointsPreviewInput, enabled: boolean) =>
    queryOptions({
      queryKey: ['customer', 'adjust-preview', input],
      queryFn: ({ signal }) => previewAdjustPoints(input, signal),
      enabled,
      staleTime: 0,
      refetchOnWindowFocus: false,
      retry: 1,
    }),
};

export function useCustomerQuery(userId: number) {
  return useQuery(CUSTOMER_QUERIES.detail(userId));
}

export function useCustomerLedgerQuery(userId: number, params: CustomerLedgerParams) {
  return useQuery(CUSTOMER_QUERIES.ledger(userId, params));
}

export function useCustomersQuery(params: CustomerListParams = {}) {
  return useQuery(CUSTOMER_QUERIES.list(params));
}

export function useCustomerActivityQuery(userId: number, params: CustomerActivityParams) {
  return useQuery(CUSTOMER_QUERIES.activity(userId, params));
}

export function useCustomerRedemptionsQuery(userId: number, params: DrawerPageOnlyParams) {
  return useQuery(CUSTOMER_QUERIES.redemptions(userId, params));
}

export function useAdjustPointsPreviewQuery(input: AdjustPointsPreviewInput, enabled: boolean) {
  return useQuery(CUSTOMER_QUERIES.adjustPreview(input, enabled));
}

export function useAdjustPointsMutation(userId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['customer', 'adjust', userId],
    mutationFn: (input: AdjustPointsInput) => adjustCustomerPoints(userId, input),
    onSuccess: () => {
      // The list too, or the row shows the old balance until staleTime lapses.
      queryClient.invalidateQueries({ queryKey: customerKeyPrefix('detail', userId) });
      queryClient.invalidateQueries({ queryKey: customerKeyPrefix('ledger', userId) });
      // An adjustment writes a `points_adjusted` activity row.
      queryClient.invalidateQueries({ queryKey: customerKeyPrefix('activity', userId) });
      queryClient.invalidateQueries({ queryKey: ['customer', 'list'] });
      // The store-wide ledger, when the drawer is open over it.
      queryClient.invalidateQueries({ queryKey: ['customer', 'store-ledger'] });
    },
  });
}

/**
 * One POST /customers/{id}/adjust per customer, through settleInChunks();
 * a failed customer does not abort the rest. Adjustments are not
 * idempotent, so the dialog must never submit this twice.
 */
export function useBulkAdjustPointsMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['customer', 'adjust', 'bulk'],
    mutationFn: async ({ userIds, input }: { userIds: number[]; input: AdjustPointsInput }) => {
      const { succeeded, failedItems } = await settleInChunks(userIds, (userId) => adjustCustomerPoints(userId, input));

      return { succeeded, failed: failedItems.length, failedIds: failedItems };
    },
    // onSettled: a partly failed batch still moved real balances.
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['customer'] });
    },
  });
}
