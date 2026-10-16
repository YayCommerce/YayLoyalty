import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import {
  campaignDeleteResponseSchema,
  CampaignStatus,
  campaignStatusSchema,
  resolveCampaignDisplayStatus,
} from '@/lib/schema/campaign.schema';

/** Mirrors RewardRestController::define_item_schema(), the source of truth. */

/** Mirrors RewardType::lite_types(), the source of truth. The built-in slider, offered in every build. */
export const LITE_REWARD_TYPES = ['flexible_discount'] as const;

/** Mirrors YayLoyaltyPro::add_reward_types(), the source of truth. The catalog types Pro adds. */
export const PRO_REWARD_TYPES = ['fixed_coupon', 'percent_coupon', 'free_shipping', 'free_product'] as const;

/**
 * Mirrors RewardType::all_known(), the source of truth. Every type the schema recognises.
 * Parsing must accept Pro values in both builds, or a downgraded site's
 * rewards list throws instead of rendering.
 */
export const ALL_KNOWN_REWARD_TYPES = [...LITE_REWARD_TYPES, ...PRO_REWARD_TYPES] as const;

export const rewardTypeSchema = v.picklist(ALL_KNOWN_REWARD_TYPES);
export type RewardType = v.InferOutput<typeof rewardTypeSchema>;
/** Catalog rewards: every offerable type except the built-in slider, which is looked up by slug, not listed as a template. */
export type CatalogRewardType = (typeof PRO_REWARD_TYPES)[number];

export function isCatalogRewardType(type: string): type is CatalogRewardType {
  return (PRO_REWARD_TYPES as readonly string[]).includes(type);
}

/** Reuses the shared Status vocabulary and display-status resolver: rewards.status follows the same rules as campaigns.status (Enums\Status on the PHP side). */
export const rewardStatusSchema = campaignStatusSchema;
export type RewardStatus = CampaignStatus;
export const resolveRewardDisplayStatus = resolveCampaignDisplayStatus;

/** Claim figures from RedemptionRepository::summary_by_rewards(); zeros and nulls until the redemptions table exists. */
export const rewardRedemptionSummarySchema = v.object({
  /** Every claim row in any status — what decides `is_deletable`. */
  claimed: v.pipe(v.number(), v.integer()),
  /** Issued coupons not yet expired. */
  outstanding: v.pipe(v.number(), v.integer()),
  outstanding_customers: v.pipe(v.number(), v.integer()),
  outstanding_points: v.pipe(v.number(), v.integer()),
  first_claimed_at: v.nullable(v.string()),
  last_expires_at: v.nullable(v.string()),
});

export type RewardRedemptionSummary = v.InferOutput<typeof rewardRedemptionSummarySchema>;

export const rewardSchema = v.object({
  id: v.pipe(v.number(), v.integer()),
  name: v.string(),
  /** Built-in is RewardRepository::CART_DISCOUNT_SLUG. */
  slug: v.string(),
  type: rewardTypeSchema,
  status: rewardStatusSchema,
  sort_order: v.pipe(v.number(), v.integer()),
  /** Null for flexible_discount — the customer picks the amount; required for every other type. */
  cost_points: v.nullable(v.pipe(v.number(), v.integer())),
  /** Loose on purpose: the per-type whitelist lives in PHP and would drift if duplicated. */
  config: v.record(v.string(), v.unknown()),
  conditions: v.union([v.record(v.string(), v.unknown()), v.array(v.unknown())]),
  starts_at: v.nullable(v.string()),
  /** Exclusive: a reward is not live at exactly this instant. */
  ends_at: v.nullable(v.string()),
  /** The seeded flexible-discount row: cannot be archived, addressed by slug across sites. */
  is_builtin: v.boolean(),
  created_by: v.nullable(v.pipe(v.number(), v.integer())),
  created_at: v.string(),
  updated_at: v.string(),
  /** RewardRestController::is_deletable(): not built-in and never claimed. Read it; never re-derive it. */
  is_deletable: v.boolean(),
  redemption_summary: rewardRedemptionSummarySchema,
});

export type Reward = v.InferOutput<typeof rewardSchema>;

/** Same `{ deleted, id }` body the campaigns DELETE answers. */
export const rewardDeleteResponseSchema = campaignDeleteResponseSchema;

/** App-side list params; reward.api.ts maps them to the wire. */
export const rewardListParamsSchema = v.object({
  page: v.optional(v.pipe(v.number(), v.integer())),
  perPage: v.optional(v.pipe(v.number(), v.integer())),
  type: v.optional(rewardTypeSchema),
  status: v.optional(rewardStatusSchema),
  search: v.optional(v.string()),
});

export type RewardListParams = v.InferOutput<typeof rewardListParamsSchema>;

/* ─── Write ─────────────────────────────────────────────────────────── */

/** One availability rule; loose here because RewardConditionSet validates it on save. */
export const rewardConditionSchema = v.object({
  type: v.string(),
  operator: v.string(),
  value: v.unknown(),
});

export type RewardCondition = v.InferOutput<typeof rewardConditionSchema>;

/**
 * The stored conditions as a list, defensively: the wire type is a loose
 * union, so an object (empty legacy envelope) or a malformed entry degrades
 * to fewer rules rather than a crash.
 */
export function normalizeRewardConditions(conditions: Reward['conditions']): RewardCondition[] {
  if (!Array.isArray(conditions)) {
    return [];
  }

  return conditions.filter(
    (entry): entry is RewardCondition =>
      !!entry && typeof entry === 'object' && typeof (entry as { type?: unknown }).type === 'string',
  );
}

/** The editable fields, unrefined so the editor form can pick from them. */
export const rewardFieldsSchema = v.object({
  name: v.pipe(v.string(), v.trim(), v.minLength(1, __('A reward needs a name.', 'yayloyalty'))),
  status: rewardStatusSchema,
  cost_points: v.nullable(v.pipe(v.number(), v.integer())),
  config: v.record(v.string(), v.unknown()),
  conditions: v.array(rewardConditionSchema),
  starts_at: v.nullable(v.string()),
  /** Exclusive; see rewardSchema.ends_at. */
  ends_at: v.nullable(v.string()),
});

/** Mirrors RewardRestController::update_item()'s accepted fields, the source of truth. The write body for PUT rewards/{id}. Every key is optional on the wire. */
export type RewardUpdateInput = v.InferOutput<typeof rewardFieldsSchema>;

/** Write body for POST rewards: update's fields plus the type, which is immutable afterwards. No slug: the server derives it from `name`. */
export type RewardCreateInput = RewardUpdateInput & { type: CatalogRewardType };

const moneySchema = v.nullable(v.pipe(v.number(), v.minValue(0)));
/** A cap: null is no cap, and a set cap is above 0 (the PHP spec's `exclusive_min: 0`). */
const moneyCapSchema = v.nullable(v.pipe(v.number(), v.gtValue(0)));
const couponExpiryDaysSchema = v.nullable(v.pipe(v.number(), v.integer(), v.minValue(1)));

/**
 * Mirrors Reward::config_schema() (flexible_discount) and
 * YayLoyaltyPro::add_reward_config_schema() (the four Pro types), the source of truth.
 * Its bounds give form feedback before the request; the PHP specs decide what is stored.
 */
export const REWARD_CONFIG_SCHEMAS = {
  flexible_discount: v.object({
    currency: v.string(),
    points_per_currency_unit: v.pipe(v.number(), v.gtValue(0)),
    min_points: v.pipe(v.number(), v.integer(), v.minValue(0)),
    max_points_per_order: moneyCapSchema,
    max_percent_of_subtotal: moneySchema,
    increment: v.pipe(v.number(), v.integer(), v.minValue(1)),
    show_on: v.array(v.picklist(['cart', 'checkout'])),
    refund_policy: v.picklist(['proportional', 'full', 'none']),
    max_total: moneyCapSchema,
  }),
  fixed_coupon: v.object({
    currency: v.string(),
    amount: v.pipe(v.number(), v.minValue(0.01, __('The discount must be more than zero.', 'yayloyalty'))),
    min_order: moneySchema,
    coupon_expiry_days: couponExpiryDaysSchema,
    exclude_sale_items: v.boolean(),
  }),
  percent_coupon: v.object({
    percent: v.pipe(
      v.number(),
      v.minValue(1, __('The discount must be between 1% and 100%.', 'yayloyalty')),
      v.maxValue(100, __('The discount must be between 1% and 100%.', 'yayloyalty')),
    ),
    max_discount: moneyCapSchema,
    currency: v.string(),
    min_order: moneySchema,
    coupon_expiry_days: couponExpiryDaysSchema,
    exclude_sale_items: v.boolean(),
  }),
  free_shipping: v.object({
    min_order: moneySchema,
    currency: v.string(),
    coupon_expiry_days: couponExpiryDaysSchema,
  }),
  free_product: v.object({
    product_id: v.pipe(
      v.number(__('Choose a product.', 'yayloyalty')),
      v.integer(),
      v.minValue(1, __('Choose a product.', 'yayloyalty')),
    ),
    variation_id: v.nullable(v.pipe(v.number(), v.integer(), v.minValue(1))),
    qty: v.pipe(v.number(), v.integer(), v.minValue(1)),
    coupon_expiry_days: couponExpiryDaysSchema,
  }),
} satisfies Record<RewardType, v.GenericSchema>;

export type RewardConfigOf<T extends RewardType> = v.InferOutput<(typeof REWARD_CONFIG_SCHEMAS)[T]>;
