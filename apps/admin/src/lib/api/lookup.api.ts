import { api } from '@/lib/api/api';
import { LookupEntity, lookupResponseSchema } from '@/lib/schema/lookup.schema';

/** App-side params for getLookup(); `ids` wins over `search` server-side. */
export type LookupParams = {
  search?: string;
  ids?: number[];
};

/** GET /lookups/{entity}: picker rows by search term, or the labels for stored ids. */
export async function getLookup(entity: LookupEntity, params: LookupParams = {}, signal?: AbortSignal) {
  const searchParams: Record<string, string> = {};

  if (params.search) searchParams.search = params.search;
  if (params.ids?.length) searchParams.ids = params.ids.join(',');

  const payload = await api.get(`lookups/${entity}`, { searchParams, signal }).json();

  return lookupResponseSchema.parse(payload).items;
}
