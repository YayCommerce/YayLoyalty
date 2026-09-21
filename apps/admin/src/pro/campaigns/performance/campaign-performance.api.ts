import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import {
  CampaignPerformanceEntriesParams,
  campaignPerformanceEntrySchema,
  campaignPerformanceSchema,
} from '@/lib/schema/campaign-performance.schema';

/**
 * Clients for the two Pro-only routes behind CampaignPerformanceRestController.
 * Lite has neither route at all (404, not 403) — see that controller's
 * docblock. Both currently answer from CampaignPerformanceFixture; that is a
 * SWAP POINT on the PHP side only — these two functions do not change when
 * it flips to real data.
 *
 * Responses are parsed, not cast — see customer.api.ts.
 */

export async function getCampaignPerformance(id: number) {
  return campaignPerformanceSchema.parse(await api.get(`campaigns/${id}/performance`).json());
}

export async function getCampaignPerformanceEntries(id: number, params: CampaignPerformanceEntriesParams) {
  const searchParams: Record<string, string> = { page: String(params.page) };

  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.search) searchParams.search = params.search;

  const payload = await api.get(`campaigns/${id}/performance/entries`, { searchParams }).json();

  return parsePaginated<typeof campaignPerformanceEntrySchema>(campaignPerformanceEntrySchema, payload);
}
