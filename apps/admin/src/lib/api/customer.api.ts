import { z } from 'zod';

import { api } from '@/lib/api/api';
import { toDrawerSearchParams, type DrawerPageParams } from '@/lib/api/customer-drawer.api';
import { paginatedSchema } from '@/lib/api/paginated';
import {
  AdjustPointsInput,
  adjustPointsPreviewSchema,
  AdjustPointsPreview,
  AdjustPointsPreviewInput,
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

/** `scenario` is the dev-only fixture switch; only `useDevScenario()` ever produces one. */
export async function getCustomer(userId: number, scenario?: string): Promise<Customer> {
  const searchParams: Record<string, string> = {};

  if (scenario) searchParams.scenario = scenario;

  return customerSchema.parse(await api.get(`customers/${userId}`, { searchParams }).json());
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
  const json = { points: input.points, note: input.note, adjust_lifetime: input.adjustLifetime };

  return ledgerEntrySchema.parse(await api.post(`customers/${userId}/adjust`, { json }).json());
}

/**
 * `POST /customers/adjust/preview`: a read-only snapshot for the adjust
 * dialog. POST despite being a read — mirrors the server's own reason (a
 * customer-id list should not sit in a URL or a server log).
 *
 * This mapping is spelling-only (camelCase → snake_case), same rule as
 * `getCustomers()` below. The tier → balance-bucket substitution
 * (`resolveBalanceRangeForQuery`) is page-specific domain logic and must
 * happen before `input` reaches here — this stays page-agnostic.
 */
export async function previewAdjustPoints(
  input: AdjustPointsPreviewInput,
  signal?: AbortSignal,
): Promise<AdjustPointsPreview> {
  const json =
    'customerIds' in input
      ? { customer_ids: input.customerIds }
      : {
          filters: {
            search: input.filters.search,
            balance_range: input.filters.balanceRange,
            expiring_soon: input.filters.expiringSoon,
            tier: input.filters.tier,
          },
        };

  return adjustPointsPreviewSchema.parse(await api.post('customers/adjust/preview', { json, signal }).json());
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
