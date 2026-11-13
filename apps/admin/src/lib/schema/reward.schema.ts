import { z } from 'zod';
import { __ } from '@wordpress/i18n';

import {
  CampaignConditionType,
  deleteResponseSchema,
  resolveDisplayStatus,
  Status,
  statusSchema,
} from '@/lib/schema/campaign.schema';
import { conditionListSchema } from '@/lib/schema/condition-match.schema';

/** Mirrors RewardRestController::define_item_schema(), the source of truth. */

/** Mirrors RewardType::lite_types(), the source of truth. The built-in flexible discount, offered in every build. */
export const LITE_REWARD_TYPES = ['flexible_discount'] as const;

/** Mirrors YayLoyaltyPro::add_reward_types(), the source of truth. The catalog types Pro adds. */
export const PRO_REWARD_TYPES = ['fixed_coupon', 'percent_coupon', 'free_shipping', 'free_product'] as const;

/**
 * Mirrors RewardType::all_known(), the source of truth. Every type the schema recognises.
 * Parsing must accept Pro values in both builds, or a downgraded site's
 * rewards list throws instead of rendering.
 */
export const ALL_KNOWN_REWARD_TYPES = [...LITE_REWARD_TYPES, ...PRO_REWARD_TYPES] as const;

export const rewardTypeSchema = z.enum(ALL_KNOWN_REWARD_TYPES);
export type RewardType = z.infer<typeof rewardTypeSchema>;
/** Catalog rewards: every offerable type except the built-in flexible discount, which is looked up by slug, not listed as a template. */
export type CatalogRewardType = (typeof PRO_REWARD_TYPES)[number];

export function isCatalogRewardType(type: string): type is CatalogRewardType {
  return (PRO_REWARD_TYPES as readonly string[]).includes(type);
}

/** Reuses the shared Status vocabulary and display-status resolver: rewards.status follows the same rules as campaigns.status (Enums\Status on the PHP side). */
export const rewardStatusSchema = statusSchema;
export type RewardStatus = Status;
export const resolveRewardDisplayStatus = resolveDisplayStatus;

/** Claim figures from RedemptionRepository::summary_by_rewards(); zero and null for a reward with no claims. */
export const rewardRedemptionSummarySchema = z.object({
  /** Every claim row in any status — what decides `is_deletable`. */
  claimed: z.number().int(),
  first_claimed_at: z.string().nullable(),
});

export type RewardRedemptionSummary = z.infer<typeof rewardRedemptionSummarySchema>;

export const rewardSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** Built-in is RewardRepository::FLEXIBLE_DISCOUNT_SLUG. */
  slug: z.string(),
  /** Set once when the reward is created (RewardRepository::insert()); never changes. */
  coupon_code: z.string(),
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
  /** RewardRestController::is_deletable(): not built-in and never claimed. Read it; never re-derive it. */
  is_deletable: z.boolean(),
  redemption_summary: rewardRedemptionSummarySchema,
});

export type Reward = z.infer<typeof rewardSchema>;

/** Same `{ deleted, id }` body the campaigns DELETE answers. */
export const rewardDeleteResponseSchema = deleteResponseSchema;

/** App-side list params; reward.api.ts maps them to the wire. */
export const rewardListParamsSchema = z.object({
  page: z.number().int().optional(),
  perPage: z.number().int().optional(),
  type: rewardTypeSchema.optional(),
  status: rewardStatusSchema.optional(),
  search: z.string().optional(),
});

export type RewardListParams = z.infer<typeof rewardListParamsSchema>;

/* ─── Write ─────────────────────────────────────────────────────────── */

/**
 * Mirrors RewardConditionType::all(), the source of truth. What the reward editor offers; each shares
 * its spec with the campaign type of the same name (CAMPAIGN_CONDITION_SCHEMA).
 */
export const REWARD_CONDITION_TYPES = [
  'user_role',
  'customer_ids',
  'lifetime_points',
] as const satisfies readonly CampaignConditionType[];

/** One availability rule; loose here because RewardConditionSet validates it on save. */
export const rewardConditionSchema = z.object({
  type: z.string(),
  operator: z.string(),
  value: z.unknown(),
});

export type RewardCondition = z.infer<typeof rewardConditionSchema>;

/** The editable fields, unrefined so the editor form can pick from them. */
export const rewardFieldsSchema = z.object({
  name: z.string().trim().min(1, __('A reward needs a name.', 'yayloyalty')),
  status: rewardStatusSchema,
  cost_points: z.number().int().nullable(),
  config: z.record(z.string(), z.unknown()),
  /** { match, conditions }; the server also accepts a flat list. */
  conditions: conditionListSchema(rewardConditionSchema),
  starts_at: z.string().nullable(),
  /** Exclusive; see rewardSchema.ends_at. */
  ends_at: z.string().nullable(),
});

/** Mirrors RewardRestController::update_item()'s accepted fields, the source of truth. The write body for PUT rewards/{id}. Every key is optional on the wire. */
export type RewardUpdateInput = z.infer<typeof rewardFieldsSchema>;

/** Write body for POST rewards: update's fields plus the type, which is immutable afterwards. No slug: the server derives it from `name`. */
export type RewardCreateInput = RewardUpdateInput & { type: CatalogRewardType };

const moneySchema = z.number().min(0).nullable();
/** A percent of the order; null is no cap. */
const percentCapSchema = z.number().min(0).nullable();
/** A cap: null is no cap, and a set cap is above 0 (the PHP spec's `exclusive_min: 0`). */
const moneyCapSchema = z.number().positive().nullable();
/** Mirrors ConfigSpec's `limit`: null is no cap, and a set cap is a whole number of points from 1. */
const pointsCapSchema = z.number().int().min(1).nullable();

/** Mirrors Reward::REDEMPTION_MODE_*, the source of truth. How a shopper spends points on the flexible discount. */
export const REDEMPTION_MODES = ['auto_max', 'free_input'] as const;

export type RedemptionMode = (typeof REDEMPTION_MODES)[number];

/** Mirrors Reward::config_conflict()'s message, the source of truth. */
export const MIN_BELOW_INCREMENT_MESSAGE = () =>
  __('Minimum points must be at least the redemption increment.', 'yayloyalty');

/**
 * Mirrors Reward::config_schema() (flexible_discount) and
 * YayLoyaltyPro::add_reward_config_schema() (the four Pro types), the source of truth.
 * Its bounds give form feedback before the request; the PHP specs decide what is stored.
 */
export const REWARD_CONFIG_SCHEMAS = {
  flexible_discount: z
    .object({
      currency: z.string(),
      points_per_currency_unit: z.number().positive(),
      min_points: z.number().int().min(0),
      max_points_per_order: pointsCapSchema,
      max_percent_of_discountable: percentCapSchema,
      increment: z.number().int().min(1),
      show_on: z.array(z.enum(['cart', 'checkout'])),
      refund_policy: z.enum(['proportional', 'full', 'none']),
      // A row without the key reads as auto max, as Clamp::is_auto_max() does.
      redemption_mode: z.enum(REDEMPTION_MODES).default('auto_max'),
      max_total: moneyCapSchema,
    })
    // Mirrors Reward::config_conflict().
    .refine((config) => config.min_points >= config.increment, {
      message: MIN_BELOW_INCREMENT_MESSAGE(),
      path: ['min_points'],
    }),
  fixed_coupon: z.object({
    currency: z.string(),
    amount: z.number().min(0.01, __('The discount must be more than zero.', 'yayloyalty')),
    min_order: moneySchema,
    exclude_sale_items: z.boolean(),
  }),
  percent_coupon: z.object({
    percent: z
      .number()
      .min(1, __('The discount must be between 1% and 100%.', 'yayloyalty'))
      .max(100, __('The discount must be between 1% and 100%.', 'yayloyalty')),
    max_discount: moneyCapSchema,
    currency: z.string(),
    min_order: moneySchema,
    exclude_sale_items: z.boolean(),
  }),
  free_shipping: z.object({
    min_order: moneySchema,
    currency: z.string(),
  }),
  free_product: z.object({
    product_id: z
      .number({ invalid_type_error: __('Choose a product.', 'yayloyalty') })
      .int()
      .min(1, __('Choose a product.', 'yayloyalty')),
    variation_id: z.number().int().min(1).nullable(),
    qty: z.number().int().min(1),
  }),
} satisfies Record<RewardType, z.ZodType>;

export type RewardConfigOf<T extends RewardType> = z.infer<(typeof REWARD_CONFIG_SCHEMAS)[T]>;
