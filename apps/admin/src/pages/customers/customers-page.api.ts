import { z } from 'zod';

import { api } from '@/lib/api/api';
import { paginatedSchema } from '@/lib/api/paginated';
import type { RowFilters } from './customers-page.filter-rows';
import type { MockCustomer } from './customers-page.mock-data';
import type { SortDirection, SortKey } from './customers-page.sort-rows';

/**
 * TEMPORARY DEMO DATA, FETCHED OVER A REAL HTTP ROUND TRIP.
 *
 * `GET /customers` search/filters then slices the same fixed 30 rows by
 * `page`/`per_page` — see `CustomerRestController::get_items()` /
 * `CustomerRowFilters.php` / `CustomerDemoRows.php` on the PHP side, which
 * this schema mirrors field-for-field. This exists so the page has a genuine
 * loading state to show (real network latency), not a simulated `setTimeout`,
 * per an explicit request — not because the demo data became any more real.
 *
 * Deleted, along with the PHP method/class above, once a real Customers list
 * endpoint exists. Never import this outside `pages/customers/`.
 */
const customersDemoItemSchema = z.object({
  user_id: z.number().int(),
  name: z.string(),
  email: z.string(),
  balance: z.number().int(),
  pending_points: z.number().int(),
  lifetime_earned: z.number().int(),
  last_activity_label: z.string(),
  last_activity_minutes_ago: z.number(),
  expires_at: z.string().nullable(),
  tier: z.string(),
  referrals: z.number().int(),
}) satisfies z.ZodType<MockCustomer>;

/**
 * Raw "drop this filter → N rows" counts the server folds into this same
 * response once the current filters match zero rows — see
 * `CustomerRowFilters::suggestions_for_no_match()`. `key` matches the admin
 * app's `FilterKey` (`customers-page.filter-suggestions.ts`) directly; the
 * label/phrasing text is built client-side from it, not sent over the wire.
 */
const noMatchSuggestionSchema = z.object({
  key: z.enum(['search', 'balanceRange', 'expiringSoon', 'tier']),
  count: z.number().int(),
});

const customersDemoEnvelopeSchema = paginatedSchema(customersDemoItemSchema).extend({
  // Absent whenever the result isn't empty — see the PHP method above.
  suggestions: z.array(noMatchSuggestionSchema).optional(),
});

export type CustomersDemoListParams = {
  page?: number;
  per_page?: number;
  orderby?: SortKey;
  order?: SortDirection;
} & Partial<RowFilters>;

export async function getCustomersDemo(params: CustomersDemoListParams = {}) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.per_page) searchParams.per_page = String(params.per_page);
  // Sent to the server so sort/search/filter are checked against the whole
  // (demo) dataset, not just whatever page happened to already be loaded.
  if (params.orderby) searchParams.orderby = params.orderby;
  if (params.order) searchParams.order = params.order;
  if (params.search?.trim()) searchParams.search = params.search.trim();
  if (params.balanceRange && params.balanceRange !== 'any') searchParams.balance_range = params.balanceRange;
  if (params.expiringSoon && params.expiringSoon !== 'off') searchParams.expiring_soon = params.expiringSoon;
  if (params.tier && params.tier !== 'all') searchParams.tier = params.tier;

  const payload = await api.get('customers', { searchParams }).json();

  return customersDemoEnvelopeSchema.parse(payload);
}
