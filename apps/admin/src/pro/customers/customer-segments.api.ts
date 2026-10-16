import * as v from 'valibot';

import { api } from '@/lib/api/api';
import { BALANCE_RANGE_OPTIONS, EXPIRING_SOON_OPTIONS } from '@/pages/customers/customers-page.filter-rows';

/**
 * Mirrors YayLoyalty\Pro\Helpers\CustomerSegmentsHelper::FILTER_SCHEMA, the source of truth.
 * Client for POST/DELETE /customers/segments. The two converters below are the one place the
 * wire shape meets the app's camelCase RowFilters.
 */

/** Keeps the value union, not `string`, so SavedSegment['filters'] is assignable to RowFilters. */
function enumOfOptions<T extends string>(options: readonly { value: T }[]) {
  return v.picklist(options.map((option) => option.value) as [T, ...T[]]);
}

const balanceRangeSchema = enumOfOptions(BALANCE_RANGE_OPTIONS);
const expiringSoonSchema = enumOfOptions(EXPIRING_SOON_OPTIONS);

/** What the REST route and window.yayLoyaltyAdmin.segments carry. */
const savedSegmentWireSchema = v.object({
  name: v.string(),
  filters: v.object({
    balance_range: balanceRangeSchema,
    expiring_soon: expiringSoonSchema,
  }),
});

type SavedSegmentWire = v.InferOutput<typeof savedSegmentWireSchema>;

export const savedSegmentSchema = v.object({
  name: v.string(),
  filters: v.object({
    balanceRange: balanceRangeSchema,
    expiringSoon: expiringSoonSchema,
  }),
});

export type SavedSegment = v.InferOutput<typeof savedSegmentSchema>;

function toWireFilters({ balanceRange, expiringSoon }: SavedSegment['filters']): SavedSegmentWire['filters'] {
  return { balance_range: balanceRange, expiring_soon: expiringSoon };
}

function fromWire({ name, filters }: SavedSegmentWire): SavedSegment {
  return {
    name,
    filters: { balanceRange: filters.balance_range, expiringSoon: filters.expiring_soon },
  };
}

/** Parses the wire list and converts it to the app shape. */
function parseSegments(payload: unknown): SavedSegment[] {
  return v.parse(v.array(savedSegmentWireSchema), payload).map(fromWire);
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
  const result = v.safeParse(v.array(savedSegmentWireSchema), window.yayLoyaltyAdmin?.segments ?? []);

  return result.success ? result.output.map(fromWire) : [];
}
