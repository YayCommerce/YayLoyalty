import { z } from 'zod';

import { api } from '@/lib/api/api';
import {
  BALANCE_RANGE_OPTIONS,
  EXPIRING_SOON_OPTIONS,
  TIER_OPTIONS,
} from '@/pages/customers/customers-page.filter-rows';

/**
 * Client for POST/DELETE /customers/segments. The wire shape mirrors
 * CustomerSegmentsHelper::FILTER_SCHEMA; the two converters below are the
 * one place it meets the app's camelCase RowFilters.
 */

/** Keeps the value union, not `string`, so SavedSegment['filters'] is assignable to RowFilters. */
function enumOfOptions<T extends string>(options: readonly { value: T }[]) {
  return z.enum(options.map((option) => option.value) as [T, ...T[]]);
}

const balanceRangeSchema = enumOfOptions(BALANCE_RANGE_OPTIONS);
const expiringSoonSchema = enumOfOptions(EXPIRING_SOON_OPTIONS);
const tierSchema = enumOfOptions(TIER_OPTIONS);

/** What the REST route and window.yayLoyaltyAdmin.segments carry. */
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

/** Parses the wire list and converts it to the app shape. */
function parseSegments(payload: unknown): SavedSegment[] {
  return z.array(savedSegmentWireSchema).parse(payload).map(fromWire);
}

export async function saveSegment(name: string, filters: SavedSegment['filters']): Promise<SavedSegment[]> {
  const payload = await api.post('customers/segments', { json: { name, filters: toWireFilters(filters) } }).json();

  return parseSegments(payload);
}

/** `name` goes in the JSON body, not the URL: it is arbitrary text. */
export async function removeSegment(name: string): Promise<SavedSegment[]> {
  const payload = await api.delete('customers/segments', { json: { name } }).json();

  return parseSegments(payload);
}

/** The segments PHP localised at render; a broken payload degrades to an empty list. */
export function readSegmentsFromLocalize(): SavedSegment[] {
  const result = z.array(savedSegmentWireSchema).safeParse(window.yayLoyaltyAdmin?.segments ?? []);

  return result.success ? result.data.map(fromWire) : [];
}
