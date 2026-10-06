import { queryOptions, useQuery } from '@tanstack/react-query';

import { getRewards } from '@/lib/api/reward.api';
import type { RewardListParams } from '@/lib/schema/reward.schema';

/** ─── Query Options ─────────────────────────────────── */

export const REWARD_QUERIES = {
  list: (params: RewardListParams) =>
    queryOptions({
      queryKey: ['reward', 'list', params],
      queryFn: () => getRewards(params),
    }),
};

/** ─── Hooks ─────────────────────────────────────────── */

export function useRewardsQuery(params: RewardListParams = {}) {
  return useQuery(REWARD_QUERIES.list(params));
}
