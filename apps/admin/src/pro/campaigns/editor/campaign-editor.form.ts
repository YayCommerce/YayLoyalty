import { createContext, useContext } from 'react';
import type { ResolverResult } from 'react-hook-form';

import type { Campaign, CampaignCondition, CampaignUpdateInput } from '@/lib/schema/campaign.schema';
import { validateConditionValue } from './CampaignEditorConditions';

/**
 * The form's field shape, identical to CampaignUpdateInput so the resolver
 * validates exactly what is edited. No `trigger`: it is immutable on edit
 * and renders from the loaded Campaign. starts_at/ends_at stay raw UTC
 * strings so the schema's string-comparison refinement holds; the Schedule
 * section converts at its inputs only.
 */
export type CampaignFormValues = CampaignUpdateInput;

/** The loaded campaign narrowed to the editable fields, for useForm's `values`. */
export function campaignToFormValues(campaign: Campaign): CampaignFormValues {
  return {
    name: campaign.name,
    status: campaign.status,
    priority: campaign.priority,
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

/* ─── editor context: the loaded campaign, outside form state ───────── */

/**
 * The loaded Campaign for the fields outside form state: slug and trigger
 * are immutable after create and render from here, never via watch().
 * Declared here rather than in CampaignEditor.tsx to avoid a circular import
 * with the sections. Provided only after the fetch boundary has resolved,
 * so every consumer gets a fully loaded campaign.
 */
interface CampaignEditorContextValue {
  campaign: Campaign;
}

export const CampaignEditorContext = createContext<CampaignEditorContextValue | null>(null);

export function useCampaignEditorContext(): CampaignEditorContextValue {
  const context = useContext(CampaignEditorContext);

  if (!context) {
    throw new Error('useCampaignEditorContext() must be called within <CampaignEditor>.');
  }

  return context;
}
