import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import {
  Campaign,
  CampaignCreateInput,
  campaignDeleteResponseSchema,
  CampaignListParams,
  campaignSchema,
  CampaignStatus,
  CampaignUpdateInput,
} from '@/lib/schema/campaign.schema';

/** Responses are parsed, not cast — see customer.api.ts. */

export async function getCampaigns(params: CampaignListParams = {}) {
  const searchParams: Record<string, string> = {};

  // Filtering happens server-side so paging stays correct.
  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.trigger) searchParams.trigger = params.trigger;
  if (params.status) searchParams.status = params.status;
  if (params.search) searchParams.search = params.search;

  const payload = await api.get('campaigns', { searchParams }).json();

  return parsePaginated<typeof campaignSchema>(campaignSchema, payload);
}

export async function getCampaign(id: number): Promise<Campaign> {
  return campaignSchema.parse(await api.get(`campaigns/${id}`).json());
}

/** POST campaigns. The server generates `slug` from `name`; nothing is created until this is called. */
export async function createCampaign(input: CampaignCreateInput): Promise<Campaign> {
  return campaignSchema.parse(await api.post('campaigns', { json: input }).json());
}

/** PUT campaigns/{id}. Every field is optional server-side except this type's own required ones; see updateCampaignStatus() for a narrower partial write. */
export async function updateCampaign(id: number, input: Partial<CampaignUpdateInput>): Promise<Campaign> {
  return campaignSchema.parse(await api.put(`campaigns/${id}`, { json: input }).json());
}

/** PUT campaigns/{id} with only `status` — update_item() applies whichever fields the body carries, so a single-key body is a real partial write, not a full-body requirement in disguise. */
export async function updateCampaignStatus(id: number, status: CampaignStatus): Promise<Campaign> {
  return campaignSchema.parse(await api.put(`campaigns/${id}`, { json: { status } }).json());
}

/** PUT campaigns/{id} with only `config` — same reasoning as updateCampaignStatus(); BuiltInCampaignList edits config without touching name/priority/conditions/dates. */
export async function updateCampaignConfig(id: number, config: Campaign['config']): Promise<Campaign> {
  return campaignSchema.parse(await api.put(`campaigns/${id}`, { json: { config } }).json());
}

/** DELETE campaigns/{id}. 409s if the campaign has ledger rows; see Campaign.is_deletable. */
export async function deleteCampaign(id: number) {
  return campaignDeleteResponseSchema.parse(await api.delete(`campaigns/${id}`).json());
}
