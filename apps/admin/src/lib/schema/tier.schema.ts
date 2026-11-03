import { z } from 'zod';

/** Mirrors Models\Tier and TiersRestController, the source of truth. */
export const tierSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** Points earned that qualify for the tier. */
  threshold: z.number().int(),
  /** 1 for the lowest live tier, in threshold order. */
  tier_rank: z.number().int(),
  /** A decimal string such as "1.25". */
  multiplier: z.string(),
  entry_bonus: z.number().int(),
  /** `#RRGGBB`. */
  color: z.string(),
  member_count: z.number().int(),
  created_at: z.string(),
  updated_at: z.string(),
});

export type Tier = z.infer<typeof tierSchema>;

/** GET /tiers: live tiers, lowest threshold first. Not paginated. */
export const tiersSchema = z.array(tierSchema);

export type TierInput = Pick<Tier, 'name' | 'threshold' | 'multiplier' | 'entry_bonus' | 'color'>;

/** Mirrors the Tier constants, for the form's inline messages; the server stays the judge. */
export const TIER_LIMITS = {
  nameMax: 100,
  thresholdMax: 1_000_000_000,
  entryBonusMax: 1_000_000,
  multiplierMin: 1,
  multiplierMax: 10,
  multiplierStep: 0.05,
  maxLive: 10,
} as const;

/** The 409 a delete answers while rewards still name the tier in their conditions. */
export const tierInUseErrorSchema = z.object({
  code: z.literal('yayloyalty_tier_in_use'),
  message: z.string(),
  data: z.object({
    rewards: z.array(z.object({ id: z.number().int(), name: z.string(), status: z.string() })),
  }),
});

export type TierInUseReward = z.infer<typeof tierInUseErrorSchema>['data']['rewards'][number];

/** Why a delete was refused, in the server's words, and the rewards that block it. */
export type TierInUse = { message: string; rewards: TierInUseReward[] };
