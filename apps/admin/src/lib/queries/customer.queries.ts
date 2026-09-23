import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  getCustomerActivity,
  getCustomerRedemptions,
  getCustomerReferrals,
  type CustomerActivityParams,
  type DrawerPageOnlyParams,
} from '@/lib/api/customer-drawer.api';
import {
  adjustCustomerPoints,
  getCustomer,
  getCustomerLedger,
  getCustomers,
  type CustomerLedgerParams,
  type CustomerListParams,
} from '@/lib/api/customer.api';
import type { AdjustPointsInput } from '@/lib/schema/customer.schema';

/**
 * `['customer', kind, userId]` — the one place that owns this shape, so the
 * factory below and `useAdjustPointsMutation`'s invalidations can't drift
 * apart into two different prefixes for the same cache entries.
 */
function customerKeyPrefix(kind: 'detail' | 'ledger' | 'activity' | 'redemptions' | 'referrals', userId: number) {
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

  /**
   * Paged, exactly like `ledger` above — not the infinite/"Load more" shape
   * this started as (see `ActivityLogTab.tsx`'s docblock for why it
   * changed). Same `keepPreviousData` reasoning: paging/filtering must not
   * flash a skeleton over rows already on screen.
   */
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

  referrals: (userId: number, params: DrawerPageOnlyParams) =>
    queryOptions({
      queryKey: [...customerKeyPrefix('referrals', userId), params],
      queryFn: () => getCustomerReferrals(userId, params),
      enabled: userId > 0,
      placeholderData: keepPreviousData,
      retry: 1,
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

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

export function useCustomerReferralsQuery(userId: number, params: DrawerPageOnlyParams) {
  return useQuery(CUSTOMER_QUERIES.referrals(userId, params));
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
