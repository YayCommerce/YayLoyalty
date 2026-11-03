import { HTTPError } from 'ky';

import { api } from '@/lib/api/api';
import { Tier, TierInput, TierInUse, tierInUseErrorSchema, tierSchema, tiersSchema } from '@/lib/schema/tier.schema';

/** Client for Pro's tier catalogue routes. Lite has none of them. */

export async function getTiers(signal?: AbortSignal): Promise<Tier[]> {
  return tiersSchema.parse(await api.get('tiers', { signal }).json());
}

export async function createTier(input: TierInput): Promise<Tier> {
  return tierSchema.parse(await api.post('tiers', { json: input }).json());
}

/** PUT tiers/{id}. The server keeps every field the body leaves out. */
export async function updateTier(id: number, input: Partial<TierInput>): Promise<Tier> {
  return tierSchema.parse(await api.put(`tiers/${id}`, { json: input }).json());
}

export async function deleteTier(id: number): Promise<void> {
  await api.delete(`tiers/${id}`).json();
}

/** The server's refusal and the rewards that blocked a delete, or null when `error` is any other failure. */
export async function readTierInUse(error: unknown): Promise<TierInUse | null> {
  if (!(error instanceof HTTPError) || error.response.status !== 409) {
    return null;
  }

  // A clone, so toastApiError() can still read the body for any other 409.
  const body: unknown = await error.response
    .clone()
    .json()
    .catch(() => null);
  const parsed = tierInUseErrorSchema.safeParse(body);

  return parsed.success ? { message: parsed.data.message, rewards: parsed.data.data.rewards } : null;
}
