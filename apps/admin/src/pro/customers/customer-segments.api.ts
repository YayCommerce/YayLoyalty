import { z } from 'zod';

import { api } from '@/lib/api/api';
import {
  BALANCE_RANGE_OPTIONS,
  EXPIRING_SOON_OPTIONS,
  TIER_OPTIONS,
} from '@/pages/customers/customers-page.filter-rows';

/**
 * Client for POST/DELETE /customers/segments — saved Customers-page filter sets.
 *
 * Lives under `pro/` because the route only exists in a Pro build — Lite has
 * no `yayloyalty_customer_segments` option and no such feature to offer.
 * Reached only through the `@/pro` barrel, so a Lite build drops this file
 * with the rest of the subtree.
 *
 * Wire/stored shape mirrors Pro\Helpers\CustomerSegmentsHelper::FILTER_SCHEMA
 * field-for-field, in the wire's `snake_case` (`balance_range`, matching the
 * `GET /customers` query params). The rest of the app only ever sees
 * `SavedSegment`, whose `filters` is a camelCase `RowFilters` subset — the
 * two converters below are the one place the spellings meet, the same
 * boundary rule `lib/api/customer.api.ts` follows.
 */

/** Enum of an options array's values, keeping the value union (not widened to `string`) so `SavedSegment['filters']` is assignable to `RowFilters`. */
function enumOfOptions<T extends string>(options: readonly { value: T }[]) {
  return z.enum(options.map((option) => option.value) as [T, ...T[]]);
}

const balanceRangeSchema = enumOfOptions(BALANCE_RANGE_OPTIONS);
const expiringSoonSchema = enumOfOptions(EXPIRING_SOON_OPTIONS);
const tierSchema = enumOfOptions(TIER_OPTIONS);

/** What the REST route and `window.yayLoyaltyAdmin.segments` actually carry. */
const savedSegmentWireSchema = z.object({
  name: z.string(),
  filters: z.object({
    balance_range: balanceRangeSchema,
    expiring_soon: expiringSoonSchema,
    tier: tierSchema,
  }),
});

type SavedSegmentWire = z.infer<typeof savedSegmentWireSchema>;

export const savedSegmentSchema = z.object({
  name: z.string(),
  filters: z.object({
    balanceRange: balanceRangeSchema,
    expiringSoon: expiringSoonSchema,
    tier: tierSchema,
  }),
});

export type SavedSegment = z.infer<typeof savedSegmentSchema>;

function toWireFilters({ balanceRange, expiringSoon, tier }: SavedSegment['filters']): SavedSegmentWire['filters'] {
  return { balance_range: balanceRange, expiring_soon: expiringSoon, tier };
}

function fromWire({ name, filters }: SavedSegmentWire): SavedSegment {
  return {
    name,
    filters: { balanceRange: filters.balance_range, expiringSoon: filters.expiring_soon, tier: filters.tier },
  };
}

/** Parses the wire list (see customer.api.ts — parsed, not cast) and converts it to the app shape. */
function parseSegments(payload: unknown): SavedSegment[] {
  return z.array(savedSegmentWireSchema).parse(payload).map(fromWire);
}

export async function saveSegment(name: string, filters: SavedSegment['filters']): Promise<SavedSegment[]> {
  const payload = await api.post('customers/segments', { json: { name, filters: toWireFilters(filters) } }).json();

  return parseSegments(payload);
}

/**
 * `name` goes in the JSON body, not the URL — `Pro\Controllers\CustomerSegmentsRestController`
 * reads it the same way as `saveSegment()`'s POST, since a segment name is
 * arbitrary text (spaces, non-ASCII) and WP_REST_Request reads a JSON body
 * regardless of HTTP method.
 */
export async function removeSegment(name: string): Promise<SavedSegment[]> {
  const payload = await api.delete('customers/segments', { json: { name } }).json();

  return parseSegments(payload);
}

/**
 * Reads the segments PHP localised at render (`window.yayLoyaltyAdmin.segments`).
 * A broken/absent payload degrades to an empty list rather than crashing the
 * page — same rule `lib/access.tsx` applies to the rest of the bootstrap data.
 */
export function readSegmentsFromLocalize(): SavedSegment[] {
  const result = z.array(savedSegmentWireSchema).safeParse(window.yayLoyaltyAdmin?.segments ?? []);

  return result.success ? result.data.map(fromWire) : [];
}
