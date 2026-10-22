import { createContext, useContext } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import type { Resolver, ResolverResult } from 'react-hook-form';

import { dateToStored } from '@/lib/helpers/format.helper';
import {
  Campaign,
  CampaignCondition,
  CampaignCreateInput,
  CampaignTrigger,
  CampaignUpdateInput,
  campaignUpdateInputSchema,
  CreatableCampaignTrigger,
} from '@/lib/schema/campaign.schema';
import { validateConditionValue } from './CampaignEditorConditions';

/**
 * The form's fields: CampaignUpdateInput, in both modes. `trigger` lives outside the form, in
 * CampaignEditorContext, because it is fixed once the chooser picks it.
 * starts_at/ends_at stay raw UTC strings so the schema's string comparison holds; the schedule
 * fields convert at their inputs only.
 */
export type CampaignFormValues = CampaignUpdateInput;

/** The loaded campaign narrowed to the editable fields, for useForm's `values`. */
export function campaignToFormValues(campaign: Campaign): CampaignFormValues {
  return {
    name: campaign.name,
    status: campaign.status,
    // Cloned: form state must never share references with the query cache, or an in-place
    // edit corrupts what other readers have cached.
    config: structuredClone(campaign.config),
    conditions: structuredClone(normalizeConditions(campaign.conditions)),
    starts_at: campaign.starts_at,
    ends_at: campaign.ends_at,
  };
}

/** Identity today; the one seam if the form's shape ever diverges from the write body. */
export function formValuesToUpdateInput(values: CampaignFormValues): CampaignUpdateInput {
  return values;
}

/** POST campaigns' body: form values plus the trigger picked in the chooser, carried outside form state. The server puts it on top of its group; priority is not writable. */
export function formValuesToCreateInput(
  values: CampaignFormValues,
  trigger: CreatableCampaignTrigger,
): CampaignCreateInput {
  return { ...values, trigger };
}

/**
 * A template's fields shaped for the create form.
 * `trigger` is separate because it is not a form field — see CampaignFormValues.
 */
export interface CampaignCreateSeed {
  trigger: CreatableCampaignTrigger;
  values: CampaignFormValues;
}

/**
 * Placeholder for the editor context's `campaign` in create mode, since
 * nothing is persisted yet. `id` is Number.MAX_SAFE_INTEGER rather than 0
 * so no comparison against a real campaign's id can ever match it.
 */
export function synthesizeDraftCampaign(): Campaign {
  const now = dateToStored();

  return {
    id: Number.MAX_SAFE_INTEGER,
    name: '',
    slug: '',
    trigger: 'purchase',
    status: 'draft',
    priority: 0,
    config: {},
    conditions: [],
    starts_at: null,
    ends_at: null,
    is_builtin: false,
    created_by: null,
    created_at: now,
    updated_at: now,
    points_issued: 0,
    is_deletable: false,
  };
}

/** The wire type is object | array; narrow to an array once here. */
function normalizeConditions(conditions: Campaign['conditions']): CampaignCondition[] {
  return Array.isArray(conditions) ? (conditions as CampaignCondition[]) : [];
}

/**
 * Adds per-condition value errors to the zodResolver result. `value` is
 * z.unknown(), so zod never checks it; running validateConditionValue in the
 * resolver keeps it on RHF's normal lifecycle for every save path, unlike
 * setError (wiped by the next resolver run) or a native submit listener
 * (never fired by a dialog button). Builds the array through a plain Record
 * because RHF's mapped error type for an array of unknown does not iterate.
 */
export function mergeConditionErrors(
  result: ResolverResult<CampaignFormValues>,
  values: CampaignFormValues,
): ResolverResult<CampaignFormValues> {
  const conditions = Array.isArray(values.conditions) ? values.conditions : [];
  const additions: Record<number, { type: 'manual'; message: string }> = {};

  conditions.forEach((condition, index) => {
    const message = validateConditionValue(condition);

    if (message) {
      additions[index] = { type: 'manual', message };
    }
  });

  if (Object.keys(additions).length === 0) {
    return result;
  }

  const currentErrors = result.errors as Record<string, unknown> & {
    conditions?: Array<{ value?: { type: string; message: string } } | undefined>;
  };
  const existing = Array.isArray(currentErrors.conditions) ? [...currentErrors.conditions] : [];

  Object.entries(additions).forEach(([indexKey, error]) => {
    const index = Number(indexKey);
    existing[index] = { ...existing[index], value: error };
  });

  return {
    values: {},
    errors: { ...currentErrors, conditions: existing },
  } as ResolverResult<CampaignFormValues>;
}

/** One resolver for both editor modes: the fields it validates are the same in each. */
export const campaignFormResolver: Resolver<CampaignFormValues> = async (values, context, options) => {
  const result = await zodResolver(campaignUpdateInputSchema)(values, context, options);
  return mergeConditionErrors(result, values);
};

// Editor context: the loaded campaign, outside form state

/**
 * The loaded Campaign, for the fields outside form state such as id and slug. Declared here, not
 * in CampaignEditor.tsx, to avoid a circular import with the sections. Provided only once the
 * fetch (edit) or template pick (create) resolves, so every consumer gets a full value.
 */
interface CampaignEditorContextValue {
  mode: 'create' | 'edit';
  /** Edit: the loaded row. Create: synthesizeDraftCampaign()'s placeholder. Read `trigger` below, never this one's. */
  campaign: Campaign;
  /** The trigger driving Reward. Edit: campaign.trigger. Create: the chooser's pick — read this, never campaign.trigger. */
  trigger: CampaignTrigger;
}

export const CampaignEditorContext = createContext<CampaignEditorContextValue | null>(null);

export function useCampaignEditorContext(): CampaignEditorContextValue {
  const context = useContext(CampaignEditorContext);

  if (!context) {
    throw new Error('useCampaignEditorContext() must be called within <CampaignEditor>.');
  }

  return context;
}
