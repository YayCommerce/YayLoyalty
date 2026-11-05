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

/** Mirrors TierMode::ROLLING_MONTHS, the source of truth. */
export const TIERS_ROLLING_MONTHS = [3, 6, 9, 12, 18, 24] as const;

export const tiersRollingMonthsSchema = z.union([
  z.literal(3),
  z.literal(6),
  z.literal(9),
  z.literal(12),
  z.literal(18),
  z.literal(24),
]);

/** Mirrors TiersRestController::get_settings(), the source of truth. */
export const tierSettingsSchema = z.object({
  tiers_enabled: z.boolean(),
  tiers_mode: z.enum(['lifetime', 'rolling']),
  tiers_rolling_months: tiersRollingMonthsSchema,
  /** Customer rows a placement would cover. */
  customer_count: z.number().int(),
  /** The pending sweep (placement, re-evaluation or a mode switch), or null when none is running. */
  sweep: z
    .object({
      mode: z.enum(['placement', 'reevaluate', 'to_rolling', 'to_lifetime']),
      done: z.number().int(),
      total: z.number().int(),
    })
    .nullable(),
});

export type TierSettings = z.infer<typeof tierSettingsSchema>;

/** PUT /tiers/settings: at least one key. */
export type TierSettingsInput = Partial<Pick<TierSettings, 'tiers_enabled' | 'tiers_mode' | 'tiers_rolling_months'>>;

/** Mirrors CustomerTierRestController::respond(), the source of truth. */
export const customerTierSchema = z.object({
  /** Null when the customer has no tier, or their stored tier was deleted. */
  tier: z
    .object({ id: z.number().int(), name: z.string(), color: z.string(), tier_rank: z.number().int() })
    .nullable(),
  /** The points the tier was last judged on; in Rolling, the window total read now. */
  progress_value: z.number().int(),
  /** The lowest live tier above `tier`, or null at the top tier. */
  next: z.object({ id: z.number().int(), name: z.string(), threshold: z.number().int() }).nullable(),
  achieved_at: z.string().nullable(),
  /** The manual floor; `until` is null for no end. */
  manual: z
    .object({ tier_id: z.number().int(), name: z.string(), color: z.string(), until: z.string().nullable() })
    .nullable(),
  mode: z.enum(['lifetime', 'rolling']),
  /** The rolling window's length; null in Lifetime. */
  window_months: z.number().int().nullable(),
  /** The running hold; both null when there is none or it has ended. */
  held_from: z.string().nullable(),
  held_until: z.string().nullable(),
});

export type CustomerTier = z.infer<typeof customerTierSchema>;

export type AssignCustomerTierInput = { tier_id: number; days: number | null };
