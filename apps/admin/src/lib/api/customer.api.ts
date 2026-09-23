import { z } from 'zod';

import { api } from '@/lib/api/api';
import { toDrawerSearchParams, type DrawerPageParams } from '@/lib/api/customer-drawer.api';
import { paginatedSchema } from '@/lib/api/paginated';
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
import { LedgerEntry, ledgerEntrySchema, ledgerEnvelopeSchema, LedgerFilterType } from '@/lib/schema/ledger.schema';

/** Every response is parsed, not cast: `.json<T>()` is unchecked, and a bad shape would white-screen a component. */

export async function getCustomer(userId: number): Promise<Customer> {
  return customerSchema.parse(await api.get(`customers/${userId}`).json());
}

export type CustomerLedgerParams = DrawerPageParams & { type?: LedgerFilterType };

/**
 * `GET /customers/{id}/ledger`. Parsed with `ledgerEnvelopeSchema`, not the
 * generic `parsePaginated()` helper — that helper's envelope shape has no
 * `type_counts` field, so it would silently drop it.
 */
export async function getCustomerLedger(userId: number, params: CustomerLedgerParams = {}) {
  const searchParams = toDrawerSearchParams(params);

  if (params.type && params.type !== 'all') searchParams.type = params.type;

  const payload = await api.get(`customers/${userId}/ledger`, { searchParams }).json();

  return ledgerEnvelopeSchema.parse(payload);
}

export async function adjustCustomerPoints(userId: number, input: AdjustPointsInput): Promise<LedgerEntry> {
  return ledgerEntrySchema.parse(await api.post(`customers/${userId}/adjust`, { json: input }).json());
}

/** App-side params for getCustomers(); the wire spelling exists only in the request builder. */
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
  // Absent whenever the result is not empty.
  suggestions: z.array(noMatchSuggestionSchema).optional(),
});

/** GET /customers: search, filter, sort and paginate server-side. `signal` lets a multi-page walk cancel. */
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
