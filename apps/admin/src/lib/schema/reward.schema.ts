import { z } from 'zod';

import { campaignStatusSchema, resolveCampaignDisplayStatus } from '@/lib/schema/campaign.schema';

/** Mirrors RewardRestController::define_item_schema(), the source of truth. */

/** Mirrors RewardType::lite_types(): the built-in slider, offered in every build. */
export const LITE_REWARD_TYPES = ['flexible_discount'] as const;

/** Mirrors YayLoyaltyPro::add_reward_types(): the catalog types Pro adds. */
export const PRO_REWARD_TYPES = ['fixed_coupon', 'percent_coupon', 'free_shipping', 'free_product'] as const;

/**
 * Every type the schema recognises, mirroring RewardType::all_known().
 * Parsing must accept Pro values in both builds, or a downgraded site's
 * rewards list throws instead of rendering.
 */
export const ALL_KNOWN_REWARD_TYPES = [...LITE_REWARD_TYPES, ...PRO_REWARD_TYPES] as const;

export const rewardTypeSchema = z.enum(ALL_KNOWN_REWARD_TYPES);
export type RewardType = z.infer<typeof rewardTypeSchema>;
/** Catalog rewards: every offerable type except the built-in slider, which is looked up by slug, not listed as a template. */
export type CatalogRewardType = (typeof PRO_REWARD_TYPES)[number];

/** Reuses the shared Status vocabulary and display-status resolver: rewards.status follows the same rules as campaigns.status (Enums\Status on the PHP side). */
export const rewardStatusSchema = campaignStatusSchema;
export const resolveRewardDisplayStatus = resolveCampaignDisplayStatus;

export const rewardSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** Built-in is RewardRepository::CART_DISCOUNT_SLUG. */
  slug: z.string(),
  type: rewardTypeSchema,
  status: rewardStatusSchema,
  sort_order: z.number().int(),
  /** Null for flexible_discount — the customer picks the amount; required for every other type. */
  cost_points: z.number().int().nullable(),
  /** Loose on purpose: the per-type whitelist lives in PHP and would drift if duplicated. */
  config: z.record(z.string(), z.unknown()),
  conditions: z.union([z.record(z.string(), z.unknown()), z.array(z.unknown())]),
  starts_at: z.string().nullable(),
  /** Exclusive: a reward is not live at exactly this instant. */
  ends_at: z.string().nullable(),
  /** The seeded flexible-discount row: cannot be archived, addressed by slug across sites. */
  is_builtin: z.boolean(),
  created_by: z.number().int().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
});

export type Reward = z.infer<typeof rewardSchema>;

/** App-side list params; reward.api.ts maps them to the wire. */
export const rewardListParamsSchema = z.object({
  page: z.number().int().optional(),
  perPage: z.number().int().optional(),
  type: rewardTypeSchema.optional(),
  status: rewardStatusSchema.optional(),
  search: z.string().optional(),
});

export type RewardListParams = z.infer<typeof rewardListParamsSchema>;
