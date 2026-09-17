import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  adjustCustomerPoints,
  getCustomer,
  getCustomerLedger,
  getCustomers,
  type CustomerListParams,
} from '@/lib/api/customer.api';
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
      queryFn: () => getCustomerLedger(userId, { page, perPage }),
      enabled: userId > 0,
    }),

  list: (params: CustomerListParams) =>
    queryOptions({
      queryKey: ['customer', 'list', params],
      queryFn: () => getCustomers(params),
      // Every page/sort/filter change is a distinct queryKey — without this, each
      // one drops back to zero rows until it resolves, flashing the loading
      // skeleton over a table that's mostly showing the same result. Keeping the
      // previous page's rows on screen (marked `isPlaceholderData` on the query)
      // while the new one loads removes that flash; `CustomersPage.tsx` only
      // treats `isPending` (no data at all yet) as "loading" now.
      placeholderData: keepPreviousData,
      // GET is idempotent, so one retry against a one-off network blip is safe —
      // unlike the adjust mutation below, which must never auto-retry.
      retry: 1,
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

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
      // All three change together. Invalidating only detail/ledger leaves this
      // customer's row on the Customers list showing the balance from before
      // the adjustment until staleTime (5 min) lapses or the page is reloaded.
      queryClient.invalidateQueries({ queryKey: ['customer', 'detail', userId] });
      queryClient.invalidateQueries({ queryKey: ['customer', 'ledger', userId] });
      queryClient.invalidateQueries({ queryKey: ['customer', 'list'] });
    },
  });
}

/**
 * Requests in flight at once for `useBulkAdjustPointsMutation` — bulk-adjusting
 * a few thousand selected customers must not mean a few thousand concurrent
 * `POST` requests landing on the WP REST API in the same tick; that's a
 * self-inflicted spike, not a legitimate load pattern. 10 keeps it moving
 * without doing that.
 */
const BULK_ADJUST_CONCURRENCY = 10;

/**
 * Bulk version of the same `POST /customers/{id}/adjust` call — there is no
 * bulk endpoint, so this fires one request per customer, `BULK_ADJUST_CONCURRENCY`
 * at a time (one failed customer doesn't abort the rest — each chunk resolves
 * via `Promise.allSettled`) and reports how many of each. Adjustments are
 * deliberately not idempotent (see `CustomerRestController::adjust_points()`,
 * `LedgerService::adjust()` and `Enums\ReferenceType`), so this is only ever
 * invoked once per dialog submit — the dialog disables its submit button for
 * the duration via `isAdjustSubmitting` (see `customers-page.bulk-actions.ts`).
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
    // onSettled, not onSuccess: even a batch that's part failures still moved
    // real balances for the ones that went through — those must not stay stale.
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['customer'] });
    },
  });
}
