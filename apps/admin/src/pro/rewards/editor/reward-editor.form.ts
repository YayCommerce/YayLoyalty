import { zodResolver } from '@hookform/resolvers/zod';
import type { Resolver } from 'react-hook-form';
import { z } from 'zod';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { pointsPhrase } from '@/lib/helpers/points.helper';
import { numberConfig } from '@/lib/helpers/reward.helper';
import { isCampaignScheduleInOrder } from '@/lib/schema/campaign.schema';
import {
  CatalogRewardType,
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
import type { EditorSaveIntent } from '@/hooks/useEditorSave';
import { defaultRewardName } from '../reward-labels';

/**
 * The form's fields. No `conditions`: the editor shows none, and a save sends
 * the stored list back untouched.
 */
export interface RewardFormValues {
  name: string;
  /** Never archived: archiving is its own flow; Save draft overrides it (resolveSaveStatus). */
  status: EditableRewardStatus;
  /** Null only for flexible_discount, whose customer picks the amount. */
  cost_points: number | null;
  config: Record<string, unknown>;
  starts_at: string | null;
  ends_at: string | null;
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
        coupon_expiry_days: 60,
        exclude_sale_items: false,
      };
    case 'percent_coupon':
      return {
        percent: 15,
        max_discount: 50,
        currency,
        min_order: null,
        coupon_expiry_days: 30,
        exclude_sale_items: false,
      };
    case 'free_shipping':
      return { min_order: 25, currency, coupon_expiry_days: 30 };
    case 'free_product':
      return { product_id: null, variation_id: null, qty: 1, coupon_expiry_days: 60 };
  }
}

const SEED_COST_POINTS: Record<CatalogRewardType, number> = {
  fixed_coupon: 500,
  percent_coupon: 1500,
  free_shipping: 800,
  free_product: 3000,
};

/** A worked example of a catalog type's starting point, read from the seed it opens with. */
export function templateExample(type: CatalogRewardType): string {
  const config = seedConfig(type, storeCurrency());
  const cost = pointsPhrase(SEED_COST_POINTS[type]);

  switch (type) {
    case 'fixed_coupon':
      return sprintf(
        /* translators: 1: discount amount, 2: minimum order amount, 3: points cost, e.g. "500 points". */
        __('Example: %1$s off orders over %2$s, for %3$s', 'yayloyalty'),
        parseWPCurrency(numberConfig(config, 'amount') ?? 0),
        parseWPCurrency(numberConfig(config, 'min_order') ?? 0),
        cost,
      );
    case 'percent_coupon':
      return sprintf(
        /* translators: 1: discount percent, 2: points cost, e.g. "1,500 points". */
        __('Example: %1$s%% off, for %2$s', 'yayloyalty'),
        String(numberConfig(config, 'percent') ?? 0),
        cost,
      );
    case 'free_shipping':
      /* translators: %s: points cost, e.g. "800 points". */
      return sprintf(__('Example: free shipping on one order, for %s', 'yayloyalty'), cost);
    case 'free_product':
      /* translators: %s: points cost, e.g. "3,000 points". */
      return sprintf(__('Example: a free product, for %s', 'yayloyalty'), cost);
  }
}

/** A new reward's starting values; nothing is persisted until Save or Save draft. */
export function rewardCreateSeed(type: CatalogRewardType, currency: string): RewardFormValues {
  const config = seedConfig(type, currency);

  return {
    name: defaultRewardName(type, config),
    status: 'active',
    cost_points: SEED_COST_POINTS[type],
    config,
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
    // An archived reward is restored by Save, as before the status field existed.
    status: reward.status === 'archived' ? 'active' : reward.status,
    cost_points: reward.cost_points,
    // Cloned: form state must never share references with the query cache.
    config: structuredClone({ ...base, ...reward.config }),
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
export function formValuesToUpdateInput(
  values: RewardFormValues,
  reward: Reward,
  intent: RewardSaveIntent,
): RewardUpdateInput {
  return formValuesToBody(values, normalizeRewardConditions(reward.conditions), intent);
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
  return { type, ...formValuesToBody(values, sourceConditions, intent) };
}

function formValuesToBody(
  values: RewardFormValues,
  storedConditions: RewardCondition[],
  intent: RewardSaveIntent,
): RewardUpdateInput {
  return {
    name: values.name,
    status: resolveSaveStatus(values, intent),
    cost_points: values.cost_points,
    config: values.config,
    conditions: storedConditions,
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
    .pick({ name: true, starts_at: true, ends_at: true })
    .extend({
      cost_points:
        type === 'flexible_discount'
          ? z.null()
          : z.number({ invalid_type_error: costMessage }).int().min(1, costMessage),
      config: REWARD_CONFIG_SCHEMAS[type],
      status: z.enum(EDITABLE_REWARD_STATUSES),
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
