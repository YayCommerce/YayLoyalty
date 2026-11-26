import { zodResolver } from '@hookform/resolvers/zod';
import type { Resolver } from 'react-hook-form';
import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';

import { isScheduleInOrder } from '@/lib/schema/campaign.schema';
import { ConditionList, emptyConditionList, toConditionList } from '@/lib/schema/condition-match.schema';
import {
  CatalogRewardType,
  isCatalogRewardType,
  Reward,
  REWARD_CONFIG_SCHEMAS,
  RewardCondition,
  RewardCreateInput,
  rewardFieldsSchema,
  RewardStatus,
  RewardType,
  RewardUpdateInput,
} from '@/lib/schema/reward.schema';
import type { EditorSaveIntent } from '@/hooks/useEditorSave';
import { mergeConditionErrors } from '../../conditions/condition-form';
import { defaultRewardName } from '../reward-labels';

/** The reward's tier rule: none, "Minimum tier" (`gte`) or "Only this tier" (`eq`). */
const TIER_RULES = ['any', 'gte', 'eq'] as const;

export type TierRule = (typeof TIER_RULES)[number];

/**
 * The form's fields. `conditions` is edited in "Who can redeem" for catalog rewards, less its
 * tier entry, which the form holds as `tier_rule` and `tier_id`; the built-in shows none, and a
 * save sends its stored conditions back untouched.
 */
export interface RewardFormValues {
  name: string;
  /** Never archived: archiving is its own flow; Save draft overrides it (resolveSaveStatus). */
  status: EditableRewardStatus;
  /** Null only for flexible_discount, whose customer picks the amount. */
  cost_points: number | null;
  config: Record<string, unknown>;
  conditions: ConditionList<RewardCondition>;
  starts_at: string | null;
  ends_at: string | null;
  /** Only coupon rewards set a rule other than `any`. */
  tier_rule: TierRule;
  /** The required tier's id; null for `any`, or until the merchant picks one. */
  tier_id: number | null;
}

export type RewardSaveIntent = EditorSaveIntent;

/** RewardStatus minus archived, which only the archive dialog writes. */
export const EDITABLE_REWARD_STATUSES = ['active', 'paused', 'draft'] as const;

export type EditableRewardStatus = (typeof EDITABLE_REWARD_STATUSES)[number];

/** Config a new reward of each catalog type starts from, filled into rows missing a key too. */
function seedConfig(type: CatalogRewardType, currency: string): Record<string, unknown> {
  switch (type) {
    case 'fixed_coupon':
      return {
        currency,
        amount: 5,
        min_order: 25,
        exclude_sale_items: false,
      };
    case 'percent_coupon':
      return {
        percent: 15,
        max_discount: 50,
        currency,
        min_order: null,
        exclude_sale_items: false,
      };
    case 'free_shipping':
      return { min_order: null, currency };
    case 'free_product':
      return { product_id: null, variation_id: null, qty: 1 };
  }
}

const SEED_COST_POINTS: Record<CatalogRewardType, number> = {
  fixed_coupon: 500,
  percent_coupon: 1500,
  free_shipping: 800,
  free_product: 3000,
};

/** A new reward's starting values; nothing is persisted until Save or Save draft. */
export function rewardCreateSeed(type: CatalogRewardType, currency: string): RewardFormValues {
  const config = seedConfig(type, currency);

  return {
    name: defaultRewardName(type, config),
    status: 'active',
    cost_points: SEED_COST_POINTS[type],
    config,
    conditions: emptyConditionList(),
    starts_at: null,
    ends_at: null,
    tier_rule: 'any',
    tier_id: null,
  };
}

/** The loaded reward narrowed to the form's fields; `storeCurrency` fills a row stored without one. */
export function rewardToFormValues(reward: Reward, storeCurrency: string): RewardFormValues {
  // A catalog row missing a key (written before typed validation) gets the seed's value, so every input renders.
  const base = isCatalogRewardType(reward.type) ? seedConfig(reward.type, storeCurrency) : {};
  const stored = toConditionList<RewardCondition>(reward.conditions);

  return {
    name: reward.name,
    // An archived reward is restored by Save, as before the status field existed.
    status: reward.status === 'archived' ? 'active' : reward.status,
    cost_points: reward.cost_points,
    // Cloned: form state must never share references with the query cache.
    config: structuredClone({ ...base, ...reward.config }),
    conditions: structuredClone({ ...stored, conditions: stored.conditions.filter((entry) => entry.type !== 'tier') }),
    starts_at: reward.starts_at,
    ends_at: reward.ends_at,
    ...tierRuleOf(stored.conditions),
  };
}

/**
 * Create-form values seeded from an existing reward, for the table's
 * Duplicate action: everything copied, the name marked as a copy.
 */
export function duplicateToFormValues(source: Reward, storeCurrency: string): RewardFormValues {
  return {
    ...rewardToFormValues(source, storeCurrency),
    /* translators: %s: the name of the reward being duplicated. */
    name: sprintf(__('%s (copy)', 'yayloyalty'), source.name),
  };
}

/** Status a save writes: Save draft drafts, Save writes the status field. The built-in is never offered Save draft. */
export function resolveSaveStatus(values: RewardFormValues, intent: RewardSaveIntent): RewardStatus {
  return intent === 'draft' ? 'draft' : values.status;
}

/**
 * Which of the side area's buttons a reward offers; Save and Cancel are always shown. The built-in is never archived.
 * Saving an unchanged reward is a no-op, except an archived one, which Save restores.
 */
export function rewardEditorActions(reward: Reward | null, isDirty: boolean) {
  return {
    canSave: reward === null || isDirty || reward.status === 'archived',
    canSaveDraft: reward === null || (reward.status === 'draft' && !reward.is_builtin),
    showArchive: reward !== null && !reward.is_builtin && reward.status !== 'archived',
  };
}

/** PUT body: every editable field, so the server sees the form as a whole. */
export function formValuesToUpdateInput(values: RewardFormValues, intent: RewardSaveIntent): RewardUpdateInput {
  return formValuesToBody(values, intent);
}

/** POST body for a new reward of `type`; a duplicate carries its source's conditions in the form values. */
export function formValuesToCreateInput(
  values: RewardFormValues,
  type: CatalogRewardType,
  intent: RewardSaveIntent,
): RewardCreateInput {
  return { type, ...formValuesToBody(values, intent) };
}

function formValuesToBody(values: RewardFormValues, intent: RewardSaveIntent): RewardUpdateInput {
  return {
    name: values.name,
    status: resolveSaveStatus(values, intent),
    cost_points: values.cost_points,
    config: values.config,
    conditions: withTierRule(values.conditions, values.tier_rule, values.tier_id),
    starts_at: values.starts_at,
    ends_at: values.ends_at,
  };
}

/**
 * Mirrors Reward::cost_points_is_valid() and AbstractRestController::dates_in_order(), the source of truth.
 * Form validation for one type: its typed config, its cost rule and the end-after-start check.
 */
export function rewardFormSchema(type: RewardType) {
  const costMessage = __('A reward needs a cost of at least 1 point.', 'yayloyalty');

  return rewardFieldsSchema
    .pick({ name: true, conditions: true, starts_at: true, ends_at: true })
    .extend({
      cost_points:
        type === 'flexible_discount'
          ? z.null()
          : z.number({ invalid_type_error: costMessage }).int().min(1, costMessage),
      config: REWARD_CONFIG_SCHEMAS[type],
      status: z.enum(EDITABLE_REWARD_STATUSES),
      tier_rule: z.enum(TIER_RULES),
      tier_id: z.number().int().positive().nullable(),
    })
    .refine((data) => isScheduleInOrder(data.starts_at, data.ends_at), {
      message: __('The reward must end after it starts.', 'yayloyalty'),
      path: ['ends_at'],
    })
    .refine((data) => data.tier_rule === 'any' || data.tier_id !== null, {
      message: __('Pick a tier.', 'yayloyalty'),
      path: ['tier_id'],
    });
}

/**
 * Mirrors RewardTierCondition::tier_entry(), the source of truth: the rule and
 * id of a `tier` entry with `gte` or `eq`, or `any` without a usable one.
 */
function tierRuleOf(conditions: RewardCondition[]): Pick<RewardFormValues, 'tier_rule' | 'tier_id'> {
  const entry = conditions.find((condition) => condition.type === 'tier');
  const operator = entry?.operator;
  const value = entry?.value;
  const id = typeof value === 'number' || typeof value === 'string' ? Number(value) : NaN;

  return (operator === 'gte' || operator === 'eq') && Number.isInteger(id) && id > 0
    ? { tier_rule: operator, tier_id: id }
    : { tier_rule: 'any', tier_id: null };
}

/** The list with its tier entries replaced by one for `rule`, or with none for `any`. */
function withTierRule(
  list: ConditionList<RewardCondition>,
  rule: TierRule,
  tierId: number | null,
): ConditionList<RewardCondition> {
  const rest = list.conditions.filter((condition) => condition.type !== 'tier');

  return {
    ...list,
    conditions: rule === 'any' || tierId === null ? rest : [...rest, { type: 'tier', operator: rule, value: tierId }],
  };
}

export function rewardFormResolver(type: RewardType): Resolver<RewardFormValues> {
  // Form state keeps config as a loose record shared by every type; the per-type schema is what types it.
  const resolver = zodResolver(rewardFormSchema(type)) as unknown as Resolver<RewardFormValues>;

  return async (values, context, options) => mergeConditionErrors(await resolver(values, context, options), values);
}
