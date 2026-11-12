import { HTTPError } from 'ky';

import { api } from '@/lib/api/api';
import {
  AssignCustomerTierInput,
  CustomerTier,
  customerTierSchema,
  Tier,
  TierInput,
  TierInUse,
  tierInUseErrorSchema,
  tierSchema,
  TierSettings,
  TierSettingsInput,
  tierSettingsSchema,
  tiersSchema,
} from '@/lib/schema/tier.schema';

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

export async function getTierSettings(signal?: AbortSignal): Promise<TierSettings> {
  return tierSettingsSchema.parse(await api.get('tiers/settings', { signal }).json());
}

/**
 * PUT tiers/settings. Turning tiers on starts the placement sweep, and turning them off drops any pending sweep.
 * A mode change while tiers are on starts a mode sweep, and is refused with a 409 while any sweep runs.
 */
export async function updateTierSettings(input: TierSettingsInput): Promise<TierSettings> {
  return tierSettingsSchema.parse(await api.put('tiers/settings', { json: input }).json());
}

export async function getCustomerTier(userId: number, signal?: AbortSignal): Promise<CustomerTier> {
  return customerTierSchema.parse(await api.get(`customers/${userId}/tier`, { signal }).json());
}

/** POST customers/{id}/tier: sets the manual floor, which raises the customer now when it outranks their tier. */
export async function assignCustomerTier(userId: number, input: AssignCustomerTierInput): Promise<CustomerTier> {
  return customerTierSchema.parse(await api.post(`customers/${userId}/tier`, { json: input }).json());
}

/** DELETE customers/{id}/tier: clears the floor and moves the customer to the tier their points qualify for. */
export async function removeCustomerTier(userId: number): Promise<CustomerTier> {
  return customerTierSchema.parse(await api.delete(`customers/${userId}/tier`).json());
}
