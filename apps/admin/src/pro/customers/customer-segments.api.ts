import { z } from 'zod';

import { api } from '@/lib/api/api';
import { BALANCE_RANGE_OPTIONS, EXPIRING_SOON_OPTIONS, TIER_OPTIONS } from '@/pages/customers/customers-page.filter-rows';

/**
 * Client for POST/DELETE /customers/segments — saved Customers-page filter sets.
 *
 * Lives under `pro/` because the route only exists in a Pro build — Lite has
 * no `yayloyalty_customer_segments` option and no such feature to offer.
 * Reached only through the `@/pro` barrel, so a Lite build drops this file
 * with the rest of the subtree.
 *
 * Mirrors Pro\Helpers\CustomerSegmentsHelper::FILTER_SCHEMA field-for-field.
 */

export const savedSegmentSchema = z.object({
  name: z.string(),
  filters: z.object({
    balanceRange: z.enum(BALANCE_RANGE_OPTIONS.map((option) => option.value) as [string, ...string[]]),
    expiringSoon: z.enum(EXPIRING_SOON_OPTIONS.map((option) => option.value) as [string, ...string[]]),
    tier: z.enum(TIER_OPTIONS.map((option) => option.value) as [string, ...string[]]),
  }),
});

export type SavedSegment = z.infer<typeof savedSegmentSchema>;

/** Responses are parsed, not cast — see customer.api.ts. */
export async function saveSegment(name: string, filters: SavedSegment['filters']): Promise<SavedSegment[]> {
  const payload = await api.post('customers/segments', { json: { name, filters } }).json();

  return z.array(savedSegmentSchema).parse(payload);
}

/**
 * `name` goes in the JSON body, not the URL — `Pro\Controllers\CustomerSegmentsRestController`
 * reads it the same way as `saveSegment()`'s POST, since a segment name is
 * arbitrary text (spaces, non-ASCII) and WP_REST_Request reads a JSON body
 * regardless of HTTP method.
 */
export async function removeSegment(name: string): Promise<SavedSegment[]> {
  const payload = await api.delete('customers/segments', { json: { name } }).json();

  return z.array(savedSegmentSchema).parse(payload);
}

/**
 * Reads the segments PHP localised at render (`window.yayLoyaltyAdmin.segments`).
 * A broken/absent payload degrades to an empty list rather than crashing the
 * page — same rule `lib/access.tsx` applies to the rest of the bootstrap data.
 */
export function readSegmentsFromLocalize(): SavedSegment[] {
  const result = z.array(savedSegmentSchema).safeParse(window.yayLoyaltyAdmin?.segments ?? []);

  return result.success ? result.data : [];
}
