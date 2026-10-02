import { zodResolver } from '@hookform/resolvers/zod';
import type { Resolver } from 'react-hook-form';
import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';

import { isCampaignScheduleInOrder } from '@/lib/schema/campaign.schema';
import {
  CatalogRewardType,
  claimsPerCustomer,
  isCatalogRewardType,
  normalizeRewardConditions,
  Reward,
  REWARD_CONFIG_SCHEMAS,
  RewardCondition,
  RewardCreateInput,
  rewardFieldsSchema,
  RewardStatus,
  RewardType,
  RewardUpdateInput,
} from '@/lib/schema/reward.schema';
import { templateTitle } from '../reward-labels';

/**
 * The form's fields. No `status`: the button pressed decides it
 * (resolveSaveStatus). No `conditions`: the editor manages only the
 * per-customer claim cap, merged back into the stored list on save so any
 * other stored rule survives untouched.
 */
export interface RewardFormValues {
  name: string;
  /** Null only for flexible_discount, whose customer picks the amount. */
  cost_points: number | null;
  config: Record<string, unknown>;
  /** Written as `redemption_count lt N`; null = unlimited. */
  claims_per_customer: number | null;
  starts_at: string | null;
  ends_at: string | null;
}

export type RewardSaveIntent = 'save' | 'draft';

/** Config a new reward of each catalog type starts from, filled into rows missing a key too. */
function seedConfig(type: CatalogRewardType, currency: string): Record<string, unknown> {
  switch (type) {
    case 'fixed_coupon':
      return {
        currency,
        amount: 5,
        min_order: 25,
        coupon_expiry_days: 60,
        exclude_sale_items: false,
        max_total: null,
      };
    case 'percent_coupon':
      return {
        percent: 15,
        max_discount: 50,
        currency,
        min_order: null,
        coupon_expiry_days: 30,
        exclude_sale_items: false,
        max_total: null,
      };
    case 'free_shipping':
      return { min_order: 25, currency, coupon_expiry_days: 30, max_total: null };
    case 'free_product':
      return { product_id: null, variation_id: null, qty: 1, coupon_expiry_days: 60, max_total: null };
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
  return {
    name: templateTitle(type),
    cost_points: SEED_COST_POINTS[type],
    config: seedConfig(type, currency),
    claims_per_customer: null,
    starts_at: null,
    ends_at: null,
  };
}

/** The loaded reward narrowed to the form's fields; `storeCurrency` fills a row stored without one. */
export function rewardToFormValues(reward: Reward, storeCurrency: string): RewardFormValues {
  // A catalog row missing a key (written before typed validation) gets the seed's value, so every input renders.
  const base = isCatalogRewardType(reward.type) ? seedConfig(reward.type, storeCurrency) : {};

  return {
    name: reward.name,
    cost_points: reward.cost_points,
    // Cloned: form state must never share references with the query cache.
    config: structuredClone({ ...base, ...reward.config }),
    claims_per_customer: claimsPerCustomer(normalizeRewardConditions(reward.conditions)),
    starts_at: reward.starts_at,
    ends_at: reward.ends_at,
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

/**
 * Conditions with the claim cap set to `next`. Unchanged input returns the
 * list as stored, so a rule the editor cannot show (e.g. `eq`) is never
 * dropped by a save that did not touch it.
 */
export function withClaimsPerCustomer(conditions: RewardCondition[], next: number | null): RewardCondition[] {
  if (next === claimsPerCustomer(conditions)) {
    return conditions;
  }

  const others = conditions.filter((condition) => condition.type !== 'redemption_count');

  return next === null ? others : [...others, { type: 'redemption_count', operator: 'lt', value: next }];
}

/**
 * Status a save writes: Save draft drafts; Save publishes a new, draft or
 * archived reward and keeps an active or paused one as it is. The built-in
 * always keeps its status — it is paused from its own switch, never drafted.
 */
export function resolveSaveStatus(reward: Reward | null, intent: RewardSaveIntent): RewardStatus {
  if (reward?.is_builtin) {
    return reward.status;
  }

  if (intent === 'draft') {
    return 'draft';
  }

  return reward?.status === 'active' || reward?.status === 'paused' ? reward.status : 'active';
}

/** Which of the side area's buttons a reward offers; Save and Cancel are always shown. */
export function rewardEditorActions(reward: Reward | null) {
  return {
    canSaveDraft: reward === null || (reward.status === 'draft' && !reward.is_builtin),
    showArchive: reward !== null && reward.status !== 'archived',
    isArchiveDisabled: reward?.is_builtin ?? false,
  };
}

/** PUT body: every editable field, so the server sees the form as a whole. */
export function formValuesToUpdateInput(
  values: RewardFormValues,
  reward: Reward,
  intent: RewardSaveIntent,
): RewardUpdateInput {
  return formValuesToBody(values, reward, normalizeRewardConditions(reward.conditions), intent);
}

/**
 * POST body for a new reward of `type`. `sourceConditions` is a duplicated
 * reward's stored list, so rules the form cannot show survive the copy.
 */
export function formValuesToCreateInput(
  values: RewardFormValues,
  type: CatalogRewardType,
  intent: RewardSaveIntent,
  sourceConditions: RewardCondition[] = [],
): RewardCreateInput {
  return { type, ...formValuesToBody(values, null, sourceConditions, intent) };
}

function formValuesToBody(
  values: RewardFormValues,
  reward: Reward | null,
  storedConditions: RewardCondition[],
  intent: RewardSaveIntent,
): RewardUpdateInput {
  return {
    name: values.name,
    status: resolveSaveStatus(reward, intent),
    cost_points: values.cost_points,
    config: values.config,
    conditions: withClaimsPerCustomer(storedConditions, values.claims_per_customer),
    starts_at: values.starts_at,
    ends_at: values.ends_at,
  };
}

/**
 * Form validation for one type: its typed config, its cost rule (mirroring
 * Reward::cost_points_is_valid()) and the end-after-start check (mirroring
 * AbstractRestController::dates_in_order()).
 */
export function rewardFormSchema(type: RewardType) {
  const costMessage = __('A reward needs a cost of at least 1 point.', 'yayloyalty');

  return rewardFieldsSchema
    .pick({ name: true, starts_at: true, ends_at: true })
    .extend({
      cost_points:
        type === 'flexible_discount'
          ? z.null()
          : z.number({ invalid_type_error: costMessage }).int().min(1, costMessage),
      config: REWARD_CONFIG_SCHEMAS[type],
      claims_per_customer: z.number().int().min(1).nullable(),
    })
    .refine((data) => isCampaignScheduleInOrder(data.starts_at, data.ends_at), {
      message: __('The reward must end after it starts.', 'yayloyalty'),
      path: ['ends_at'],
    });
}

export function rewardFormResolver(type: RewardType): Resolver<RewardFormValues> {
  // Form state keeps config as a loose record shared by every type; the per-type schema is what types it.
  return zodResolver(rewardFormSchema(type)) as unknown as Resolver<RewardFormValues>;
}
