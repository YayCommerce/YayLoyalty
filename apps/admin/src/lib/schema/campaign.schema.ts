import { z } from 'zod';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';

/**
 * Mirrors CampaignRestController::get_item_schema().
 *
 * THAT PHP METHOD IS THE SOURCE OF TRUTH.
 */

/**
 * Triggers Lite itself ships. Mirrors CampaignTrigger::lite_triggers().
 */
export const LITE_CAMPAIGN_TRIGGERS = ['purchase', 'signup', 'review'] as const;

/**
 * Triggers Pro adds. Mirrors YayLoyaltyPro::ADDED_TRIGGERS.
 */
export const PRO_CAMPAIGN_TRIGGERS = ['birthday', 'first_order', 'order_count', 'spend_milestone'] as const;

/**
 * Every trigger the API may hand back. Mirrors CampaignTrigger::all_known().
 *
 * THE SPA HALF OF DOWNGRADE SAFETY, and the easiest thing here to get wrong.
 * Parsing must accept Pro values in BOTH builds: a site that drops from Pro to
 * Lite still holds campaigns whose trigger is `birthday`, and a Lite bundle
 * that parsed against the offerable list alone would throw on the campaigns
 * list rather than render it. The PHP half (all_known() staying unfiltered) is
 * not enough on its own — zod would reject what PHP happily returned.
 *
 * `referral`, `social` and `renewal` are recognised for the same reason even
 * though no build offers them yet.
 */
export const ALL_KNOWN_CAMPAIGN_TRIGGERS = [
  ...LITE_CAMPAIGN_TRIGGERS,
  ...PRO_CAMPAIGN_TRIGGERS,
  'referral',
  'social',
  'renewal',
] as const;

/**
 * Triggers this build can offer. Mirrors CampaignTrigger::all().
 *
 * Build-time, from IS_PRO_BUILD — never a runtime flag. Used for the UI's
 * choices only; never for parsing.
 */
export const CAMPAIGN_TRIGGERS = IS_PRO_BUILD
  ? ([...LITE_CAMPAIGN_TRIGGERS, ...PRO_CAMPAIGN_TRIGGERS] as const)
  : LITE_CAMPAIGN_TRIGGERS;

/**
 * Stored statuses. Mirrors Enums\Status::all().
 *
 * Merchant intent only. There is deliberately no 'scheduled' or 'ended' here —
 * whether a campaign is live is derived from status plus the date window,
 * evaluated against the event's timestamp rather than now().
 */
export const CAMPAIGN_STATUSES = ['active', 'paused', 'draft', 'archived'] as const;

/** Parses what the server sends — the full known vocabulary, in both builds. */
export const campaignTriggerSchema = z.enum(ALL_KNOWN_CAMPAIGN_TRIGGERS);
export const campaignStatusSchema = z.enum(CAMPAIGN_STATUSES);

export type CampaignTrigger = z.infer<typeof campaignTriggerSchema>;

/** A trigger this build can offer, as opposed to one it merely recognises. */
export type OfferableCampaignTrigger = (typeof CAMPAIGN_TRIGGERS)[number];
export type CampaignStatus = z.infer<typeof campaignStatusSchema>;

/**
 * `CampaignStatus` plus the two labels that are never stored: `scheduled`
 * and `ended`. Mirrors `Enums\Status::resolve_display()` — display-only,
 * derived from `status` + the date window, never a value `status` itself
 * takes.
 */
export const CAMPAIGN_DISPLAY_STATUSES = [...CAMPAIGN_STATUSES, 'scheduled', 'ended'] as const;

export type CampaignDisplayStatus = (typeof CAMPAIGN_DISPLAY_STATUSES)[number];

/**
 * Client-side mirror of `Enums\Status::resolve_display()`. `at` defaults to
 * now, in the same UTC `YYYY-MM-DD HH:mm:ss` shape every stored datetime
 * uses — pass the campaign's own dates through unmodified, never converted
 * to the browser's timezone (see code-standards.md §4).
 */
export function resolveCampaignDisplayStatus(
  status: CampaignStatus,
  startsAt: string | null,
  endsAt: string | null,
  at: string = new Date().toISOString().slice(0, 19).replace('T', ' '),
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
  /**
   * Shape depends on `trigger`; the server whitelists keys per trigger and
   * stamps schema_version. Kept loose here on purpose — validating the union
   * client-side would duplicate the PHP whitelist and drift from it.
   */
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
});

export type Campaign = z.infer<typeof campaignSchema>;

/** App-side (camelCase) list params — `campaign.api.ts` maps them to the wire's `per_page`. */
export const campaignListParamsSchema = z.object({
  page: z.number().int().optional(),
  perPage: z.number().int().optional(),
  trigger: campaignTriggerSchema.optional(),
  status: campaignStatusSchema.optional(),
  search: z.string().optional(),
});

export type CampaignListParams = z.infer<typeof campaignListParamsSchema>;

/* ─── Write: campaignUpdateInputSchema ──────────────────────────────── */

/**
 * `CampaignConditionType::all()`, offerable types only — mirrors
 * `CampaignConditionType::schema()` minus `UNAVAILABLE`.
 *
 * `tier` is deliberately excluded: the tier system does not exist yet, and
 * `CampaignConditionSet::is_valid()` (called from `conditions_are_valid()`)
 * would reject a save that carried one. Do not add it back until the PHP
 * side offers it.
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

/** Operators `CampaignConditionType::schema()` recognises, across every type. */
type CampaignConditionOperator = 'in' | 'not_in' | 'gte' | 'lte' | 'gt' | 'lt' | 'eq' | 'between';

/** The `value` shapes a condition type may require — see `CampaignConditionType::schema()`'s docblock. */
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

/**
 * Client mirror of `CampaignConditionType::schema()`, offerable types only.
 * The form layer (phase-05) reads this to know which operators and value
 * shape to render per condition type — it is UI metadata, not a validator:
 * `CampaignConditionSet` on the server is the actual enforcement point, and
 * duplicating its normalisation rules here would only drift from them.
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
    sourceValues: ['shipping', 'billing', 'any'],
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
 * One entry in a campaign's `conditions` list. `value`'s real shape depends
 * on `type` — see `CAMPAIGN_CONDITION_SCHEMA` — and per-type refinement is a
 * form-layer concern (phase-05), so it stays `unknown` here the same way
 * `campaignSchema.config` does.
 *
 * Plain `z.object`, unlike `config`'s `z.record` — zod silently strips any
 * extra key on a condition entry at submit time. Intentional/acceptable for
 * now: a condition has a known fixed shape (`type`/`operator`/`value`) per
 * type, unlike `config`, which genuinely varies and must round-trip whatever
 * keys the server sent. Not an oversight.
 */
export const campaignConditionSchema = z.object({
  type: z.enum(CAMPAIGN_CONDITION_TYPES),
  operator: z.string(),
  value: z.unknown(),
});

export type CampaignCondition = z.infer<typeof campaignConditionSchema>;

/**
 * Write body for `PUT campaigns/{id}` — mirrors `update_item()`'s mutable
 * fields exactly.
 *
 * No `slug`, no `trigger`: `update_item()` never calls a trigger setter and
 * `unset()`s both before the row is written (line ~224). Sending either is
 * harmless — the server strips them regardless — but omitting them from the
 * type documents that they cannot actually change post-create.
 */
export const campaignUpdateInputSchema = z
  .object({
    name: z.string().trim().min(1),
    status: campaignStatusSchema,
    priority: z.number().int(),
    /** Loose on purpose — see `campaignSchema.config`'s docblock. */
    config: z.record(z.string(), z.unknown()),
    conditions: z.array(campaignConditionSchema),
    starts_at: z.string().nullable(),
    /** Exclusive — see `campaignSchema.ends_at`. */
    ends_at: z.string().nullable(),
  })
  // The server does not currently enforce this — see update_item(). String
  // comparison is correct here: both are fixed-width UTC `YYYY-MM-DD HH:mm:ss`.
  .refine((data) => !data.starts_at || !data.ends_at || data.starts_at < data.ends_at, {
    message: 'The start date must be before the end date.',
    path: ['ends_at'],
  });

export type CampaignUpdateInput = z.infer<typeof campaignUpdateInputSchema>;

/* ─── Write: per-trigger config field maps ──────────────────────────── */

type CampaignConfigFieldType = 'bool' | 'string' | 'int' | 'number' | 'enum' | 'int_list';

interface CampaignConfigFieldSpec {
  type: CampaignConfigFieldType;
  /** Lower bound — `int` and `number` only. */
  min?: number;
  /** Fixed value set — `enum` only. */
  values?: readonly string[];
}

/**
 * Per-trigger config field map, mirroring `Campaign::config_schema()` for the
 * three triggers Lite itself defines. UI metadata for the form layer to
 * drive its fields off — not validation; `config` stays `z.record` above so
 * this map can never drift into a second whitelist.
 *
 * Pro triggers (`birthday`, `first_order`, `order_count`, `spend_milestone`)
 * have no Lite `config_schema()` entry and therefore no entry here either —
 * phase-04 renders those with no field map.
 */
export const CAMPAIGN_CONFIG_FIELDS: Record<
  'purchase' | 'signup' | 'review',
  Record<string, CampaignConfigFieldSpec>
> = {
  purchase: {
    points_per_unit: { type: 'number', min: 0 },
    // 'rate' | 'multiplier' — missing means 'rate', see resolvePurchaseMode().
    mode: { type: 'enum', values: ['rate', 'multiplier'] },
    multiplier: { type: 'number', min: 0 },
    base: { type: 'enum', values: ['after_discounts', 'before_discounts'] },
    // Stored but never read by BaseCalculator — a dead key kept so a
    // downgrade/upgrade round-trip does not lose it. Still render it.
    include_shipping: { type: 'bool' },
    include_tax: { type: 'bool' },
    rounding: { type: 'enum', values: ['down', 'nearest', 'up'] },
    min_subtotal: { type: 'number', min: 0 },
    exclude_coupon_orders: { type: 'bool' },
    excluded_product_ids: { type: 'int_list' },
    excluded_category_ids: { type: 'int_list' },
    max_points_per_order: { type: 'int', min: 0 },
    daily_cap: { type: 'int', min: 0 },
  },
  signup: {
    points: { type: 'int', min: 0 },
  },
  review: {
    points: { type: 'int', min: 0 },
    require_verified: { type: 'bool' },
    one_per_product: { type: 'bool' },
    daily_cap: { type: 'int', min: 0 },
    // In config_schema() but absent from default_config() — default to 0
    // client-side rather than rendering undefined.
    media_bonus_points: { type: 'int', min: 0 },
  },
};

/** `mode` is absent from `default_config('purchase')` — missing means `'rate'`. */
export const DEFAULT_PURCHASE_MODE = 'rate' as const;

/**
 * Client mirror of `PurchaseHandler::is_multiplier()`'s
 * `'multiplier' === ($config['mode'] ?? 'rate')`. One place, used by the
 * editor and by `CampaignTable`'s `givesLabel()` — never re-derived inline.
 */
export function resolvePurchaseMode(config: Record<string, unknown>): 'rate' | 'multiplier' {
  return config.mode === 'multiplier' ? 'multiplier' : DEFAULT_PURCHASE_MODE;
}
