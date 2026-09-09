import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import { Campaign, CampaignListParams, campaignSchema } from '@/lib/schema/campaign.schema';

/** Responses are parsed, not cast — see customer.api.ts. */

export async function getCampaigns(params: CampaignListParams = {}) {
  const searchParams: Record<string, string> = {};

  // Filtering happens server-side so paging stays correct.
  if (params.page) searchParams.page = String(params.page);
  if (params.per_page) searchParams.per_page = String(params.per_page);
  if (params.trigger) searchParams.trigger = params.trigger;
  if (params.status) searchParams.status = params.status;
  if (params.search) searchParams.search = params.search;

  const payload = await api.get('campaigns', { searchParams }).json();

  return parsePaginated<typeof campaignSchema>(campaignSchema, payload);
}

export async function getCampaign(id: number): Promise<Campaign> {
  return campaignSchema.parse(await api.get(`campaigns/${id}`).json());
}
