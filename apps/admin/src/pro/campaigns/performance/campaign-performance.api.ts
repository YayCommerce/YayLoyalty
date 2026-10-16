import * as v from 'valibot';

import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import {
  CampaignPerformanceEntriesParams,
  campaignPerformanceEntrySchema,
  campaignPerformanceSchema,
} from '@/lib/schema/campaign-performance.schema';

/**
 * Clients for the two Pro-only routes behind CampaignPerformanceRestController. Lite has neither
 * route (404, not 403). Responses are parsed, not cast.
 */

export async function getCampaignPerformance(id: number) {
  return v.parse(campaignPerformanceSchema, await api.get(`campaigns/${id}/performance`).json());
}

export async function getCampaignPerformanceEntries(id: number, params: CampaignPerformanceEntriesParams) {
  const searchParams: Record<string, string> = { page: String(params.page) };

  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.search) searchParams.search = params.search;

  const payload = await api.get(`campaigns/${id}/performance/entries`, { searchParams }).json();

  return parsePaginated<typeof campaignPerformanceEntrySchema>(campaignPerformanceEntrySchema, payload);
}
