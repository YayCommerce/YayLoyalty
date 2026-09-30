import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import { RewardListParams, rewardSchema } from '@/lib/schema/reward.schema';

/** Responses are parsed, not cast — see customer.api.ts. */

export async function getRewards(params: RewardListParams = {}) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.type) searchParams.type = params.type;
  if (params.status) searchParams.status = params.status;
  if (params.search) searchParams.search = params.search;

  const payload = await api.get('rewards', { searchParams }).json();

  return parsePaginated<typeof rewardSchema>(rewardSchema, payload);
}
