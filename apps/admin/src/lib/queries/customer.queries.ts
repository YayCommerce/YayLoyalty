import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  adjustCustomerPoints,
  getCustomer,
  getCustomerLedger,
  getCustomers,
  type CustomerListParams,
} from '@/lib/api/customer.api';
import type { AdjustPointsInput } from '@/lib/schema/customer.schema';

export const CUSTOMER_QUERIES = {
  detail: (userId: number) =>
    queryOptions({
      queryKey: ['customer', 'detail', userId],
      queryFn: () => getCustomer(userId),
      enabled: userId > 0,
    }),

  ledger: (userId: number, page: number, perPage: number) =>
    queryOptions({
      queryKey: ['customer', 'ledger', userId, page, perPage],
      queryFn: () => getCustomerLedger(userId, { page, perPage }),
      enabled: userId > 0,
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
};

export function useCustomerQuery(userId: number) {
  return useQuery(CUSTOMER_QUERIES.detail(userId));
}

export function useCustomerLedgerQuery(userId: number, page: number, perPage = 10) {
  return useQuery(CUSTOMER_QUERIES.ledger(userId, page, perPage));
}

export function useCustomersQuery(params: CustomerListParams = {}) {
  return useQuery(CUSTOMER_QUERIES.list(params));
}

export function useAdjustPointsMutation(userId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['customer', 'adjust', userId],
    mutationFn: (input: AdjustPointsInput) => adjustCustomerPoints(userId, input),
    onSuccess: () => {
      // The list too, or the row shows the old balance until staleTime lapses.
      queryClient.invalidateQueries({ queryKey: ['customer', 'detail', userId] });
      queryClient.invalidateQueries({ queryKey: ['customer', 'ledger', userId] });
      queryClient.invalidateQueries({ queryKey: ['customer', 'list'] });
    },
  });
}

/** Concurrent requests for the bulk adjust; thousands at once would be a self-inflicted spike. */
const BULK_ADJUST_CONCURRENCY = 10;

/**
 * One POST /customers/{id}/adjust per customer, BULK_ADJUST_CONCURRENCY at
 * a time; a failed customer does not abort the rest. Adjustments are not
 * idempotent, so the dialog must never submit this twice.
 */
export function useBulkAdjustPointsMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['customer', 'adjust', 'bulk'],
    mutationFn: async ({ userIds, input }: { userIds: number[]; input: AdjustPointsInput }) => {
      let succeeded = 0;
      let failed = 0;

      for (let i = 0; i < userIds.length; i += BULK_ADJUST_CONCURRENCY) {
        const chunk = userIds.slice(i, i + BULK_ADJUST_CONCURRENCY);
        const results = await Promise.allSettled(chunk.map((userId) => adjustCustomerPoints(userId, input)));

        succeeded += results.filter((result) => result.status === 'fulfilled').length;
        failed += results.filter((result) => result.status === 'rejected').length;
      }

      return { succeeded, failed };
    },
    // onSettled: a partly failed batch still moved real balances.
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['customer'] });
    },
  });
}
