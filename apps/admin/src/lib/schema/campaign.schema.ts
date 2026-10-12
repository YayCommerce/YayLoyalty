import { z } from 'zod';
import { __ } from '@wordpress/i18n';

import { dateToStored } from '@/lib/helpers/format.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { RoundingMode } from '@/lib/points-math/points-math';

/** Mirrors CampaignRestController::define_item_schema(), the source of truth. */

/** Mirrors CampaignTrigger::lite_triggers(), the source of truth. */
export const LITE_CAMPAIGN_TRIGGERS = ['purchase', 'signup', 'review'] as const;

/** The triggers the create form offers; the Lite build's POST accepts only these, while Pro adds more through the yayloyalty_campaign_triggers filter that CampaignTrigger::is_valid() reads. */
export type LiteCampaignTrigger = (typeof LITE_CAMPAIGN_TRIGGERS)[number];

/** Mirrors YayLoyaltyPro::ADDED_TRIGGERS, the source of truth. */
export const PRO_CAMPAIGN_TRIGGERS = ['birthday', 'first_order', 'order_count', 'spend_milestone'] as const;

/**
 * Mirrors CampaignTrigger::all_known(), the source of truth. Every trigger the API may hand back.
 * Parsing must accept Pro values in both builds, or a downgraded site's
 * campaigns list throws instead of rendering.
 */
export const ALL_KNOWN_CAMPAIGN_TRIGGERS = [
  ...LITE_CAMPAIGN_TRIGGERS,
  ...PRO_CAMPAIGN_TRIGGERS,
  'referral',
  'social',
  'renewal',
] as const;

/** Mirrors CampaignTrigger::all(), the source of truth. The triggers this build offers. For UI choices only, never parsing. */
export const CAMPAIGN_TRIGGERS = IS_PRO_BUILD
  ? ([...LITE_CAMPAIGN_TRIGGERS, ...PRO_CAMPAIGN_TRIGGERS] as const)
  : LITE_CAMPAIGN_TRIGGERS;

/** Mirrors Enums\Status::all(), the source of truth. The stored statuses. No scheduled/ended: liveness is derived. */
export const CAMPAIGN_STATUSES = ['active', 'paused', 'draft', 'archived'] as const;

/** Parses the full known vocabulary, in both builds. */
export const campaignTriggerSchema = z.enum(ALL_KNOWN_CAMPAIGN_TRIGGERS);
export const campaignStatusSchema = z.enum(CAMPAIGN_STATUSES);

export type CampaignTrigger = z.infer<typeof campaignTriggerSchema>;

/** A trigger this build can offer, as opposed to one it merely recognises. */
export type OfferableCampaignTrigger = (typeof CAMPAIGN_TRIGGERS)[number];
export type CampaignStatus = z.infer<typeof campaignStatusSchema>;

/** Mirrors Enums\Status::resolve_display(), the source of truth. CampaignStatus plus the two derived, never-stored labels. */
export const CAMPAIGN_DISPLAY_STATUSES = [...CAMPAIGN_STATUSES, 'scheduled', 'ended'] as const;

export type CampaignDisplayStatus = (typeof CAMPAIGN_DISPLAY_STATUSES)[number];

/**
 * Mirrors Enums\Status::resolve_display(), the source of truth. `at` is UTC in the stored shape;
 * pass the campaign's dates through unconverted.
 */
export function resolveCampaignDisplayStatus(
  status: CampaignStatus,
  startsAt: string | null,
  endsAt: string | null,
  at: string = dateToStored(),
): CampaignDisplayStatus {
  if (status !== 'active') {
    return status;
  }

  if (startsAt && startsAt > at) {
    return 'scheduled';
  }

  if (endsAt && endsAt <= at) {
    return 'ended';
  }

  return 'active';
}

export const campaignSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** Generated server-side and immutable after create. */
  slug: z.string(),
  trigger: campaignTriggerSchema,
  status: campaignStatusSchema,
  priority: z.number().int(),
  /** Loose on purpose: the per-trigger whitelist lives in PHP and would drift if duplicated. */
  config: z.record(z.string(), z.unknown()),
  conditions: z.union([z.record(z.string(), z.unknown()), z.array(z.unknown())]),
  starts_at: z.string().nullable(),
  /** Exclusive: a campaign is not live at exactly this instant. */
  ends_at: z.string().nullable(),
  /** Built-ins cannot be deleted and are addressed by slug across sites. */
  is_builtin: z.boolean(),
  created_by: z.number().int().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  /** Net points awarded, lifetime. Derived from the ledger at read time, never stored. */
  points_issued: z.number().int(),
  /** Whether DELETE would succeed: not built-in, and no ledger row names it. */
  is_deletable: z.boolean(),
});

export type Campaign = z.infer<typeof campaignSchema>;

/** The built-in (default) campaign for a trigger: what a non-built-in falls back to. undefined while the list loads. */
export function findBuiltinCampaign<T extends Campaign>(
  campaigns: T[] | undefined,
  trigger: CampaignTrigger,
): T | undefined {
  return campaigns?.find((candidate) => candidate.is_builtin && candidate.trigger === trigger);
}

/** App-side list params; campaign.api.ts maps them to the wire. */
export const campaignListParamsSchema = z.object({
  page: z.number().int().optional(),
  perPage: z.number().int().optional(),
  trigger: campaignTriggerSchema.optional(),
  status: campaignStatusSchema.optional(),
  search: z.string().optional(),
});

export type CampaignListParams = z.infer<typeof campaignListParamsSchema>;

// Write shapes

/**
 * Mirrors CampaignConditionType::schema(), the source of truth. It leaves out
 * UNAVAILABLE, and `tier` stays out until the PHP side offers it:
 * CampaignConditionSet rejects a save that carries one.
 */
export const CAMPAIGN_CONDITION_TYPES = [
  'user_role',
  'customer_ids',
  'currency',
  'payment_method',
  'country',
  'product_in_cart',
  'category_in_cart',
  'lifetime_points',
  'order_count',
  'lifetime_spend',
] as const;

export type CampaignConditionKind = (typeof CAMPAIGN_CONDITION_TYPES)[number];

/** Mirrors the operators CampaignConditionType::schema() recognises, the source of truth. */
type CampaignConditionOperator = 'in' | 'not_in' | 'gte' | 'lte' | 'gt' | 'lt' | 'eq' | 'between';

/** Mirrors the value shapes in CampaignConditionType::schema(), the source of truth. */
type CampaignConditionValueKind = 'string_list' | 'int_list' | 'currency_list' | 'country_list' | 'int' | 'money';

interface CampaignConditionSpec {
  /** Operators this type accepts. Not interchangeable across types. */
  operators: readonly CampaignConditionOperator[];
  value: CampaignConditionValueKind;
  /** Cap on list length — only `customer_ids` sets this (100). */
  max?: number;
  /** Sub-keys the value object must carry (`country`, `lifetime_spend`). */
  requireKeys?: readonly string[];
  /** Allowed values for `country`'s `source` sub-key. */
  sourceValues?: readonly string[];
}

/** Mirrors CampaignConditionType::SOURCE_*, the source of truth. The address a `country` condition reads. */
export const COUNTRY_SOURCE_VALUES = ['shipping', 'billing', 'any'] as const;

/**
 * Mirrors CampaignConditionType::schema(), the source of truth. Offerable types only.
 * UI metadata, not a validator: CampaignConditionSet enforces on save, and
 * a duplicate here would only drift from it.
 */
export const CAMPAIGN_CONDITION_SCHEMA: Record<CampaignConditionKind, CampaignConditionSpec> = {
  user_role: {
    operators: ['in', 'not_in'],
    value: 'string_list',
  },
  customer_ids: {
    operators: ['in', 'not_in'],
    value: 'int_list',
    max: 100,
  },
  currency: {
    operators: ['in', 'not_in'],
    value: 'currency_list',
  },
  payment_method: {
    operators: ['in', 'not_in'],
    value: 'string_list',
  },
  country: {
    operators: ['in', 'not_in'],
    value: 'country_list',
    requireKeys: ['source'],
    sourceValues: COUNTRY_SOURCE_VALUES,
  },
  product_in_cart: {
    operators: ['in', 'not_in'],
    value: 'int_list',
  },
  category_in_cart: {
    operators: ['in', 'not_in'],
    value: 'int_list',
  },
  lifetime_points: {
    operators: ['gte', 'lte', 'gt', 'lt', 'eq', 'between'],
    value: 'int',
  },
  order_count: {
    operators: ['gte', 'lte', 'gt', 'lt', 'eq', 'between'],
    value: 'int',
  },
  lifetime_spend: {
    operators: ['gte', 'lte', 'gt', 'lt', 'eq', 'between'],
    value: 'money',
    requireKeys: ['currency'],
  },
};

/**
 * One entry in a campaign's `conditions`. `value` stays unknown because its
 * shape varies per type (CAMPAIGN_CONDITION_SCHEMA). Plain z.object strips
 * extra keys on submit, which is fine here: a condition has a fixed shape
 * per type, unlike `config`.
 */
export const campaignConditionSchema = z.object({
  type: z.enum(CAMPAIGN_CONDITION_TYPES),
  operator: z.string(),
  value: z.unknown(),
});

export type CampaignCondition = z.infer<typeof campaignConditionSchema>;

/**
 * Mirrors CampaignRestController::update_item()'s mutable fields, the source of truth.
 * The write body for PUT campaigns/{id}. No slug or trigger: both are immutable after create. No priority:
 * only the campaigns table's drag writes it, through campaigns/reorder.
 */
export const campaignUpdateInputSchema = z
  .object({
    name: z.string().trim().min(1, __('A campaign needs a name.', 'yayloyalty')),
    status: campaignStatusSchema,
    /** Loose on purpose; see campaignSchema.config. */
    config: z.record(z.string(), z.unknown()),
    conditions: z.array(campaignConditionSchema),
    starts_at: z.string().nullable(),
    /** Exclusive; see campaignSchema.ends_at. */
    ends_at: z.string().nullable(),
  })
  .refine((data) => isCampaignScheduleInOrder(data.starts_at, data.ends_at), {
    message: __('The campaign must end after it starts.', 'yayloyalty'),
    path: ['ends_at'],
  });

export type CampaignUpdateInput = z.infer<typeof campaignUpdateInputSchema>;

/**
 * Mirrors AbstractRestController::dates_in_order(), the source of truth. An open side passes, two
 * set dates must run forward (ends_at is exclusive, so equal is an empty run).
 * String comparison is correct: both are fixed-width UTC YYYY-MM-DD HH:mm:ss.
 */
export function isCampaignScheduleInOrder(startsAt: string | null, endsAt: string | null): boolean {
  return !startsAt || !endsAt || startsAt < endsAt;
}

/**
 * Mirrors CampaignRestController::create_item(), the source of truth. The write body for POST campaigns. `trigger` is set only here: it is immutable after create.
 * No `slug`: the server generates it from `name`.
 */
export type CampaignCreateInput = CampaignUpdateInput & { trigger: LiteCampaignTrigger };

/** Mirrors CampaignRestController::delete_item(), the source of truth. The response of DELETE campaigns/{id}. */
export const campaignDeleteResponseSchema = z.object({
  deleted: z.boolean(),
  id: z.number().int(),
});

export type CampaignDeleteResponse = z.infer<typeof campaignDeleteResponseSchema>;

// Per-trigger config fields

/** `limit`: a whole number of at least 1, or null for no limit. */
type CampaignConfigFieldType = 'bool' | 'string' | 'int' | 'number' | 'enum' | 'int_list' | 'limit';

interface CampaignConfigFieldSpec {
  type: CampaignConfigFieldType;
  /** Lower bound; int and number only. */
  min?: number;
  /** Strict lower bound: the value must be above it; number only. */
  exclusiveMin?: number;
  /** Fixed value set; enum only. */
  values?: readonly string[];
}

/**
 * Mirrors Campaign::config_schema(), the source of truth. A per-trigger config
 * field map for the three Lite triggers. UI metadata, not validation: `config` stays z.record
 * so this can never become a second whitelist. Pro triggers have no Lite
 * schema and so no entry here.
 */
export const CAMPAIGN_CONFIG_FIELDS: Record<
  'purchase' | 'signup' | 'review',
  Record<string, CampaignConfigFieldSpec>
> = {
  purchase: {
    points_per_unit: { type: 'number', exclusiveMin: 0 },
    base: { type: 'enum', values: ['after_discounts', 'before_discounts'] },
    // Never read by the calculator; kept so a downgrade/upgrade round trip does not lose it.
    include_shipping: { type: 'bool' },
    include_tax: { type: 'bool' },
    rounding: { type: 'enum', values: ['down', 'nearest', 'up'] },
    min_subtotal: { type: 'number', min: 0 },
    exclude_coupon_orders: { type: 'bool' },
    excluded_product_ids: { type: 'int_list' },
    excluded_category_ids: { type: 'int_list' },
    max_points_per_order: { type: 'limit' },
  },
  signup: {
    points: { type: 'int', min: 0 },
  },
  review: {
    points: { type: 'int', min: 0 },
    require_verified: { type: 'bool' },
    one_per_product: { type: 'bool' },
    // Absent from default_config(); the form defaults it to 0.
    media_bonus_points: { type: 'int', min: 0 },
  },
};

/**
 * Mirrors Campaign::default_config(), the source of truth. Used only when creating: the chooser's
 * templates start from these, and switching the trigger in the builder
 * re-seeds config to the new trigger's defaults, since a stale key from the
 * old trigger would fail Campaign::config_is_valid() on save.
 */
export const DEFAULT_CAMPAIGN_CONFIG: Record<LiteCampaignTrigger, Record<string, unknown>> = {
  purchase: {
    points_per_unit: 1,
    base: 'after_discounts',
    include_shipping: false,
    include_tax: false,
    rounding: 'down',
    min_subtotal: 0,
    exclude_coupon_orders: false,
    excluded_product_ids: [],
    excluded_category_ids: [],
  },
  signup: { points: 100 },
  review: { points: 50, require_verified: true, one_per_product: true },
};

/**
 * Mirrors Campaign::config_schema()['purchase'], the source of truth. Config is untyped on the wire,
 * so every key is optional: a stored row may predate one.
 */
export interface PurchaseConfig {
  points_per_unit?: number;
  base?: 'after_discounts' | 'before_discounts';
  include_shipping?: boolean;
  include_tax?: boolean;
  rounding?: RoundingMode;
  min_subtotal?: number;
  exclude_coupon_orders?: boolean;
  excluded_product_ids?: number[];
  excluded_category_ids?: number[];
  max_points_per_order?: number | null;
}

/** Mirrors Campaign::config_schema()['signup'], the source of truth. */
export interface SignupConfig {
  points?: number;
}

/** Mirrors Campaign::config_schema()['review'], the source of truth. */
export interface ReviewConfig {
  points?: number;
  require_verified?: boolean;
  one_per_product?: boolean;
  media_bonus_points?: number;
}

/** The form's side of CampaignRestController::lacks_purchase_rate(): a purchase campaign with no numeric rate could never earn, so saving it is refused. */
export function lacksPurchaseRate(config: Record<string, unknown>): boolean {
  return typeof config.points_per_unit !== 'number';
}
