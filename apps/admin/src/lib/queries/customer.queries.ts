import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { adjustCustomerPoints, getCustomer, getCustomerLedger } from '@/lib/api/customer.api';
import type { AdjustPointsInput } from '@/lib/schema/customer.schema';

/** ─── Query Options ─────────────────────────────────── */

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
      queryFn: () => getCustomerLedger(userId, { page, per_page: perPage }),
      enabled: userId > 0,
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useCustomerQuery(userId: number) {
  return useQuery(CUSTOMER_QUERIES.detail(userId));
}

export function useCustomerLedgerQuery(userId: number, page: number, perPage = 10) {
  return useQuery(CUSTOMER_QUERIES.ledger(userId, page, perPage));
}

export function useAdjustPointsMutation(userId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationKey: ['customer', 'adjust', userId],
    mutationFn: (input: AdjustPointsInput) => adjustCustomerPoints(userId, input),
    onSuccess: () => {
      // Both change together. Invalidating only one shows a stale balance next
      // to a fresh history row, which is the obvious bug.
      queryClient.invalidateQueries({ queryKey: ['customer', 'detail', userId] });
      queryClient.invalidateQueries({ queryKey: ['customer', 'ledger', userId] });
    },
  });
}
