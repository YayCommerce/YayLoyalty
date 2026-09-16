import { z } from 'zod';

import { api } from '@/lib/api/api';
import { paginatedSchema, parsePaginated } from '@/lib/api/paginated';
import {
  AdjustPointsInput,
  Customer,
  CustomerBalanceRange,
  CustomerExpiringSoon,
  customerListRowSchema,
  customerSchema,
  CustomerSortDirection,
  CustomerSortKey,
  CustomerTier,
  noMatchSuggestionSchema,
} from '@/lib/schema/customer.schema';
import { LedgerEntry, ledgerEntrySchema } from '@/lib/schema/ledger.schema';

/**
 * Every response is `.parse()`d rather than cast.
 *
 * `.json<T>()` is an unchecked TypeScript assertion: it tells the compiler what
 * the shape is without checking. A null where a number is expected then reaches
 * a component and white-screens the page. Parsing turns that into a caught
 * error at the boundary.
 */

export async function getCustomer(userId: number): Promise<Customer> {
  return customerSchema.parse(await api.get(`customers/${userId}`).json());
}

export async function getCustomerLedger(userId: number, params: { page?: number; perPage?: number } = {}) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);

  const payload = await api.get(`customers/${userId}/ledger`, { searchParams }).json();

  return parsePaginated<typeof ledgerEntrySchema>(ledgerEntrySchema, payload);
}

export async function adjustCustomerPoints(userId: number, input: AdjustPointsInput): Promise<LedgerEntry> {
  return ledgerEntrySchema.parse(await api.post(`customers/${userId}/adjust`, { json: input }).json());
}

/**
 * App-side (camelCase) params for `getCustomers()`. The wire spelling
 * (`per_page`, `orderby`, `balance_range`, `expiring_soon` — WP REST core
 * style, see `CustomerRestController::get_items()` args) exists only inside
 * the request builder below: one conversion point, so no caller has to know
 * how the server spells a query param.
 */
export type CustomerListParams = {
  page?: number;
  perPage?: number;
  orderBy?: CustomerSortKey;
  order?: CustomerSortDirection;
  search?: string;
  balanceRange?: CustomerBalanceRange;
  expiringSoon?: CustomerExpiringSoon;
  tier?: CustomerTier;
};

const customerListEnvelopeSchema = paginatedSchema(customerListRowSchema).extend({
  // Absent whenever the result isn't empty — see CustomerRestController::get_items().
  suggestions: z.array(noMatchSuggestionSchema).optional(),
});

/**
 * `GET /customers` — the admin Customers list: search + filter + sort + paginate, all server-side.
 *
 * `signal` is optional and only matters to `fetchAllMatchingCustomers()`'s
 * multi-page loop (Export CSV, bulk Adjust points) — a caller that cancels
 * mid-loop (e.g. on unmount) aborts whichever request is in flight instead
 * of letting a large sequential fetch run to completion for no reason.
 */
export async function getCustomers(params: CustomerListParams = {}, signal?: AbortSignal) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.orderBy) searchParams.orderby = params.orderBy;
  if (params.order) searchParams.order = params.order;
  if (params.search?.trim()) searchParams.search = params.search.trim();
  if (params.balanceRange && params.balanceRange !== 'any') searchParams.balance_range = params.balanceRange;
  if (params.expiringSoon && params.expiringSoon !== 'off') searchParams.expiring_soon = params.expiringSoon;
  if (params.tier && params.tier !== 'all') searchParams.tier = params.tier;

  const payload = await api.get('customers', { searchParams, signal }).json();

  return customerListEnvelopeSchema.parse(payload);
}
