import { api } from '@/lib/api/api';
import { parsePaginated } from '@/lib/api/paginated';
import {
  Reward,
  RewardCreateInput,
  rewardDeleteResponseSchema,
  RewardListParams,
  rewardSchema,
  RewardStatus,
  RewardUpdateInput,
} from '@/lib/schema/reward.schema';

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

export async function getReward(id: number): Promise<Reward> {
  return rewardSchema.parse(await api.get(`rewards/${id}`).json());
}

/** POST rewards. Catalog types only; the server generates `slug` from `name` and appends the row last. */
export async function createReward(input: RewardCreateInput): Promise<Reward> {
  return rewardSchema.parse(await api.post('rewards', { json: input }).json());
}

/** PUT rewards/{id}. update_item() applies only the keys the body carries. */
export async function updateReward(id: number, input: Partial<RewardUpdateInput>): Promise<Reward> {
  return rewardSchema.parse(await api.put(`rewards/${id}`, { json: input }).json());
}

/** PUT rewards/{id} with only `status`: pause, resume, archive and restore leave every other field as stored. */
export async function updateRewardStatus(id: number, status: RewardStatus): Promise<Reward> {
  return updateReward(id, { status });
}

/** DELETE rewards/{id}. 409s once the reward has been claimed; see Reward.is_deletable. */
export async function deleteReward(id: number) {
  return rewardDeleteResponseSchema.parse(await api.delete(`rewards/${id}`).json());
}

/** POST rewards/reorder: the listed ids first, in order; RewardRepository::reorder() renumbers every other row after them. */
export async function reorderRewards(ids: number[]) {
  await api.post('rewards/reorder', { json: { ids } }).json();
}
